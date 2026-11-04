<?php

namespace Tests\Feature\Notifications;

use App\Enums\FarmRole;
use App\Models\FarmNotification;
use App\Models\User;
use App\Notifications\FarmAlertNotification;
use App\Services\Notifications\NotificationGenerator;
use App\Services\Work\ReminderPlan;
use Carbon\CarbonImmutable;
use Tests\Feature\Team\TeamTestCase;
use Tests\Feature\Work\TaskTestHelpers;

class TaskReminderPresetsTest extends TeamTestCase
{
    use TaskTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->onPlan('farm-business');
        $this->freezeClock('2026-10-13 10:00:00'); // Tuesday, 11:00 in Lagos
        $this->signInAs($this->owner);
    }

    private function generate(string $utc): void
    {
        app(NotificationGenerator::class)->forFarm($this->farm->fresh(), CarbonImmutable::parse($utc, 'UTC'));
    }

    private function reminders(User $user): array
    {
        return FarmNotification::where('user_id', $user->id)->where('type', 'task_reminder')->orderBy('created_at')->orderBy('id')->get()->all();
    }

    /** @return array<string, string> preset => UTC fire time */
    private function fires(string $dueDate, ?string $time, array $presets, array $offsets = []): array
    {
        $dueAt = $time !== null ? CarbonImmutable::parse("$dueDate $time", 'Africa/Lagos')->utc() : CarbonImmutable::parse($dueDate, 'Africa/Lagos')->addDay()->startOfDay()->utc();

        return array_map(fn ($t) => $t->format('Y-m-d H:i'), ReminderPlan::fireTimes('Africa/Lagos', $dueDate, $time, $presets, $offsets, $dueAt));
    }

    // ------------------------------------------------------------------ fire times

    public function test_every_preset_fires_at_seven_farm_local_on_its_reminder_day_without_a_due_time(): void
    {
        $this->assertSame([
            'on_the_day' => '2026-10-20 06:00', '1_day' => '2026-10-19 06:00', '2_days' => '2026-10-18 06:00', '7_days' => '2026-10-13 06:00',
        ], $this->fires('2026-10-20', null, ['on_the_day', '1_day', '2_days', '7_days']), '07:00 in Lagos (UTC+1) is 06:00 UTC');
    }

    public function test_with_a_due_time_the_reminder_is_still_seven_but_on_the_day_is_at_least_one_hour_before(): void
    {
        $this->assertSame(['on_the_day' => '2026-10-20 06:00', '1_day' => '2026-10-19 06:00'], $this->fires('2026-10-20', '14:00', ['on_the_day', '1_day']));
        $this->assertSame(['on_the_day' => '2026-10-20 05:30'], $this->fires('2026-10-20', '07:30', ['on_the_day']), 'due 07:30: 06:30 local');
        $this->assertSame(['on_the_day' => '2026-10-20 06:00'], $this->fires('2026-10-20', '08:00', ['on_the_day']), 'due 08:00: exactly 07:00');
        $this->assertSame(['on_the_day' => '2026-10-19 23:00'], $this->fires('2026-10-20', '00:30', ['on_the_day']), 'never before the start of the day (00:00 local)');
        $this->assertSame(['1_day' => '2026-10-19 06:00'], $this->fires('2026-10-20', '07:30', ['1_day']), 'earlier days keep 07:00');
    }

    public function test_minute_offsets_still_count_back_from_the_due_time(): void
    {
        $this->assertSame(['m60' => '2026-10-20 11:30', 'm1440' => '2026-10-19 12:30'], $this->fires('2026-10-20', '13:30', [], [60, 1440]));
    }

    // ------------------------------------------------------------------ notifications

    public function test_a_preset_reminds_once_at_its_fire_time_and_the_message_is_specific(): void
    {
        $worker = $this->member(FarmRole::FarmWorker, null, 'Wale Worker');
        $cycle = $this->cycle();
        $batch = $this->getJson('/api/v1/production-cycles/'.$cycle)->json('data.name');
        $task = $this->task(['title' => 'Weigh birds', 'due_date' => '2026-10-20', 'reminders' => ['1_day'], 'assigned_user_id' => $worker->id, 'production_cycle_id' => $cycle])->assertCreated()->json('data');

        $this->generate('2026-10-19 05:59:00');
        $this->assertSame([], $this->reminders($worker), 'not yet 07:00 on 19 Oct');
        $this->generate('2026-10-19 06:00:00');
        $this->generate('2026-10-19 09:00:00');
        $got = $this->reminders($worker);
        $this->assertCount(1, $got, 'once, however often it is evaluated');
        $this->assertSame('task:'.$task['id'].':2026-10-20:remind:1_day', $got[0]->dedupe_key, 'the due date is part of the key');
        $this->assertSame('Reminder: Weigh birds', $got[0]->title);
        $this->assertStringContainsString('Weigh birds', $got[0]->message);
        $this->assertStringContainsString('Tue 20 Oct (all day)', $got[0]->message);
        $this->assertStringContainsString('Batch: '.$batch, $got[0]->message);
        $this->assertSame($task['id'], $got[0]->subject_id);
        $this->assertSame([], $this->reminders($this->owner), 'it is assigned to the worker, not the creator');
    }

    public function test_on_the_day_with_a_due_time_fires_an_hour_before_when_the_due_time_is_early(): void
    {
        $this->task(['title' => 'Early vaccine', 'due_date' => '2026-10-20', 'due_time' => '07:30', 'reminders' => ['on_the_day']])->assertCreated();
        $this->generate('2026-10-20 05:29:00');
        $this->assertSame([], $this->reminders($this->owner));
        $this->generate('2026-10-20 05:31:00');
        $got = $this->reminders($this->owner);
        $this->assertCount(1, $got);
        $this->assertStringContainsString('Tue 20 Oct, 07:30 (Africa/Lagos)', $got[0]->message, 'date and time in the farm timezone');
    }

    public function test_a_moved_task_is_reminded_again_for_its_new_date(): void
    {
        $task = $this->task(['title' => 'Move me', 'due_date' => '2026-10-20', 'reminders' => ['on_the_day']])->assertCreated()->json('data');
        $this->generate('2026-10-20 06:00:00');
        $this->assertCount(1, $this->reminders($this->owner));

        $this->patchJson('/api/v1/tasks/'.$task['id'], ['due_date' => '2026-10-22'])->assertOk();
        $this->generate('2026-10-20 07:00:00');
        $this->assertCount(1, $this->reminders($this->owner), 'nothing new before the new reminder day');
        $this->generate('2026-10-22 06:00:00');
        $this->generate('2026-10-22 08:00:00');
        $got = $this->reminders($this->owner);
        $this->assertCount(2, $got);
        $this->assertStringEndsWith(':2026-10-22:remind:on_the_day', $got[1]->dedupe_key);
    }

    public function test_presets_can_be_changed_on_an_open_task(): void
    {
        $task = $this->task(['due_date' => '2026-10-20'])->assertCreated()->json('data');
        $this->assertNull($task['reminders']);
        $this->patchJson('/api/v1/tasks/'.$task['id'], ['reminders' => ['2_days', '7_days']])->assertOk()->assertJsonPath('data.reminders', ['2_days', '7_days']);
        $this->patchJson('/api/v1/tasks/'.$task['id'], ['reminders' => null])->assertOk()->assertJsonPath('data.reminders', null);
    }

    public function test_who_is_reminded_assignee_role_or_the_creator_when_nobody_is_assigned(): void
    {
        $worker = $this->member(FarmRole::FarmWorker, null, 'Wale Worker');
        $other = $this->member(FarmRole::FarmWorker, null, 'Ola Other');
        $this->task(['title' => 'Nobody', 'due_date' => '2026-10-20', 'reminders' => ['on_the_day']])->assertCreated();
        $this->task(['title' => 'Direct', 'due_date' => '2026-10-20', 'reminders' => ['on_the_day'], 'assigned_user_id' => $worker->id])->assertCreated();
        $this->task(['title' => 'By role', 'due_date' => '2026-10-20', 'reminders' => ['on_the_day'], 'assigned_role' => FarmRole::FarmWorker->value])->assertCreated();

        $this->generate('2026-10-20 06:00:00');

        $this->assertSame(['Reminder: Nobody'], array_column($this->reminders($this->owner), 'title'), 'no assignee and no role: the creator');
        $this->assertEqualsCanonicalizing(['Reminder: Direct', 'Reminder: By role'], array_column($this->reminders($worker), 'title'));
        $this->assertSame(['Reminder: By role'], array_column($this->reminders($other), 'title'), 'a role-assigned task reaches every member with that role');
    }

    public function test_a_new_series_does_not_announce_reminder_times_that_were_already_past(): void
    {
        $this->repeating(['pattern' => 'daily', 'ends' => ['type' => 'never']], ['reminders' => ['7_days', '2_days', '1_day']])->assertCreated();
        $this->generate('2026-10-13 12:00:00');
        $this->assertSame([], $this->reminders($this->owner), 'tasks of the first week would otherwise all fire at once');
        $this->generate('2026-10-21 06:00:00'); // a later day: the 1_day reminder of the task due on 22 Oct is genuinely new
        $this->assertGreaterThan(0, count($this->reminders($this->owner)));
    }

    public function test_the_reminder_email_names_the_task_and_links_to_it_in_the_farm_timezone(): void
    {
        $task = $this->task(['title' => 'Candle eggs', 'due_date' => '2026-10-20', 'due_time' => '14:00', 'reminders' => ['on_the_day']])->assertCreated()->json('data');
        $this->generate('2026-10-20 06:00:00');
        $n = $this->reminders($this->owner)[0];
        $mail = (new FarmAlertNotification($n->id))->toMail($this->owner);
        $this->assertSame('Green Acres: Reminder: Candle eggs', $mail->subject);
        $this->assertStringContainsString('Tue 20 Oct, 14:00 (Africa/Lagos)', implode(' ', $mail->introLines));
        $this->assertStringEndsWith('/tasks/'.$task['id'], $mail->actionUrl);
        $this->assertSame('Open task', $mail->actionText);
        $this->assertSame('Africa/Lagos', $n->data['timezone']);
    }
}
