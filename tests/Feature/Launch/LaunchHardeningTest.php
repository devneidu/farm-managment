<?php

namespace Tests\Feature\Launch;

use App\Enums\FarmRole;
use App\Jobs\GenerateFarmNotifications;
use App\Jobs\GenerateReportExport;
use App\Models\FarmNotification;
use App\Models\ReportExport;
use App\Notifications\FarmAlertNotification;
use App\Services\Notifications\NotificationGenerator;
use App\Services\Operations\LedgerReconciler;
use App\Services\Reports\ExportService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Reports\ReportsFixtures;
use Tests\Feature\Team\TeamTestCase;

class LaunchHardeningTest extends TeamTestCase
{
    use ReportsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootClock();
        $this->onPlan('farm-business');
        $this->signInAs($this->owner);
        Storage::fake('local');
    }

    private function queuedExport(): ReportExport
    {
        Queue::fake();
        $id = $this->postJson('/api/v1/reports/exports', ['report' => 'mortality', 'format' => 'csv', 'idempotency_key' => $this->key()])
            ->assertStatus(202)->json('data.id');

        return ReportExport::findOrFail($id);
    }

    public function test_exports_recheck_suspension_and_do_not_create_private_files(): void
    {
        $export = $this->queuedExport();
        $this->owner->forceFill(['suspended_at' => now()])->save();
        app(ExportService::class)->generate($export->id);
        $this->assertSame('failed', $export->fresh()->status);
        $this->assertSame('access_revoked', $export->fresh()->error_code);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_duplicate_processing_delivery_is_noop_and_worker_failure_is_visible(): void
    {
        $export = $this->queuedExport();
        $export->forceFill(['status' => 'processing', 'started_at' => now()])->save();
        app(ExportService::class)->generate($export->id);
        $this->assertSame('processing', $export->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles());
        (new GenerateReportExport($export->id))->failed(new \RuntimeException('secret connection detail'));
        $this->assertSame('failed', $export->fresh()->status);
        $this->assertStringNotContainsString('secret', $export->fresh()->error_message);
        $this->assertGreaterThan((new GenerateReportExport($export->id))->timeout, config('queue.connections.database.retry_after'));
    }

    public function test_failed_storage_write_cannot_mark_an_export_completed(): void
    {
        $export = $this->queuedExport();
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with(config('reports.disk'))->andReturn($disk);
        app(ExportService::class)->generate($export->id);
        $this->assertSame('failed', $export->fresh()->status);
        $this->assertNull($export->fresh()->file_path);
    }

    public function test_queue_insertion_failure_rolls_back_export_and_audit_request(): void
    {
        config(['app.debug' => false]);
        $queue = \Mockery::mock(\Illuminate\Contracts\Queue\Queue::class);
        $queue->shouldReceive('push')->once()->andThrow(new \RuntimeException('Queue unavailable'));
        Queue::shouldReceive('connection')->andReturn($queue);
        $before = DB::table('audit_logs')->count();
        $this->postJson('/api/v1/reports/exports', ['report' => 'mortality', 'format' => 'csv', 'idempotency_key' => $this->key()])
            ->assertStatus(500)->assertJsonPath('message', 'Server error.');
        $this->assertSame(0, ReportExport::count());
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_failed_private_file_deletion_keeps_the_path_for_a_cleanup_retry(): void
    {
        $export = $this->queuedExport();
        app(ExportService::class)->generate($export->id);
        $path = $export->fresh()->file_path;
        $this->travel(8)->days();
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('delete')->with($path)->twice()->andReturn(false, true);
        Storage::shouldReceive('disk')->with(config('reports.disk'))->andReturn($disk);
        try {
            app(ExportService::class)->pruneExpired();
            $this->fail('Cleanup must report its failure.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('deletion failed', $e->getMessage());
        }
        $this->assertSame($path, $export->fresh()->file_path);
        $this->assertFalse($export->fresh()->isDownloadable());
        $this->assertSame(1, app(ExportService::class)->pruneExpired());
        $this->assertSame('expired', $export->fresh()->status);
        $this->assertNull($export->fresh()->file_path);
    }

    public function test_queued_mail_rechecks_role_membership_preferences_and_account(): void
    {
        $user = $this->member(FarmRole::Manager);
        $membership = $this->membershipOf($user);
        $membership->forceFill(['notification_preferences' => ['channels' => ['email' => true, 'in_app' => true]]])->save();
        $n = FarmNotification::create(['farm_id' => $this->farm->id, 'user_id' => $user->id, 'type' => 'overdue_invoices',
            'severity' => 'warning', 'title' => 'Overdue', 'message' => 'Private financial alert', 'dedupe_key' => 'launch-mail', 'in_app' => true, 'email_status' => 'queued']);
        $mail = new FarmAlertNotification($n->id);
        $this->assertTrue($mail->shouldSend($user, 'mail'));
        $this->signInAs($user);
        $this->getJson('/api/v1/notifications')->assertJsonCount(1, 'data')->assertJsonPath('meta.unread_count', 1);
        $membership->forceFill(['role' => FarmRole::FarmWorker])->save();
        $this->assertFalse($mail->shouldSend($user, 'mail'));
        $this->getJson('/api/v1/notifications')->assertJsonCount(0, 'data')->assertJsonPath('meta.unread_count', 0);
        $this->postJson('/api/v1/notifications/'.$n->id.'/read')->assertNotFound();
        $membership->forceFill(['role' => FarmRole::Manager])->save();
        $user->forceFill(['suspended_at' => now()])->save();
        $this->assertFalse($mail->shouldSend($user, 'mail'));
        $user->forceFill(['suspended_at' => null])->save();
        $membership->forceFill(['notification_preferences' => ['channels' => ['email' => false]]])->save();
        $this->assertFalse($mail->shouldSend($user, 'mail'));
        $membership->forceFill(['notification_preferences' => ['channels' => ['email' => true]]])->save();
        $this->removeMembership($user);
        $this->assertFalse($mail->shouldSend($user, 'mail'));
        $this->assertSame('skipped', $n->fresh()->email_status);
    }

    public function test_api_success_and_errors_have_security_and_private_cache_headers(): void
    {
        foreach (['/api/v1/me', '/api/v1/missing-launch-route'] as $url) {
            $response = $this->getJson($url)->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Referrer-Policy', 'no-referrer');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertNotEmpty($response->headers->get('X-Request-Id'));
        }
    }

    public function test_stateful_mutations_require_csrf_outside_the_test_bypass(): void
    {
        $this->app->instance('env', 'production');
        $this->withSession(['_token' => 'launch-csrf-token']);
        $payload = ['email' => $this->owner->email, 'password' => 'Password123'];
        $this->postJson('/api/v1/auth/login', $payload)->assertStatus(419)->assertJsonPath('code', 'session_expired');
        $this->withHeader('X-CSRF-TOKEN', 'launch-csrf-token')->postJson('/api/v1/auth/login', $payload)->assertOk();
    }

    public function test_database_queue_retries_a_transient_notification_failure_without_duplicates(): void
    {
        $generator = app(NotificationGenerator::class);
        $mock = \Mockery::mock(NotificationGenerator::class);
        $mock->shouldReceive('forFarm')->once()->andThrow(new \RuntimeException('Transient test failure'));
        $this->app->instance(NotificationGenerator::class, $mock);
        Queue::connection('database')->push(new GenerateFarmNotifications($this->farm->id));
        $worker = app('queue.worker');
        $options = new WorkerOptions(sleep: 0, maxTries: 2);
        $worker->runNextJob('database', 'default', $options);
        $this->assertSame(1, (int) DB::table('jobs')->value('attempts'));
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->travel(31)->seconds();
        $this->app->instance(NotificationGenerator::class, $generator);
        $worker->runNextJob('database', 'default', $options);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $count = FarmNotification::count();
        $generator->forFarm($this->farm);
        $this->assertSame($count, FarmNotification::count());
    }

    public function test_dashboard_and_paginated_lists_under_repeated_fixture_load(): void
    {
        $this->layers();
        for ($i = 0; $i < 30; $i++) {
            $this->task(['title' => 'Load fixture '.$i])->assertCreated();
        }
        $times = [];
        for ($i = 0; $i < 10; $i++) {
            $start = microtime(true);
            $this->getJson('/api/v1/dashboard')->assertOk();
            $this->getJson('/api/v1/tasks?per_page=10')->assertOk()->assertJsonCount(10, 'data');
            $this->getJson('/api/v1/inventory/items?per_page=10')->assertOk();
            $times[] = round((microtime(true) - $start) * 1000, 2);
        }
        sort($times);
        fwrite(STDERR, '\nLaunch fixture load: 30 reads; three-request batch p50='.$times[4].'ms p95='.$times[9]."ms (in-process, sequential).\n");
    }

    public function test_reconciliation_accepts_real_ledger_and_reversals_without_writing(): void
    {
        $cycle = $this->layers();
        $record = $this->mortality($cycle, 2);
        $this->reverseRecord($record['id']);
        [, $invoice] = $this->invoicedSale('100.00');
        $payment = $this->pay($invoice['id'], '40.00')->assertCreated()->json('data');
        $this->postJson('/api/v1/payments/'.$payment['id'].'/reverse', ['reason' => 'Correction', 'idempotency_key' => $this->key()])->assertOk();
        $counts = [DB::table('population_movements')->count(), DB::table('inventory_movements')->count(), DB::table('finance_transactions')->count()];
        $this->assertSame([], app(LedgerReconciler::class)->forFarm($this->farm));
        $this->artisan('app:reconcile', ['--farm' => $this->farm->id])->assertSuccessful();
        $this->assertSame($counts, [DB::table('population_movements')->count(), DB::table('inventory_movements')->count(), DB::table('finance_transactions')->count()]);
    }

    public function test_reconciliation_detects_corrupt_population_stock_and_finance_and_scopes_farms(): void
    {
        $cycle = $this->layers();
        DB::table('population_movements')->where('production_cycle_id', $cycle)->update(['quantity' => 1]);
        [, $invoice] = $this->invoicedSale('100.00');
        $payment = $this->pay($invoice['id'], '40.00')->assertCreated()->json('data');
        DB::table('finance_transactions')->where('id', DB::table('payments')->where('id', $payment['id'])->value('finance_transaction_id'))->update(['amount' => '41.00']);
        DB::table('inventory_movements')->where('type', 'stock_out')->update(['quantity_delta' => '-2000']);
        $checks = array_column(app(LedgerReconciler::class)->forFarm($this->farm), 'check');
        $this->assertContains('cycle_reconciliation_failed', $checks);
        $this->assertContains('stock_negative_history', $checks);
        $this->assertContains('payment_finance_mismatch', $checks);
        $this->assertContains('sale_stock_mismatch', $checks);
        $this->artisan('app:reconcile', ['--farm' => $this->farm->id])->assertFailed();
        [, $other] = $this->otherFarm();
        $this->assertSame([], app(LedgerReconciler::class)->forFarm($other));
        $this->artisan('app:reconcile', ['--farm' => 'invalid'])->assertFailed();
    }

    public function test_reconciliation_detects_missing_operational_stock_and_purchase_expense(): void
    {
        $cycle = $this->layers();
        $item = $this->item('feed', 'kg');
        $location = $this->store();
        $this->receive($item, $location, '50', 'kg');
        $record = $this->record($cycle, 'feed_use', ['feed_name' => 'Layer feed', 'components' => [['quantity' => '1', 'unit' => 'kg']],
            'inventory' => ['item_id' => $item, 'storage_location_id' => $location]])->assertCreated()->json('data');
        $purchase = $this->postJson('/api/v1/purchases', ['recorded_at' => $this->at(), 'idempotency_key' => $this->key(),
            'items' => [['kind' => 'non_stock', 'description' => 'Transport', 'amount' => '12.00']]])->assertCreated()->json('data');
        $this->assertSame([], app(LedgerReconciler::class)->forFarm($this->farm));
        DB::table('inventory_movements')->where('operational_record_id', $record['id'])->delete();
        DB::table('finance_transactions')->where('source_type', 'purchase')->where('source_id', $purchase['id'])->delete();
        $checks = array_column(app(LedgerReconciler::class)->forFarm($this->farm), 'check');
        $this->assertContains('record_stock_mismatch', $checks);
        $this->assertContains('purchase_expense_mismatch', $checks);
    }
}
