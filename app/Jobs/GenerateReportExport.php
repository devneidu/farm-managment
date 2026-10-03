<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Services\Reports\ExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Renders one queued report export on the database queue. The job carries only the export id: the farm, the requesting user and the exact filters
 * are read from the export row, and access is re-checked when it runs. Failures are recorded on the export (status failed) instead of retried,
 * because a report that fails deterministically will fail again; the user simply requests a new export.
 */
class GenerateReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $exportId) {}

    public function handle(ExportService $exports): void
    {
        $exports->generate($this->exportId);
    }

    public function failed(?\Throwable $exception): void
    {
        ReportExport::whereKey($this->exportId)->whereIn('status', [ReportExport::QUEUED, ReportExport::PROCESSING])
            ->update(['status' => ReportExport::FAILED, 'error_code' => 'export_failed',
                'error_message' => 'The export could not be generated. Please try again.', 'failed_at' => now()]);
    }
}
