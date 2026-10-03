<?php

namespace App\Services\Reports;

use App\Enums\Feature;
use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Jobs\GenerateReportExport;
use App\Models\FarmMembership;
use App\Models\ReportExport;
use App\Services\Audit\AuditLogger;
use App\Services\Dashboard\DashboardClock;
use App\Services\Notifications\NotificationCatalogue;
use App\Services\Notifications\NotificationIntent;
use App\Services\Notifications\NotificationService;
use App\Services\Reports\Writers\CsvWriter;
use App\Services\Reports\Writers\PdfWriter;
use App\Services\Reports\Writers\XlsxWriter;
use App\Services\Subscription\EntitlementService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use App\Support\Idempotency\RequestHash;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Report exports: request (validated, authorised, queued), generate (on the database queue, re-checking access), download (authorised, private).
 * A file is rendered from the SAME report code path as the JSON API, with the exact filters stored on the request, and written to a private
 * disk. The export row keeps lifecycle only - never data.
 */
class ExportService
{
    public const FORMATS = ['csv', 'xlsx', 'pdf'];

    public function __construct(
        private ReportService $reports, private EntitlementService $entitlements, private AuditLogger $audit,
        private NotificationService $notifications,
    ) {}

    /** @return array{export: ReportExport, replayed: bool} */
    public function request(FarmContext $ctx, array $data): array
    {
        return DB::transaction(fn () => $this->createRequest($ctx, $data));
    }

    private function createRequest(FarmContext $ctx, array $data): array
    {
        $ctx->authorize(Permission::ReportExport);
        $report = $this->reports->find($data['report']);
        $definition = $report->definition();
        $this->reports->authorize($ctx, $definition);
        $this->entitlements->assertAllows($ctx->farm, Feature::DataExport);

        $filters = $this->reports->filters($ctx, $definition, (array) ($data['filters'] ?? []))->applied($definition);
        $hash = RequestHash::of(['report' => $definition->code, 'format' => $data['format'], 'filters' => $data['filters'] ?? []]);
        $key = $data['idempotency_key'];

        $existing = ReportExport::where('farm_id', $ctx->farm->id)->where('requested_by', $ctx->membership->user_id)->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            return ['export' => $this->replay($existing, $hash), 'replayed' => true];
        }

        try {
            $export = ReportExport::create([
                'farm_id' => $ctx->farm->id, 'requested_by' => $ctx->membership->user_id, 'report' => $definition->code, 'format' => $data['format'], 'filters' => $filters,
                'status' => ReportExport::QUEUED, 'idempotency_key' => $key, 'request_hash' => $hash,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A current read sees the winning insert even under MySQL REPEATABLE READ.
            $existing = ReportExport::where('farm_id', $ctx->farm->id)->where('requested_by', $ctx->membership->user_id)->where('idempotency_key', $key)->lockForUpdate()->firstOrFail();

            return ['export' => $this->replay($existing, $hash), 'replayed' => true];
        }

        $this->audit->record($ctx->farm->id, $ctx->membership->user_id, 'report.export_requested', 'report_export', $export->id, $definition->code, ['format' => $export->format, 'filters' => $filters]);
        // V1 uses the same database for domain rows and queue jobs: enqueue atomically.
        GenerateReportExport::dispatch($export->id)->beforeCommit();

        return ['export' => $export, 'replayed' => false];
    }

    private function replay(ReportExport $existing, string $hash): ReportExport
    {
        if (! hash_equals($existing->request_hash, $hash)) {
            throw new ApiHttpException(409, 'idempotency_conflict', 'This idempotency key was already used for a different export.');
        }

        return $existing;
    }

    /** The viewer's OWN exports on this farm, newest first. */
    public function list(FarmContext $ctx, array $f): LengthAwarePaginator
    {
        $ctx->authorize(Permission::ReportExport);

        return ReportExport::where('farm_id', $ctx->farm->id)->where('requested_by', $ctx->membership->user_id)
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($f['report'] ?? null, fn ($q, $v) => $q->where('report', $v))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($f['per_page'] ?? 25);
    }

    /** An export is visible only to the user who requested it, on the farm it was requested for; anything else is a plain 404. */
    public function find(FarmContext $ctx, string $id): ReportExport
    {
        $ctx->authorize(Permission::ReportExport);

        return ReportExport::where('farm_id', $ctx->farm->id)->where('requested_by', $ctx->membership->user_id)->findOrFail($id);
    }

    // ------------------------------------------------------------------ background generation

    /** Runs on the queue. Re-checks that the requester still belongs to the farm and still may run and export the report. */
    public function generate(string $exportId): void
    {
        $export = ReportExport::find($exportId);
        if ($export === null || $export->status !== ReportExport::QUEUED) {
            return; // already finished (a repeated job is harmless)
        }
        // Atomic claim: duplicate deliveries cannot render/write the same file concurrently.
        if (! ReportExport::whereKey($exportId)->where('status', ReportExport::QUEUED)
            ->update(['status' => ReportExport::PROCESSING, 'started_at' => now()])) {
            return;
        }
        $export->refresh();
        $tmp = tempnam(sys_get_temp_dir(), 'exp');

        try {
            $membership = FarmMembership::where('farm_id', $export->farm_id)->where('user_id', $export->requested_by)->where('status', MembershipStatus::Active->value)->with(['farm', 'user'])->first();
            if ($membership === null || $membership->user === null || $membership->user->isSuspended() || ! $membership->user->hasVerifiedEmail()) {
                throw new ApiHttpException(403, 'access_revoked', 'The requesting user no longer has access to this farm.');
            }
            $ctx = new FarmContext($membership->farm, $membership);
            $ctx->authorize(Permission::ReportExport);
            $report = $this->reports->find($export->report);
            $definition = $report->definition();
            $this->reports->authorize($ctx, $definition);
            $this->entitlements->assertAllows($ctx->farm, Feature::DataExport);

            $filters = ReportFilters::resolve(new DashboardClock($ctx->farm), (array) $export->filters);
            $run = ['report' => $definition, 'filters' => $filters, 'result' => $report->run($ctx, $filters), 'generated_at' => $filters->clock->now->toISOString()];
            $payload = ReportPayload::of($ctx, $run);

            $writer = match ($export->format) {
                'csv' => new CsvWriter, 'xlsx' => new XlsxWriter, 'pdf' => new PdfWriter,
            };
            $writer->write($payload, $tmp);

            $path = "report-exports/{$export->farm_id}/{$export->id}.".$writer::EXTENSION;
            $disk = Storage::disk(config('reports.disk'));
            $handle = fopen($tmp, 'rb');
            try {
                if (! $disk->put($path, $handle, ['visibility' => 'private'])) {
                    throw new \RuntimeException('Private export storage write failed.');
                }
            } finally {
                fclose($handle);
            }

            $export->forceFill([
                'status' => ReportExport::COMPLETED, 'file_path' => $path, 'file_name' => $this->fileName($export, $writer::EXTENSION), 'file_size' => filesize($tmp),
                'row_count' => count($payload['rows']), 'completed_at' => now(), 'expires_at' => now()->addDays(config('reports.retention_days')),
            ])->save();
            $this->announce($membership, $export, true);
        } catch (ApiHttpException $e) {
            $this->fail($export, $e->errorCode, $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $this->fail($export, 'export_failed', 'The export could not be generated. Please try again.');
        } finally {
            @unlink($tmp);
        }
    }

    private function fail(ReportExport $export, string $code, string $message): void
    {
        $export->forceFill(['status' => ReportExport::FAILED, 'error_code' => $code, 'error_message' => Str::limit($message, 250, ''), 'failed_at' => now()])->save();
        $membership = FarmMembership::where('farm_id', $export->farm_id)->where('user_id', $export->requested_by)->where('status', MembershipStatus::Active->value)->with('user')->first();
        if ($membership !== null) {
            $this->announce($membership, $export, false);
        }
    }

    /** Tells the requester (their own inbox) that the export finished or failed. */
    private function announce(FarmMembership $membership, ReportExport $export, bool $ok): void
    {
        try {
            $title = $this->reports->find($export->report)->definition()->title;
            $this->notifications->notify($membership, new NotificationIntent(
                $ok ? NotificationCatalogue::EXPORT_READY : NotificationCatalogue::EXPORT_FAILED, $ok ? 'info' : 'warning',
                $ok ? 'Your export is ready' : 'Your export failed',
                $ok ? "{$title} ({$export->format}) is ready to download." : "{$title} ({$export->format}) could not be generated. Please request it again.",
                'export:'.$export->id.($ok ? ':ready' : ':failed'),
                ['type' => 'report_export', 'id' => $export->id, 'reference' => $export->report], ['report' => $export->report, 'format' => $export->format],
            ));
        } catch (Throwable $e) {
            report($e); // a notification problem must never turn a finished export into a failed one
        }
    }

    private function fileName(ReportExport $export, string $extension): string
    {
        $filters = (array) $export->filters;
        $range = isset($filters['from'], $filters['to']) ? "{$filters['from']}_{$filters['to']}" : ($filters['as_of'] ?? now()->toDateString());

        return preg_replace('/[^A-Za-z0-9._-]/', '-', "{$export->report}-{$range}").'.'.$extension;
    }

    // ------------------------------------------------------------------ download and retention

    /** @return array{disk: string, path: string, name: string, mime: string} */
    public function download(FarmContext $ctx, string $id): array
    {
        $export = $this->find($ctx, $id);
        // The requester's access is checked again at download time: a role change or lost plan feature ends access to old files too.
        $this->reports->authorize($ctx, $this->reports->find($export->report)->definition());
        $this->entitlements->assertAllows($ctx->farm, Feature::DataExport);

        if ($export->status === ReportExport::EXPIRED) {
            throw new ApiHttpException(410, 'export_expired', 'This export has expired. Request it again.');
        }
        if ($export->status !== ReportExport::COMPLETED) {
            throw new ApiHttpException(409, $export->status === ReportExport::FAILED ? 'export_failed' : 'export_not_ready', $export->status === ReportExport::FAILED ? 'This export failed and has no file.' : 'This export is not ready yet.');
        }
        $disk = Storage::disk(config('reports.disk'));
        if ($export->expires_at->isPast() || ! $disk->exists($export->file_path)) {
            $this->expire($export);
            throw new ApiHttpException(410, 'export_expired', 'This export has expired. Request it again.');
        }

        $export->forceFill(['download_count' => $export->download_count + 1, 'first_downloaded_at' => $export->first_downloaded_at ?? now()])->save();
        $this->audit->record($ctx->farm->id, $ctx->membership->user_id, 'report.export_downloaded', 'report_export', $export->id, $export->report, ['format' => $export->format]);

        $mime = ['csv' => CsvWriter::MIME, 'xlsx' => XlsxWriter::MIME, 'pdf' => PdfWriter::MIME][$export->format];

        return ['disk' => config('reports.disk'), 'path' => $export->file_path, 'name' => $export->file_name, 'mime' => $mime];
    }

    /** Removes the file of every completed export past its retention and marks it expired. Returns how many. */
    public function pruneExpired(): int
    {
        $count = 0;
        ReportExport::where('status', ReportExport::COMPLETED)->where('expires_at', '<', now())->orderBy('id')->each(function (ReportExport $export) use (&$count) {
            if (! $this->expire($export)) {
                throw new \RuntimeException('Private export deletion failed; retention cleanup must be retried.');
            }
            $count++;
        });

        return $count;
    }

    private function expire(ReportExport $export): bool
    {
        if ($export->file_path !== null && ! Storage::disk(config('reports.disk'))->delete($export->file_path)) {
            // Keep the path for the next prune; the expired timestamp still denies downloads.
            return false;
        }
        $export->forceFill(['status' => ReportExport::EXPIRED, 'file_path' => null])->save();

        return true;
    }
}
