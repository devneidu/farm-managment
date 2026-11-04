<?php

namespace Tests\Feature\Work;

use App\Enums\FarmRole;
use App\Enums\Recurrence;
use App\Models\Schedule;
use App\Models\Task;
use App\Services\Work\Occurrences;
use Tests\Feature\Team\TeamTestCase;

class TasksCalendarTest extends TeamTestCase
{
    use TaskTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->onPlan('farm-pro');
        $this->freezeClock();
        $this->signInAs($this->owner);
    }

    private function dates(array $tasks): array
    {
        return array_map(fn ($t) => $t['due_date'], $tasks);
    }

    // ------------------------------------------------------------------ single entry point

    public function test_without_repeat_the_task_form_still_creates_one_plain_task(): void
    {
        $r = $this->task(['reminders' => ['1_day', 'on_the_day']])->assertCreated();
        $this->assertArrayNotHasKey('schedule', $r->json('data'));
        $this->assertNull($r->json('data.schedule_id'));
        $this->assertSame(['1_day', 'on_the_day'], $r->json('data.reminders'));
        $this->assertSame(1, Task::count());
        $this->assertSame(0, Schedule::count());
        $this->task(['repeat' => null])->assertCreated()->assertJsonPath('data.title', 'Check drinkers');
        $this->task(['reminders' => ['next_week']])->assertStatus(422)->assertJsonValidationErrors('reminders.0');
    }

    public function test_a_repeat_block_creates_a_schedule_and_returns_it_with_its_generated_tasks(): void
    {
        $r = $this->repeating(['pattern' => 'daily', 'every' => 1, 'ends' => ['type' => 'never']])->assertCreated();
        $this->assertSame('daily', $r->json('data.schedule.recurrence'));
        $this->assertSame('never', $r->json('data.schedule.ends_type'));
        $this->assertCount(31, $r->json('data.tasks'), 'today through the 30-day horizon');
        $this->assertSame($r->json('data.schedule.id'), $r->json('data.tasks.0.schedule_id'));
        $this->assertSame(31, Schedule::first()->tasks()->count());
    }

    public function test_repeat_creation_is_idempotent_and_needs_task_manage(): void
    {
        $payload = $this->payload(['repeat' => ['pattern' => 'weekly', 'ends' => ['type' => 'days', 'days' => 14]]]);
        $first = $this->postJson('/api/v1/tasks', $payload)->assertCreated()->json('data');
        $again = $this->postJson('/api/v1/tasks', $payload)->assertCreated()->json('data');
        $this->assertSame($first['schedule']['id'], $again['schedule']['id']);
        $this->assertSame(1, Schedule::count());
        $this->postJson('/api/v1/tasks', ['title' => 'Other'] + $payload)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');

        $this->signInAs($this->member(FarmRole::FarmWorker));
        $this->repeating(['pattern' => 'daily'])->assertForbidden();
    }

    // ------------------------------------------------------------------ patterns

    public function test_daily_every_two_days_for_five_days(): void
    {
        $r = $this->repeating(['pattern' => 'daily', 'every' => 2, 'ends' => ['type' => 'days', 'days' => 5]])->assertCreated();
        $this->assertSame($this->day(4), $r->json('data.schedule.ends_on'), 'ends.days = 5 means start + 5 - 1');
        $this->assertSame([$this->day(0), $this->day(2), $this->day(4)], $this->dates($r->json('data.tasks')));
    }

    public function test_weekly_on_chosen_weekdays_until_a_date(): void
    {
        // 2026-10-14 is a Wednesday; Monday = 1, Wednesday = 3.
        $r = $this->repeating(['pattern' => 'weekly', 'weekdays' => [1, 3], 'ends' => ['type' => 'date', 'date' => '2026-10-28']])->assertCreated();
        $this->assertSame(['2026-10-14', '2026-10-19', '2026-10-21', '2026-10-26', '2026-10-28'], $this->dates($r->json('data.tasks')));
        $this->repeating(['pattern' => 'daily', 'weekdays' => [1]])->assertStatus(422)->assertJsonValidationErrors('repeat.weekdays');
        $this->repeating(['pattern' => 'weekly', 'ends' => ['type' => 'date', 'date' => $this->day(-1)]])->assertStatus(422)->assertJsonValidationErrors('repeat.ends.date');
        $this->repeating(['pattern' => 'weekly', 'ends' => ['type' => 'days']])->assertStatus(422)->assertJsonValidationErrors('repeat.ends.days');
    }

    public function test_monthly_repeats_clamp_to_the_last_day_of_shorter_months(): void
    {
        $dates = fn (int $every, ?int $limit = null) => Occurrences::between(Recurrence::Monthly, $every, null, '2026-01-31', null, $limit, '2026-01-01', '2026-12-31');
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30'], array_slice($dates(1), 0, 6), 'the 31st never drifts to the 28th');
        $this->assertSame(['2026-01-31', '2026-03-31', '2026-05-31', '2026-07-31', '2026-09-30', '2026-11-30'], $dates(2));
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], $dates(1, 3));
        $this->assertSame(['2028-02-29'], Occurrences::between(Recurrence::Monthly, 1, null, '2028-01-31', null, null, '2028-02-01', '2028-02-29'), 'leap year');
        $this->assertSame(['2026-01-30', '2026-02-28'], array_slice(Occurrences::between(Recurrence::Monthly, 1, null, '2026-01-30', null, null, '2026-01-01', '2026-03-01'), 0, 2));
    }

    public function test_monthly_through_the_api_generates_the_horizon_and_lists_the_rest_as_coming_repeats(): void
    {
        $r = $this->repeating(['pattern' => 'monthly', 'ends' => ['type' => 'never']], ['due_date' => '2026-10-31'])->assertCreated();
        $this->assertSame(['2026-10-31'], $this->dates($r->json('data.tasks')), 'only the 30-day horizon is generated');
        $cal = $this->getJson('/api/v1/calendar?from=2026-11-01&to=2026-12-31')->assertOk()->json('data');
        $coming = array_values(array_filter($cal, fn ($i) => $i['kind'] === 'upcoming_repeat'));
        $this->assertSame(['2026-11-30', '2026-12-31'], array_column($coming, 'date'), 'November is clamped to the 30th');
    }

    // ------------------------------------------------------------------ end types

    public function test_never_and_date_and_days_end_types_are_stored_as_expected(): void
    {
        $never = $this->repeating(['pattern' => 'daily'])->assertCreated()->json('data.schedule');
        $this->assertNull($never['ends_on']);
        $this->assertSame('active', $never['status']);
        $date = $this->repeating(['pattern' => 'daily', 'ends' => ['type' => 'date', 'date' => $this->day(3)]])->assertCreated()->json('data');
        $this->assertSame('date', $date['schedule']['ends_type']);
        $this->assertCount(4, $date['tasks']);
        $this->assertSame('ended', $date['schedule']['status'], 'a fully generated bounded schedule ends itself');
    }

    public function test_batch_end_needs_a_batch_and_runs_while_it_is_open(): void
    {
        $this->repeating(['pattern' => 'daily', 'ends' => ['type' => 'batch_end']])->assertStatus(422)->assertJsonValidationErrors('repeat.ends.type');
        $this->postJson('/api/v1/schedules', ['title' => 'Feed', 'category' => 'feeding_watering', 'recurrence' => 'daily', 'starts_on' => $this->today(), 'ends_type' => 'batch_end', 'idempotency_key' => 'k-batch-none'])
            ->assertStatus(422)->assertJsonValidationErrors('ends_type');

        $cycle = $this->cycle();
        $r = $this->repeating(['pattern' => 'daily', 'ends' => ['type' => 'batch_end']], ['production_cycle_id' => $cycle])->assertCreated();
        $this->assertSame('batch_end', $r->json('data.schedule.ends_type'));
        $this->assertSame('active', $r->json('data.schedule.status'));
        $this->assertCount(31, $r->json('data.tasks'));
    }

    public function test_closing_the_batch_ends_the_schedule_and_cancels_its_future_open_tasks_only(): void
    {
        $cycle = $this->cycle();
        $batch = $this->repeating(['pattern' => 'daily', 'ends' => ['type' => 'batch_end']], ['production_cycle_id' => $cycle])->assertCreated()->json('data');
        $plain = $this->repeating(['pattern' => 'daily', 'ends' => ['type' => 'never']], ['production_cycle_id' => $cycle, 'title' => 'Open-ended'])->assertCreated()->json('data');
        $this->postJson('/api/v1/tasks/'.$batch['tasks'][1]['id'].'/complete')->assertOk();

        $this->closeCycle($cycle);

        $this->assertSame('ended', Schedule::find($batch['schedule']['id'])->status);
        $tasks = Task::where('schedule_id', $batch['schedule']['id'])->orderBy('due_date')->get();
        $this->assertSame('open', $tasks[0]->status->value, 'due today: not future, kept');
        $this->assertSame('completed', $tasks[1]->status->value, 'completed work is history');
        foreach ($tasks->slice(2) as $t) {
            $this->assertSame('cancelled', $t->status->value);
            $this->assertSame('Batch closed', $t->cancel_reason);
        }
        $this->assertSame('active', Schedule::find($plain['schedule']['id'])->status, 'a schedule that is not "until the batch ends" is not touched by this hook');
        $this->assertSame(0, Task::where('schedule_id', $batch['schedule']['id'])->where('status', 'open')->where('due_date', '>', $this->today())->count());
    }

    // ------------------------------------------------------------------ edit this and all future

    public function test_edit_series_splits_the_series_and_leaves_completed_and_earlier_tasks_alone(): void
    {
        $series = $this->repeating(['pattern' => 'daily', 'ends' => ['type' => 'never']], ['title' => 'Water'])->assertCreated()->json('data');
        $tasks = $series['tasks'];
        $this->postJson('/api/v1/tasks/'.$tasks[1]['id'].'/complete')->assertOk();
        $this->postJson('/api/v1/tasks/'.$tasks[4]['id'].'/complete')->assertOk(); // a later task that is already done
        $pivot = $tasks[3];

        $key = 'edit-series-key-1';
        $r = $this->editSeries($pivot['id'], ['title' => 'Water twice', 'due_time' => '08:00', 'reminders' => ['on_the_day'], 'idempotency_key' => $key])->assertCreated();
        $new = $r->json('data.schedule');
        $this->assertSame($series['schedule']['id'], $r->json('data.previous_schedule_id'));
        $this->assertNotSame($series['schedule']['id'], $new['id']);
        $this->assertSame($this->day(3), $new['starts_on']);
        $this->assertSame('Water twice', $new['title']);
        $this->assertSame('08:00', $new['due_time']);
        $this->assertSame($this->day(3), $r->json('data.tasks.0.due_date'));
        $this->assertSame(['on_the_day'], $r->json('data.tasks.0.reminders'));

        $old = Schedule::find($series['schedule']['id']);
        $this->assertSame('ended', $old->status);
        $this->assertSame($this->day(2), $old->ends_on->toDateString(), 'ended the day before this occurrence');
        $byDate = Task::where('schedule_id', $old->id)->get()->keyBy(fn ($t) => $t->occurrence_date->toDateString());
        $this->assertSame('open', $byDate[$this->day(0)]->status->value, 'earlier open task untouched');
        $this->assertSame('completed', $byDate[$this->day(1)]->status->value);
        $this->assertSame('open', $byDate[$this->day(2)]->status->value);
        $this->assertSame('cancelled', $byDate[$this->day(3)]->status->value);
        $this->assertSame('Series edited', $byDate[$this->day(3)]->cancel_reason);
        $this->assertSame('completed', $byDate[$this->day(4)]->status->value, 'completed tasks are never cancelled, even in the future');
        $this->assertSame('cancelled', $byDate[$this->day(5)]->status->value);
        $this->assertSame('Water', $byDate[$this->day(2)]->title, 'old tasks keep their old title');

        // Retrying the same request returns the same new schedule and changes nothing more.
        $count = Schedule::count();
        $replay = $this->editSeries($pivot['id'], ['title' => 'Water twice', 'due_time' => '08:00', 'reminders' => ['on_the_day'], 'idempotency_key' => $key])->assertCreated();
        $this->assertSame($new['id'], $replay->json('data.schedule.id'));
        $this->assertSame($count, Schedule::count());
        $this->editSeries($pivot['id'], ['title' => 'Different', 'idempotency_key' => $key])->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
    }

    public function test_edit_series_can_change_the_repeat_and_rejects_single_completed_and_closed_work(): void
    {
        $cycle = $this->cycle();
        $series = $this->repeating(['pattern' => 'daily', 'ends' => ['type' => 'batch_end']], ['production_cycle_id' => $cycle])->assertCreated()->json('data');
        $r = $this->editSeries($series['tasks'][2]['id'], ['repeat' => ['pattern' => 'weekly', 'weekdays' => [1], 'ends' => ['type' => 'batch_end']]])->assertCreated();
        $this->assertSame('weekly', $r->json('data.schedule.recurrence'));
        $this->assertSame('batch_end', $r->json('data.schedule.ends_type'));
        $this->assertSame($cycle, $r->json('data.schedule.production_cycle_id'));
        $this->assertSame(['2026-10-19', '2026-10-26', '2026-11-02', '2026-11-09'], array_slice($this->dates($r->json('data.tasks')), 0, 4));

        $this->postJson('/api/v1/tasks/'.$series['tasks'][0]['id'].'/complete')->assertOk();
        $this->editSeries($series['tasks'][0]['id'], ['title' => 'x'])->assertStatus(409)->assertJsonPath('code', 'task_not_open');
        $single = $this->task()->assertCreated()->json('data');
        $this->editSeries($single['id'], ['title' => 'x'])->assertStatus(409)->assertJsonPath('code', 'task_not_in_series');
        $fresh = $this->repeating(['pattern' => 'daily'], ['title' => 'Fresh'])->assertCreated()->json('data');
        $this->editSeries($fresh['tasks'][5]['id'], ['due_date' => $this->day(1)])->assertStatus(422)->assertJsonValidationErrors('due_date');

        $this->closeCycle($cycle);
        $this->assertSame(0, Task::where('schedule_id', $r->json('data.schedule.id'))->where('status', 'open')->where('due_date', '>', $this->today())->count(), 'closing the batch cancelled the new series\' future tasks');
    }

    // ------------------------------------------------------------------ coming repeats

    public function test_coming_repeats_follow_filters_visibility_and_the_92_day_limit(): void
    {
        $cycle = $this->cycle();
        $this->repeating(['pattern' => 'weekly', 'weekdays' => [5]], ['title' => 'Friday weighing', 'category' => 'growth_monitoring', 'production_cycle_id' => $cycle])->assertCreated();
        $this->repeating(['pattern' => 'daily', 'ends' => ['type' => 'days', 'days' => 3]], ['title' => 'Short job'])->assertCreated();

        $range = '/api/v1/calendar?from=2026-12-01&to=2026-12-31';
        $items = $this->getJson($range)->assertOk()->json('data');
        $coming = array_values(array_filter($items, fn ($i) => $i['kind'] === 'upcoming_repeat'));
        $this->assertSame(['2026-12-04', '2026-12-11', '2026-12-18', '2026-12-25'], array_column($coming, 'date'));
        $first = $coming[0];
        $this->assertSame(['kind', 'date', 'end_date', 'time', 'title', 'category', 'schedule_id', 'production_cycle_id', 'breeding_project_id'], array_keys($first));
        $this->assertSame('Friday weighing', $first['title']);
        $this->assertSame('growth_monitoring', $first['category']);
        $this->assertSame(Schedule::where('title', 'Friday weighing')->value('id'), $first['schedule_id']);

        $this->assertCount(0, array_filter($this->getJson($range.'&category=feeding_watering')->json('data'), fn ($i) => $i['kind'] === 'upcoming_repeat'));
        $this->assertCount(4, array_filter($this->getJson($range.'&production_cycle_id='.$cycle)->json('data'), fn ($i) => $i['kind'] === 'upcoming_repeat'));
        $this->assertCount(0, array_filter($this->getJson($range.'&include_upcoming_repeats=0')->json('data'), fn ($i) => $i['kind'] === 'upcoming_repeat'));
        $this->getJson('/api/v1/calendar?from=2026-10-01&to=2027-02-01')->assertStatus(422);

        $this->signInAs($this->member(FarmRole::FarmWorker));
        $this->assertSame([], array_filter($this->getJson($range)->assertOk()->json('data'), fn ($i) => $i['kind'] === 'upcoming_repeat'), 'a worker only sees repeats that are theirs');

        $this->signInAs($this->owner);
        $this->assertSame(0, Task::where('due_date', '>=', '2026-12-01')->count(), 'coming repeats are computed, never stored');
    }

    public function test_the_dashboard_calendar_is_unchanged_by_coming_repeats(): void
    {
        $this->repeating(['pattern' => 'monthly'], ['due_date' => '2026-10-20'])->assertCreated();
        $days = $this->getJson('/api/v1/dashboard/calendar?from=2026-11-18&days=7')->assertOk()->json('data.days');
        $this->assertCount(7, $days);
    }

    // ------------------------------------------------------------------ categories

    public function test_categories_carry_a_description_and_a_colour_group(): void
    {
        $cats = $this->getJson('/api/v1/master/task-categories')->assertOk()->json('data');
        $this->assertCount(15, $cats);
        $groups = ['feeding_eggs_growth', 'health_vaccines', 'breeding', 'cleaning_repairs_moves', 'crops', 'orders_records_other'];
        foreach ($cats as $c) {
            $this->assertNotSame('', $c['description']);
            $this->assertContains($c['color_group'], $groups);
            $this->assertArrayHasKey('label', $c);
        }
        $by = array_column($cats, 'color_group', 'code');
        $this->assertSame('health_vaccines', $by['vaccination_medication']);
        $this->assertSame('feeding_eggs_growth', $by['egg_collection']);
        $this->assertSame('crops', $by['harvest']);
        $this->assertSame('orders_records_other', $by['payment']);
    }
}
