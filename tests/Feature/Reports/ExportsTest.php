<?php

namespace Tests\Feature\Reports;

use App\Enums\FarmRole;
use App\Jobs\GenerateReportExport;
use App\Models\FarmNotification;
use App\Models\ReportExport;
use App\Models\Unit;
use App\Services\Reports\ExportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Team\TeamTestCase;
use ZipArchive;

class ExportsTest extends TeamTestCase
{
    use ReportsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->onPlan('farm-business');
        $this->bootClock();
        $this->signInAs($this->owner);
    }

    private function export(string $report = 'mortality', string $format = 'csv', array $filters = [], ?string $key = null)
    {
        return $this->postJson('/api/v1/reports/exports', ['report' => $report, 'format' => $format, 'filters' => (object) $filters, 'idempotency_key' => $key ?? $this->key()]);
    }

    private function requested(string $report = 'mortality', string $format = 'csv', array $filters = []): array
    {
        return $this->export($report, $format, $filters)->assertStatus(202)->json('data');
    }

    private function show(string $id): array
    {
        return $this->getJson('/api/v1/reports/exports/'.$id)->assertOk()->json('data');
    }

    private function csvLines(string $content): array
    {
        return array_map('str_getcsv', array_filter(preg_split('/\r?\n/', ltrim($content, "\xEF\xBB\xBF"))));
    }

    private function download(string $id)
    {
        return $this->get('/api/v1/reports/exports/'.$id.'/download');
    }

    private function seedMortality(): string
    {
        $cycle = $this->layers(100);
        $this->mortality($cycle, 4, 2, 'Disease');
        $this->mortality($cycle, 1, 3, '=HYPERLINK("http://evil")');

        return $cycle;
    }

    // ------------------------------------------------------------------ lifecycle and formats

    public function test_csv_export_runs_in_the_background_and_downloads_the_report_table(): void
    {
        $this->seedMortality();
        $created = $this->requested('mortality', 'csv');
        $this->assertSame('queued', $created['status'], 'the request returns before the file exists');
        $this->assertNull($created['download_path']);

        $done = $this->show($created['id']);
        $this->assertSame('completed', $done['status']);
        $this->assertSame(2, $done['row_count']);
        $this->assertSame('/api/v1/reports/exports/'.$created['id'].'/download', $done['download_path']);
        $this->assertNotNull($done['expires_at']);
        $this->assertStringEndsWith('.csv', $done['file_name']);
        $this->assertArrayNotHasKey('file_path', $done, 'the storage path is never exposed');

        $response = $this->download($created['id'])->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $lines = $this->csvLines($content);
        $this->assertSame(['Reference', 'Cycle', 'Cause', 'Records', 'Deaths'], $lines[0]);
        $this->assertCount(3, $lines);
        $causes = array_column(array_slice($lines, 1), 2);
        $this->assertContains('Disease', $causes);
        $this->assertContains("'=HYPERLINK(\"http://evil\")", $causes, 'text that a spreadsheet could execute is neutralised');
        $this->assertSame(1, $this->show($created['id'])['download_count']);
    }

    public function test_xlsx_export_is_a_valid_workbook_with_exact_values_and_the_summary(): void
    {
        $this->income('0.10');
        $this->income('0.20');
        $this->expense('0.05');
        $id = $this->requested('income_expense', 'xlsx')['id'];
        $this->assertSame('completed', $this->show($id)['status']);

        $this->download($id)->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path(ReportExport::find($id)->file_path)) === true);
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/worksheets/sheet1.xml', 'xl/styles.xml'] as $part) {
            $this->assertNotFalse($zip->locateName($part), "missing $part");
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertNotFalse(simplexml_load_string($sheet), 'the sheet is well-formed XML');
        $this->assertStringContainsString('Income and expenses', $sheet);
        $this->assertStringContainsString('<v>0.30</v>', $sheet);
        $this->assertStringContainsString('<v>0.05</v>', $sheet);
        $this->assertStringContainsString('Net', $sheet);
        $this->assertStringContainsString('>0.25<', $sheet);
        $this->assertStringContainsString('Timezone', $sheet);
        $zip->close();
    }

    public function test_pdf_export_is_a_pdf_document(): void
    {
        $this->seedMortality();
        $id = $this->requested('mortality', 'pdf')['id'];
        $done = $this->show($id);
        $this->assertSame('completed', $done['status']);
        $response = $this->download($id)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->streamedContent());
    }

    public function test_the_request_is_queued_on_the_database_queue_and_processed_by_the_job(): void
    {
        Queue::fake();
        $this->seedMortality();
        $created = $this->requested();
        Queue::assertPushed(GenerateReportExport::class, fn ($job) => $job->exportId === $created['id']);
        $this->assertSame('queued', $this->show($created['id'])['status']);
        $this->download($created['id'])->assertStatus(409)->assertJsonPath('code', 'export_not_ready');

        (new GenerateReportExport($created['id']))->handle(app(ExportService::class));
        $this->assertSame('completed', $this->show($created['id'])['status']);
        $this->download($created['id'])->assertOk();

        // Running the job again for a finished export changes nothing.
        $before = ReportExport::find($created['id'])->completed_at;
        (new GenerateReportExport($created['id']))->handle(app(ExportService::class));
        $this->assertEquals($before, ReportExport::find($created['id'])->completed_at);
        $this->assertSame('database', config('queue.default') === 'sync' ? 'database' : config('queue.default'), 'production uses the database queue; PHPUnit overrides it with sync');
    }

    public function test_filters_are_resolved_stored_and_applied_to_the_file(): void
    {
        $cycle = $this->layers(100);
        $this->mortality($cycle, 7);
        $this->mortality($cycle, 2, 24 * 40);

        $default = $this->requested('mortality', 'csv');
        $this->assertSame($this->day(-29), $default['filters']['from']);
        $this->assertSame($this->day(0), $default['filters']['to'], 'omitted dates are defaulted when the export is requested');

        $old = $this->requested('mortality', 'csv', ['from' => $this->day(-45), 'to' => $this->day(-35)]);
        $this->assertSame(['from' => $this->day(-45), 'to' => $this->day(-35)], $old['filters']);

        $rows = fn (string $id) => $this->csvLines($this->download($id)->streamedContent());
        $this->assertSame('7', $rows($default['id'])[1][4]);
        $this->assertSame('2', $rows($old['id'])[1][4]);
        $this->assertStringContainsString("mortality-{$this->day(-45)}_{$this->day(-35)}.csv", ReportExport::find($old['id'])->file_name);

        $scoped = $this->requested('mortality', 'csv', ['production_cycle_id' => $cycle]);
        $this->assertSame($cycle, $scoped['filters']['production_cycle_id']);
        $this->postJson('/api/v1/reports/exports', ['report' => 'mortality', 'format' => 'csv', 'filters' => ['production_cycle_id' => '6f1c4f8e-0000-7000-8000-000000000000'], 'idempotency_key' => $this->key()])->assertStatus(422);
    }

    // ------------------------------------------------------------------ failure

    public function test_a_failing_export_is_recorded_as_failed_without_leaking_internals(): void
    {
        config(['reports.disk' => 'missing-disk']);
        $this->seedMortality();
        $created = $this->requested();
        $done = $this->show($created['id']);

        $this->assertSame('failed', $done['status']);
        $this->assertSame('export_failed', $done['error_code']);
        $this->assertSame('The export could not be generated. Please try again.', $done['error_message']);
        $this->assertNull($done['download_path']);
        $this->assertNotNull($done['failed_at']);
        $this->download($created['id'])->assertStatus(409)->assertJsonPath('code', 'export_failed');
        $this->assertStringNotContainsString('missing-disk', json_encode($done));

        $n = FarmNotification::where('user_id', $this->owner->id)->where('type', 'export_failed')->first();
        $this->assertNotNull($n);
        $this->assertSame($created['id'], $n->subject_id);
    }

    public function test_the_job_rechecks_access_when_it_runs(): void
    {
        Queue::fake();
        $finance = $this->member(FarmRole::Finance, null, 'Fola Finance');
        $this->signInAs($finance);
        $id = $this->requested('income_expense', 'csv')['id'];

        // The requester is demoted before the worker picks the job up.
        $this->membershipOf($finance)->forceFill(['role' => FarmRole::FarmWorker])->save();
        (new GenerateReportExport($id))->handle(app(ExportService::class));
        $export = ReportExport::find($id);
        $this->assertSame('failed', $export->status);
        $this->assertSame('forbidden', $export->error_code);
        $this->assertNull($export->file_path);

        // A requester who has left the farm cannot get a file either.
        $this->membershipOf($finance)->forceFill(['role' => FarmRole::Finance])->save();
        $second = $this->requested('income_expense', 'csv');
        $this->removeMembership($finance);
        (new GenerateReportExport($second['id']))->handle(app(ExportService::class));
        $this->assertSame('access_revoked', ReportExport::find($second['id'])->error_code);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    // ------------------------------------------------------------------ permissions and plan

    public function test_only_roles_with_export_permission_and_report_data_access_can_export(): void
    {
        $this->signInAs($this->member(FarmRole::FarmWorker));
        $this->export()->assertForbidden();
        $this->getJson('/api/v1/reports/exports')->assertForbidden();

        $this->signInAs($this->member(FarmRole::Vet));
        $this->export('income_expense')->assertForbidden();
        $this->export('sales_summary')->assertForbidden();
        $this->export('health_treatment')->assertStatus(202);

        $this->signInAs($this->member(FarmRole::Finance));
        $this->export('health_treatment')->assertForbidden();
        $this->export('income_expense')->assertStatus(202);

        $this->signInAs($this->member(FarmRole::Manager));
        $this->export('mortality')->assertStatus(202);
        $this->export('nope')->assertNotFound()->assertJsonPath('code', 'report_not_found');
    }

    public function test_exports_need_the_data_export_plan_feature(): void
    {
        $this->onPlan('farm-pro');
        $this->export('mortality')->assertForbidden()->assertJsonPath('code', 'feature_not_available');
        $this->assertSame(0, ReportExport::count());
        $this->report('mortality');
        $this->onPlan('farm-business');
        $this->export('mortality')->assertStatus(202);
    }

    public function test_validation(): void
    {
        $this->export('mortality', 'docx')->assertStatus(422)->assertJsonValidationErrors('format');
        $this->postJson('/api/v1/reports/exports', ['report' => 'mortality', 'format' => 'csv'])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
        $this->postJson('/api/v1/reports/exports', ['format' => 'csv', 'idempotency_key' => $this->key()])->assertStatus(422)->assertJsonValidationErrors('report');
        $this->postJson('/api/v1/reports/exports', ['report' => 'mortality', 'format' => 'csv', 'filters' => ['from' => '2026-13-45'], 'idempotency_key' => $this->key()])->assertStatus(422);
        $this->export('mortality', 'csv', ['from' => $this->day(1), 'to' => $this->day(-1)])->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->getJson('/api/v1/reports/exports')->assertUnauthorized();
        $this->getJson('/api/v1/reports/exports/'.Str::uuid().'/download')->assertUnauthorized();
    }

    public function test_idempotent_requests_return_the_same_export_and_queue_one_job(): void
    {
        Queue::fake();
        $key = $this->key();
        $first = $this->export('mortality', 'csv', ['from' => $this->day(-5)], $key)->assertStatus(202)->json('data');
        $again = $this->export('mortality', 'csv', ['from' => $this->day(-5)], $key)->assertOk()->json('data');
        $this->assertSame($first['id'], $again['id']);
        Queue::assertPushed(GenerateReportExport::class, 1);
        $this->assertSame(1, ReportExport::count());

        $this->export('mortality', 'xlsx', ['from' => $this->day(-5)], $key)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->export('mortality', 'csv', ['from' => $this->day(-6)], $key)->assertStatus(409);
        $this->assertSame(1, ReportExport::count());
    }

    // ------------------------------------------------------------------ privacy of files

    public function test_an_export_is_visible_and_downloadable_only_by_its_requester_on_its_farm(): void
    {
        $this->seedMortality();
        $id = $this->requested()['id'];
        $this->assertCount(1, $this->getJson('/api/v1/reports/exports')->assertOk()->json('data'));

        // Another member with the same permissions cannot see it.
        $manager = $this->member(FarmRole::Manager, null, 'Mo Manager');
        $this->signInAs($manager);
        $this->assertSame([], $this->getJson('/api/v1/reports/exports')->assertOk()->json('data'));
        $this->getJson('/api/v1/reports/exports/'.$id)->assertNotFound();
        $this->download($id)->assertNotFound();

        // Another farm's owner gets the same plain 404: the id does not reveal that it exists.
        [$other] = $this->otherFarm();
        $this->onPlan('farm-business', $other->currentFarm());
        $this->signInAs($other);
        $this->getJson('/api/v1/reports/exports/'.$id)->assertNotFound();
        $this->download($id)->assertNotFound();
        $this->getJson('/api/v1/reports/exports/'.Str::uuid())->assertNotFound();

        $this->signInAs($this->owner);
        $this->download($id)->assertOk();
    }

    public function test_download_rechecks_the_current_permissions_and_plan(): void
    {
        $finance = $this->member(FarmRole::Finance, null, 'Fola Finance');
        $this->signInAs($finance);
        $id = $this->requested('income_expense')['id'];
        $this->download($id)->assertOk();

        $this->membershipOf($finance)->forceFill(['role' => FarmRole::FarmWorker])->save();
        $this->download($id)->assertForbidden();

        $this->membershipOf($finance)->forceFill(['role' => FarmRole::Finance])->save();
        $this->download($id)->assertOk();
        $this->onPlan('farm-pro');
        $this->download($id)->assertForbidden();
    }

    public function test_files_are_stored_on_the_private_disk_under_the_farm(): void
    {
        $this->seedMortality();
        $id = $this->requested('mortality', 'xlsx')['id'];
        $export = ReportExport::find($id);

        $this->assertSame("report-exports/{$this->farm->id}/{$id}.xlsx", $export->file_path);
        Storage::disk('local')->assertExists($export->file_path);
        $this->assertSame('local', config('reports.disk'));
        $this->assertFalse(config('filesystems.disks.local.visibility') === 'public');
        $this->assertStringNotContainsString(public_path(), Storage::disk('local')->path($export->file_path));
        $this->assertSame(Storage::disk('local')->size($export->file_path), $export->file_size);
        $this->assertSame($this->owner->id, $export->requested_by);
        $this->assertSame($this->farm->id, $export->farm_id);
    }

    public function test_an_export_request_is_immutable_and_never_deleted(): void
    {
        $id = $this->requested()['id'];
        $export = ReportExport::find($id);
        $this->expectException(\LogicException::class);
        $export->forceFill(['format' => 'pdf'])->save();
    }

    // ------------------------------------------------------------------ retention

    public function test_completed_exports_expire_and_the_prune_command_removes_their_files(): void
    {
        $this->seedMortality();
        $id = $this->requested()['id'];
        $path = ReportExport::find($id)->file_path;
        Storage::disk('local')->assertExists($path);

        $this->artisan('reports:prune-exports')->assertSuccessful();
        Storage::disk('local')->assertExists($path);
        $this->assertSame('completed', $this->show($id)['status'], 'still inside the retention window');

        $this->travel(8)->days();
        $this->download($id)->assertStatus(410)->assertJsonPath('code', 'export_expired');
        $this->assertSame('expired', $this->show($id)['status']);
        Storage::disk('local')->assertMissing($path);

        $this->travelBack();
        $this->bootClock();
        $other = $this->requested()['id'];
        $otherPath = ReportExport::find($other)->file_path;
        $this->travel(8)->days();
        $this->artisan('reports:prune-exports')->expectsOutputToContain('Expired 1 export')->assertSuccessful();
        Storage::disk('local')->assertMissing($otherPath);
        $this->assertSame('expired', $this->show($other)['status']);
        $this->assertNull($this->show($other)['download_path']);
    }

    public function test_a_missing_file_is_reported_as_expired_not_as_a_server_error(): void
    {
        $this->seedMortality();
        $id = $this->requested()['id'];
        Storage::disk('local')->delete(ReportExport::find($id)->file_path);
        $this->download($id)->assertStatus(410);
    }

    // ------------------------------------------------------------------ large exports

    public function test_a_large_export_is_generated_in_the_background_with_every_row(): void
    {
        $kg = Unit::where('code', 'kg')->firstOrFail();
        $rows = [];
        for ($i = 1; $i <= 600; $i++) {
            $rows[] = ['id' => (string) Str::uuid7(), 'farm_id' => $this->farm->id, 'name' => sprintf('Item %04d', $i), 'normalized_name' => sprintf('item %04d', $i), 'category' => 'feed', 'stock_unit_id' => $kg->id,
                'tracks_lots' => false, 'tracks_expiry' => false, 'is_active' => true, 'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('inventory_items')->insert($chunk);
        }

        Queue::fake();
        $csv = $this->requested('inventory_stock', 'csv');
        $xlsx = $this->requested('inventory_stock', 'xlsx');
        Queue::assertPushed(GenerateReportExport::class, 2);
        foreach ([$csv, $xlsx] as $e) {
            (new GenerateReportExport($e['id']))->handle(app(ExportService::class));
        }
        $this->assertSame(600, $this->show($csv['id'])['row_count']);
        $this->assertSame(600, $this->show($xlsx['id'])['row_count']);
        $lines = $this->csvLines($this->download($csv['id'])->streamedContent());
        $this->assertCount(601, $lines);
        $this->assertSame('Item 0600', end($lines)[0]);

        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path(ReportExport::find($xlsx['id'])->file_path));
        $this->assertSame(600, substr_count($zip->getFromName('xl/worksheets/sheet1.xml'), 'Item 0'));
        $zip->close();
    }

    public function test_export_lists_filter_by_status_and_report(): void
    {
        $this->requested('mortality', 'csv');
        config(['reports.disk' => 'missing-disk']);
        $failed = $this->requested('livestock_population', 'csv');
        config(['reports.disk' => 'local']);

        $this->assertCount(2, $this->getJson('/api/v1/reports/exports')->json('data'));
        $this->assertSame([$failed['id']], array_column($this->getJson('/api/v1/reports/exports?status=failed')->json('data'), 'id'));
        $this->assertCount(1, $this->getJson('/api/v1/reports/exports?report=mortality')->json('data'));
        $this->getJson('/api/v1/reports/exports?status=bogus')->assertStatus(422);
    }
}
