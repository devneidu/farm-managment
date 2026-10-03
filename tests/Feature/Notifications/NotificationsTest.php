<?php

namespace Tests\Feature\Notifications;

use App\Enums\FarmRole;
use App\Jobs\GenerateFarmNotifications;
use App\Models\FarmNotification;
use App\Models\User;
use App\Notifications\FarmAlertNotification;
use App\Services\Notifications\EmailDeliveryTracker;
use App\Services\Notifications\NotificationGenerator;
use App\Support\Access\NotificationPreferences;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Reports\ReportsFixtures;
use Tests\Feature\Team\TeamTestCase;

class NotificationsTest extends TeamTestCase
{
    use ReportsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->onPlan('farm-business');
        $this->bootClock();
        $this->signInAs($this->owner);
    }

    private function generate(?CarbonImmutable $at = null): array
    {
        return app(NotificationGenerator::class)->forFarm($this->farm->fresh(), $at);
    }

    private function mine(?string $type = null): array
    {
        $q = '/api/v1/notifications'.($type ? '?type='.$type : '');

        return $this->getJson($q)->assertOk()->json('data');
    }

    private function types(?User $user = null): array
    {
        return FarmNotification::where('user_id', ($user ?? $this->owner)->id)->orderBy('type')->pluck('type')->all();
    }

    private function lowStock(): string
    {
        $loc = $this->store();
        $feed = $this->item('feed', 'kg', ['name' => 'Layer mash', 'low_stock_threshold' => ['quantity' => '50', 'unit' => 'kg']]);
        $this->receive($feed, $loc, '40', 'kg');

        return $feed;
    }

    private function overdueInvoice(): array
    {
        [, $invoice] = $this->invoicedSale('3000.00', ['issue_date' => $this->day(-5), 'due_date' => $this->day(-1)], 24 * 5, null, 24 * 12);

        return $invoice;
    }

    // ------------------------------------------------------------------ creation from source conditions

    public function test_a_condition_becomes_a_notification_with_a_traceable_source(): void
    {
        $item = $this->lowStock();
        $this->assertSame([], $this->mine(), 'nothing is created until the conditions are evaluated');

        $result = $this->generate();
        $this->assertSame(1, $result['members']);

        $list = $this->getJson('/api/v1/notifications')->assertOk();
        $n = $list->json('data.0');
        $this->assertSame('low_stock', $n['type']);
        $this->assertSame('warning', $n['severity']);
        $this->assertSame('inventory_item', $n['source']['type']);
        $this->assertSame($item, $n['source']['id']);
        $this->assertSame('Layer mash', $n['source']['reference']);
        $this->assertSame('low_stock', $n['data']['why']['rule']);
        $this->assertSame('low_stock:'.$item, $n['data']['insight_id']);
        $this->assertFalse($n['is_read']);
        $this->assertNull($n['read_at']);
        $this->assertSame(1, $list->json('meta.unread_count'));
        $this->assertSame($this->farm->id, FarmNotification::first()->farm_id);
        $this->assertSame($this->owner->id, FarmNotification::first()->user_id);
    }

    public function test_every_phase_16_condition_can_notify(): void
    {
        $cycle = $this->layers(100, ['expected_end_date' => $this->day(-3)]);
        $this->mortality($cycle, 6); // 6% -> critical
        $this->lowStock();
        $this->overdueInvoice();
        $this->task(['title' => 'Late job', 'due_date' => $this->day(-2)])->assertCreated();
        $this->postJson('/api/v1/breeding-projects', ['production_cycle_id' => $cycle, 'workflow' => 'incubation', 'start_date' => $this->day(-30), 'eggs_set' => 20, 'idempotency_key' => $this->key()])->assertCreated();

        $this->generate();
        $this->assertEqualsCanonicalizing(['breeding_overdue', 'cycle_past_expected_end', 'low_stock', 'mortality_threshold', 'overdue_invoices', 'overdue_tasks'], $this->types());
        $byType = collect($this->mine())->keyBy('type');
        $this->assertSame('critical', $byType['mortality_threshold']['severity']);
        $this->assertSame($cycle, $byType['mortality_threshold']['source']['id']);
        $this->assertSame('production_cycle', $byType['mortality_threshold']['source']['type']);
        $this->assertSame('info', $byType['cycle_past_expected_end']['severity']);
        $this->assertSame('breeding_project', $byType['breeding_overdue']['source']['type']);
        $this->assertNull($byType['overdue_tasks']['source'], 'a farm-wide condition has no single source');
        $this->assertSame(1, $byType['overdue_tasks']['data']['why']['overdue']);
    }

    public function test_recipients_only_get_notifications_for_data_they_may_see(): void
    {
        $cycle = $this->layers(100);
        $this->mortality($cycle, 5);
        $this->overdueInvoice();
        $worker = $this->member(FarmRole::FarmWorker, null, 'Wale Worker');
        $finance = $this->member(FarmRole::Finance, null, 'Fola Finance');

        $this->generate();
        $this->assertContains('overdue_invoices', $this->types());
        $this->assertContains('overdue_invoices', $this->types($finance));
        $this->assertNotContains('overdue_invoices', $this->types($worker), 'a farm worker never learns about invoices');
        $this->assertContains('mortality_threshold', $this->types($worker));
        $this->assertContains('mortality_threshold', $this->types($finance), 'finance holds record.view');

        $raw = '';
        $this->signInAs($worker);
        $raw = $this->getJson('/api/v1/notifications')->getContent();
        $this->assertStringNotContainsString('INV-', $raw);
        $this->assertStringNotContainsString('3000', $raw);
    }

    public function test_removed_members_and_other_farms_are_not_notified(): void
    {
        $this->lowStock();
        $gone = $this->member(FarmRole::Manager, null, 'Gone');
        $this->removeMembership($gone);
        [$other] = $this->otherFarm();

        $this->generate();
        $this->assertSame([], $this->types($gone));
        $this->assertSame([], $this->types($other), 'a different farm is never evaluated by this farm run');
        $this->assertSame(0, FarmNotification::where('farm_id', $other->currentFarm()->id)->count());
    }

    public function test_task_reminders_go_to_the_assignee_once_per_reminder_time(): void
    {
        $worker = $this->member(FarmRole::FarmWorker, null, 'Wale Worker');
        $task = $this->task(['title' => 'Vaccinate', 'due_date' => $this->today(), 'due_time' => '12:30', 'reminder_offsets' => [1440, 60], 'assigned_user_id' => $worker->id])->assertCreated()->json('data');
        $this->task(['title' => 'Unassigned', 'due_date' => $this->today(), 'due_time' => '12:30', 'reminder_offsets' => [60]])->assertCreated();
        $this->task(['title' => 'Far away', 'due_date' => $this->day(3), 'due_time' => '12:30', 'reminder_offsets' => [60], 'assigned_user_id' => $worker->id])->assertCreated();

        $this->generate();
        $this->assertSame(['task_reminder'], $this->types($worker));
        $this->assertSame([], $this->types($this->owner), 'only the assignee is reminded');
        $n = FarmNotification::where('user_id', $worker->id)->first();
        $this->assertSame('task', $n->subject_type);
        $this->assertSame($task['id'], $n->subject_id);
        $this->assertSame($task['reference'], $n->subject_reference);
        $this->assertSame(60, $n->data['reminder_minutes_before'], 'the tightest reminder that has passed');
        $this->assertStringContainsString('Vaccinate', $n->message);
        $this->assertStringContainsString('12:30', $n->message, 'times are shown in the farm timezone');

        $this->generate();
        $this->assertSame(1, FarmNotification::where('user_id', $worker->id)->count(), 're-evaluating does not repeat the reminder');
        $this->assertSame('open', $this->getJson('/api/v1/tasks/'.$task['id'])->json('data.status'), 'the task itself is untouched');
    }

    // ------------------------------------------------------------------ deduplication

    public function test_a_condition_is_announced_once_per_farm_local_day(): void
    {
        $this->lowStock();
        $this->generate();
        $this->generate();
        $this->generate();
        $this->assertSame(1, FarmNotification::count());

        $this->travel(1)->days();
        $this->generate();
        $this->assertSame(2, FarmNotification::count(), 'the condition still holds tomorrow');
        $this->generate();
        $this->assertSame(2, FarmNotification::count());
        $this->assertSame(2, DB::table('notifications')->distinct()->count('dedupe_key'));
    }

    public function test_the_day_boundary_is_the_farm_local_day_not_the_utc_day(): void
    {
        $this->lowStock();
        $this->generate(CarbonImmutable::parse('2026-10-14 22:30:00', 'UTC')); // 23:30 in Lagos on the 14th
        $this->generate(CarbonImmutable::parse('2026-10-14 22:50:00', 'UTC'));
        $this->assertSame(1, FarmNotification::count());
        $this->generate(CarbonImmutable::parse('2026-10-14 23:30:00', 'UTC')); // 00:30 in Lagos on the 15th: same UTC day, new farm day
        $this->assertSame(2, FarmNotification::count());
    }

    public function test_informational_conditions_are_announced_once_per_week(): void
    {
        $this->layers(100, ['expected_end_date' => $this->day(-3)]);
        $this->generate();
        $this->assertSame(['cycle_past_expected_end'], $this->types());

        $this->travel(1)->days();
        $this->generate();
        $this->assertSame(1, FarmNotification::count(), 'info conditions do not repeat daily');
        $this->travel(7)->days();
        $this->generate();
        $this->assertSame(2, FarmNotification::count());
    }

    public function test_the_unique_key_also_protects_against_concurrent_workers(): void
    {
        $this->lowStock();
        $this->generate();
        $row = FarmNotification::first();
        $this->expectException(UniqueConstraintViolationException::class);
        FarmNotification::create(['farm_id' => $row->farm_id, 'user_id' => $row->user_id, 'type' => $row->type, 'severity' => 'warning', 'title' => 'x', 'message' => 'y', 'dedupe_key' => $row->dedupe_key]);
    }

    // ------------------------------------------------------------------ preferences

    public function test_a_disabled_type_is_never_created(): void
    {
        $this->lowStock();
        $this->layers(100, ['expected_end_date' => $this->day(-3)]);
        $this->patchJson('/api/v1/notification-preferences', ['types' => ['low_stock' => false]])->assertOk();

        $this->generate();
        $this->assertSame(['cycle_past_expected_end'], $this->types());

        $this->patchJson('/api/v1/notification-preferences', ['types' => ['low_stock' => true]])->assertOk();
        $this->generate();
        $this->assertContains('low_stock', $this->types(), 'turning it back on picks the condition up on the next evaluation');
    }

    public function test_channel_switches_decide_what_is_stored_and_emailed(): void
    {
        Notification::fake();
        $this->lowStock();

        $this->patchJson('/api/v1/notification-preferences', ['channels' => ['in_app' => false, 'email' => false]])->assertOk();
        $this->generate();
        $this->assertSame(0, FarmNotification::count(), 'both channels off: nothing is created');
        Notification::assertNothingSent();

        $this->patchJson('/api/v1/notification-preferences', ['channels' => ['email' => true]])->assertOk();
        $this->generate();
        $this->assertSame(1, FarmNotification::count());
        $this->assertSame([], $this->mine(), 'in-app off: it does not appear in the notification centre');
        Notification::assertSentTo($this->owner, FarmAlertNotification::class);
        $this->assertSame('queued', FarmNotification::first()->email_status);

        $this->travel(1)->days();
        $this->patchJson('/api/v1/notification-preferences', ['channels' => ['in_app' => true, 'email' => false]])->assertOk();
        $this->generate();
        $this->assertCount(1, $this->mine());
        Notification::assertSentToTimes($this->owner, FarmAlertNotification::class, 1);
    }

    public function test_the_email_copy_is_queued_and_its_delivery_state_is_recorded_on_the_notification(): void
    {
        $this->lowStock();
        $this->generate();
        $n = FarmNotification::first();
        Notification::assertSentTo($this->owner, FarmAlertNotification::class, fn ($mail) => $mail->notificationId === $n->id);
        $this->assertSame('queued', $n->email_status);

        // The mail transport reports back through Laravel's notification events.
        $tracker = app(EmailDeliveryTracker::class);
        $tracker->sent(new NotificationSent($this->owner, new FarmAlertNotification($n->id), 'mail'));
        $n->refresh();
        $this->assertSame('sent', $n->email_status);
        $this->assertNotNull($n->emailed_at);
        $this->assertNull($n->read_at, 'sending an email does not read the notification');

        $this->travel(1)->days();
        $this->generate();
        $second = FarmNotification::whereKeyNot($n->id)->first();
        $tracker->failed(new NotificationFailed($this->owner, new FarmAlertNotification($second->id), 'mail'));
        $this->assertSame('failed', $second->fresh()->email_status);
        $this->assertNull($second->fresh()->emailed_at);

        $mail = (new FarmAlertNotification($n->id))->toMail($this->owner);
        $this->assertStringContainsString('Green Acres', $mail->subject);
        $this->assertStringContainsString('Layer mash', implode(' ', $mail->introLines));
    }

    public function test_preferences_list_only_types_the_member_can_receive(): void
    {
        $owner = collect($this->getJson('/api/v1/notification-preferences')->assertOk()->json('data.types'))->keyBy('code');
        $this->assertTrue($owner['overdue_invoices']['enabled']);
        $this->assertArrayHasKey('export_ready', $owner->all());
        $this->assertSame(['in_app' => true, 'email' => true], $this->getJson('/api/v1/notification-preferences')->json('data.channels'));

        $this->signInAs($this->member(FarmRole::FarmWorker));
        $worker = array_column($this->getJson('/api/v1/notification-preferences')->json('data.types'), 'code');
        $this->assertContains('task_reminder', $worker);
        $this->assertContains('low_stock', $worker);
        $this->assertNotContains('overdue_invoices', $worker);
        $this->assertNotContains('export_ready', $worker);

        $this->patchJson('/api/v1/notification-preferences', ['types' => ['overdue_invoices' => false]])->assertStatus(422)->assertJsonValidationErrors('types');
        $this->patchJson('/api/v1/notification-preferences', ['types' => ['made_up' => false]])->assertStatus(422);
        $this->patchJson('/api/v1/notification-preferences', ['types' => ['low_stock' => 'maybe']])->assertStatus(422);
        $this->patchJson('/api/v1/notification-preferences', ['channels' => ['whatsapp' => true]])->assertStatus(422)->assertJsonValidationErrors('channels');
    }

    public function test_preferences_are_per_user_and_the_legacy_channel_endpoint_keeps_type_choices(): void
    {
        $worker = $this->member(FarmRole::FarmWorker);
        $this->patchJson('/api/v1/notification-preferences', ['types' => ['low_stock' => false], 'channels' => ['email' => false]])->assertOk();
        $this->putJson('/api/v1/settings/notifications', ['channels' => ['in_app' => true]])->assertOk()->assertJsonPath('data.channels.email', false);

        $mine = collect($this->getJson('/api/v1/notification-preferences')->json('data.types'))->keyBy('code');
        $this->assertFalse($mine['low_stock']['enabled'], 'the legacy endpoint does not wipe per-type choices');

        $this->signInAs($worker);
        $theirs = collect($this->getJson('/api/v1/notification-preferences')->json('data.types'))->keyBy('code');
        $this->assertTrue($theirs['low_stock']['enabled']);
        $this->assertTrue($this->getJson('/api/v1/notification-preferences')->json('data.channels.email'));
        $this->getJson('/api/v1/settings/notifications')->assertOk()->assertJsonPath('data.channels.in_app', true);
    }

    public function test_there_is_no_whatsapp_dependency(): void
    {
        $this->assertSame(['in_app', 'email'], NotificationPreferences::CHANNELS);
        $dirs = [app_path('Services/Notifications'), app_path('Notifications'), app_path('Jobs'), app_path('Services/Reports')];
        foreach ($dirs as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $this->assertStringNotContainsStringIgnoringCase('whatsapp', file_get_contents($file->getPathname()), $file->getFilename());
            }
        }
    }

    // ------------------------------------------------------------------ read state

    public function test_read_state_belongs_to_the_notification_and_changes_nothing_else(): void
    {
        $item = $this->lowStock();
        $this->generate();
        $id = $this->mine()[0]['id'];
        $itemRow = DB::table('inventory_items')->where('id', $item)->first();
        $movements = DB::table('inventory_movements')->count();

        $this->travel(5)->minutes();
        $first = $this->postJson('/api/v1/notifications/'.$id.'/read')->assertOk()->json('data');
        $this->assertTrue($first['is_read']);
        $this->travel(1)->hours();
        $again = $this->postJson('/api/v1/notifications/'.$id.'/read')->assertOk()->json('data');
        $this->assertSame($first['read_at'], $again['read_at'], 'reading twice keeps the first read time');

        $this->assertEquals($itemRow, DB::table('inventory_items')->where('id', $item)->first(), 'the source is untouched');
        $this->assertSame($movements, DB::table('inventory_movements')->count());
        $this->assertSame(0, $this->getJson('/api/v1/notifications')->json('meta.unread_count'));
        $this->assertSame([], $this->mine_unread());

        // The same condition still shows as an insight and does not come back as unread on re-evaluation.
        $this->assertSame('low_stock', collect($this->getJson('/api/v1/insights')->json('data'))->firstWhere('code', 'low_stock')['code']);
        $this->generate();
        $this->assertSame(1, FarmNotification::count());
    }

    private function mine_unread(): array
    {
        return $this->getJson('/api/v1/notifications?unread=1')->assertOk()->json('data');
    }

    public function test_read_all_and_filters(): void
    {
        $cycle = $this->layers(100, ['expected_end_date' => $this->day(-3)]);
        $this->mortality($cycle, 6);
        $this->lowStock();
        $this->generate();
        $this->assertCount(3, $this->mine());
        $this->assertCount(1, $this->mine('low_stock'));
        $this->assertCount(1, $this->getJson('/api/v1/notifications?severity=critical')->json('data'));
        $this->assertSame(3, $this->getJson('/api/v1/notifications?unread=1')->json('meta.unread_count'));

        $one = $this->mine('low_stock')[0]['id'];
        $this->postJson('/api/v1/notifications/'.$one.'/read')->assertOk();
        $this->assertCount(2, $this->mine_unread());
        $this->assertCount(1, $this->getJson('/api/v1/notifications?unread=0')->json('data'));

        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.updated', 2);
        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.updated', 0);
        $this->assertSame(0, $this->getJson('/api/v1/notifications')->json('meta.unread_count'));
        $this->getJson('/api/v1/notifications?severity=loud')->assertStatus(422);
        $this->getJson('/api/v1/notifications?per_page=500')->assertStatus(422);
    }

    public function test_notifications_are_isolated_by_user_and_by_farm(): void
    {
        $this->lowStock();
        $manager = $this->member(FarmRole::Manager, null, 'Mo Manager');
        $this->generate();
        $ownerNotification = FarmNotification::where('user_id', $this->owner->id)->first();
        $managerNotification = FarmNotification::where('user_id', $manager->id)->first();
        $this->assertNotNull($managerNotification);

        // One user can neither see nor read another user's notification, even on the same farm.
        $this->signInAs($manager);
        $this->assertSame([$managerNotification->id], array_column($this->mine(), 'id'));
        $this->postJson('/api/v1/notifications/'.$ownerNotification->id.'/read')->assertNotFound();
        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.updated', 1);
        $this->assertNull($ownerNotification->fresh()->read_at, 'read-all only touches the caller\'s own notifications');

        // Another farm's user gets a plain 404 as well.
        [$other] = $this->otherFarm();
        $this->signInAs($other);
        $this->assertSame([], $this->mine());
        $this->postJson('/api/v1/notifications/'.$ownerNotification->id.'/read')->assertNotFound();
        $this->assertSame(0, $this->getJson('/api/v1/notifications')->json('meta.unread_count'));

        // A user who belongs to two farms only sees the notifications of the farm they are acting on.
        $this->signInAs($this->owner);
        $this->assertSame([$ownerNotification->id], array_column($this->mine(), 'id'));

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
        $this->postJson('/api/v1/notifications/read-all')->assertUnauthorized();
        $this->getJson('/api/v1/notification-preferences')->assertUnauthorized();
    }

    // ------------------------------------------------------------------ background processing

    public function test_the_command_queues_one_job_per_farm_and_the_job_creates_the_notifications(): void
    {
        $this->lowStock();
        [$other] = $this->otherFarm();

        Queue::fake();
        $this->artisan('notifications:generate')->expectsOutputToContain('Queued 2 farm job(s)')->assertSuccessful();
        Queue::assertPushed(GenerateFarmNotifications::class, 2);
        Queue::assertPushed(GenerateFarmNotifications::class, fn ($job) => $job->farmId === $this->farm->id);
        $this->assertSame(0, FarmNotification::count());

        (new GenerateFarmNotifications($this->farm->id))->handle(app(NotificationGenerator::class));
        $this->assertSame(['low_stock'], $this->types());
        (new GenerateFarmNotifications($this->farm->id))->handle(app(NotificationGenerator::class));
        $this->assertSame(1, FarmNotification::count(), 'a repeated job is harmless');
        (new GenerateFarmNotifications('00000000-0000-7000-8000-000000000000'))->handle(app(NotificationGenerator::class));
    }

    public function test_the_command_can_run_inline_for_one_farm(): void
    {
        $this->lowStock();
        $this->artisan('notifications:generate --now --farm='.$this->farm->id)->expectsOutputToContain('Created 1 notification')->assertSuccessful();
        $this->assertSame(1, FarmNotification::count());
        $scheduled = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' ');
        $this->assertStringContainsString('notifications:generate', $scheduled);
        $this->assertStringContainsString('reports:prune-exports', $scheduled);
    }

    public function test_a_finished_export_notifies_only_its_requester(): void
    {
        Storage::fake('local');
        $manager = $this->member(FarmRole::Manager, null, 'Mo Manager');
        $this->signInAs($manager);
        $export = $this->postJson('/api/v1/reports/exports', ['report' => 'mortality', 'format' => 'csv', 'idempotency_key' => $this->key()])->assertStatus(202)->json('data');

        $n = FarmNotification::where('type', 'export_ready')->first();
        $this->assertNotNull($n);
        $this->assertSame($manager->id, $n->user_id);
        $this->assertSame('report_export', $n->subject_type);
        $this->assertSame($export['id'], $n->subject_id);
        $this->assertSame(1, FarmNotification::where('type', 'export_ready')->count());
        $this->assertSame([], $this->types($this->owner));
        $this->assertSame('export_ready', $this->mine()[0]['type']);
    }

    public function test_notifications_do_not_exist_for_anyone_before_evaluation_and_running_reports_creates_none(): void
    {
        $this->lowStock();
        $this->getJson('/api/v1/dashboard')->assertOk();
        $this->getJson('/api/v1/insights')->assertOk();
        $this->getJson('/api/v1/reports/inventory_stock')->assertOk();
        $this->assertSame(0, FarmNotification::count(), 'reading a read model never creates notifications');
    }
}
