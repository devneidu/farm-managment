<?php

namespace Tests\Feature\Work;

use App\Models\Schedule;
use App\Models\Task;
use App\Models\TemplateApplication;
use App\Models\WorkTemplate;
use Illuminate\Support\Str;
use Tests\Feature\Team\TeamTestCase;

/** Routines: any-batch / limited / general scope, steps in the task form's language, save as routine, preview, re-apply and remove. */
class RoutinesTest extends TeamTestCase
{
    use TaskTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->onPlan('farm-pro');
        $this->freezeClock();
        $this->signInAs($this->owner);
    }

    private function routine(array $extra = [], array $step = [])
    {
        return $this->postJson('/api/v1/work-templates', array_replace(['name' => 'My routine', 'applies_to' => 'production_cycle',
            'items' => [array_replace(['title' => 'Check drinkers', 'category' => 'feeding_watering', 'anchor' => 'start_date', 'offset_days' => 0], $step)]], $extra));
    }

    private function apply(string $template, array $body = [], ?string $key = null)
    {
        return $this->postJson('/api/v1/work-templates/'.$template.'/apply', $body + ['idempotency_key' => $key ?? (string) Str::uuid()]);
    }

    private function counts(): array
    {
        return [Schedule::count(), Task::count(), TemplateApplication::count()];
    }

    private function dates(string $application, ?string $title = null): array
    {
        $ids = Schedule::where('template_application_id', $application)->when($title, fn ($q) => $q->where('title', $title))->pluck('id');

        return Task::whereIn('schedule_id', $ids)->orderBy('due_date')->pluck('due_date')->map(fn ($d) => $d->toDateString())->all();
    }

    // ------------------------------------------------------------------ scope

    public function test_a_routine_for_any_batch_fits_every_batch_and_a_limited_one_only_its_species(): void
    {
        $chicken = $this->cycle('chicken');
        $goat = $this->cycle('goat');
        $any = $this->routine(['name' => 'Any batch'])->assertCreated()->assertJsonPath('data.scope', 'any_batch')->assertJsonPath('data.scope_label', 'Any batch')->assertJsonPath('data.step_count', 1)->json('data.id');
        $species = \App\Models\Species::where('code', 'chicken')->firstOrFail();
        $limited = $this->routine(['name' => 'Chickens only', 'cycle_kind' => 'livestock', 'species_id' => $species->id])->assertCreated()->assertJsonPath('data.scope', 'specific')
            ->assertJsonPath('data.scope_label', $species->name.' batches')->json('data.id');

        $this->apply($any, ['production_cycle_id' => $chicken])->assertCreated();
        $this->apply($any, ['production_cycle_id' => $goat])->assertCreated();
        $this->apply($limited, ['production_cycle_id' => $chicken])->assertCreated();
        $this->apply($limited, ['production_cycle_id' => $goat])->assertStatus(422)->assertJsonValidationErrors('production_cycle_id');

        $rows = collect($this->getJson('/api/v1/work-templates?source=farm&production_cycle_id='.$goat)->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($rows[$any]['suits_batch']);
        $this->assertFalse($rows[$limited]['suits_batch']);
        $this->assertArrayNotHasKey('suits_batch', $this->getJson('/api/v1/work-templates?source=farm')->json('data.0'));
    }

    public function test_a_general_routine_has_no_batch_and_creates_tasks_without_one(): void
    {
        $general = $this->postJson('/api/v1/work-templates', ['name' => 'Farm walk', 'applies_to' => 'farm', 'items' => [['title' => 'Walk the farm', 'category' => 'cleaning_sanitation']]])->assertCreated();
        $id = $general->assertJsonPath('data.scope', 'general')->assertJsonPath('data.scope_label', 'General (no batch)')->assertJsonPath('data.items.0.anchor', 'start_date')->json('data.id');

        $applied = $this->apply($id, [])->assertCreated()->json('data');
        $this->assertNull($applied['production_cycle_id']);
        $this->assertSame($this->today(), $applied['start_date']);
        $task = Task::firstOrFail();
        $this->assertNull($task->production_cycle_id);
        $this->assertSame($this->today(), $task->due_date->toDateString());

        $this->apply($id, ['production_cycle_id' => $this->cycle()], null)->assertStatus(422)->assertJsonValidationErrors('production_cycle_id');
        $this->routine(['applies_to' => 'farm', 'species_id' => \App\Models\Species::firstOrFail()->id])->assertStatus(422)->assertJsonValidationErrors('species_id');
        $this->routine(['applies_to' => 'farm'], ['anchor' => 'cycle_start'])->assertStatus(422)->assertJsonValidationErrors('items.0.anchor');
        $this->routine(['applies_to' => 'farm'], ['repeat' => ['pattern' => 'daily', 'ends' => ['type' => 'batch_end']]])->assertStatus(422);
        $this->routine(['applies_to' => 'farm'], ['linked_record_type' => 'general_note'])->assertStatus(422);
        $this->routine([], ['anchor' => 'breeding_start'])->assertStatus(422)->assertJsonValidationErrors('items.0.anchor');
    }

    // ------------------------------------------------------------------ steps

    public function test_a_step_uses_the_task_forms_repeat_ends_and_reminders(): void
    {
        $created = $this->routine([], ['title' => 'Weigh', 'offset_days' => 1, 'reminders' => ['1_day', 'on_the_day'],
            'repeat' => ['pattern' => 'weekly', 'every' => 1, 'weekdays' => [1, 3], 'ends' => ['type' => 'days', 'days' => 14]]])->assertCreated();
        $item = $created->json('data.items.0');
        $this->assertSame(['1_day', 'on_the_day'], $item['reminders']);
        $this->assertSame(['pattern' => 'weekly', 'every' => 1, 'weekdays' => [1, 3], 'ends' => ['type' => 'days', 'days' => 14]], $item['repeat']);
        $this->assertSame(14, $item['until_offset_days'], 'days 1..14 after offset 1: the last day is offset 14');

        $cycle = $this->cycle();
        $app = $this->apply($created->json('data.id'), ['production_cycle_id' => $cycle, 'start_date' => '2026-10-14'])->assertCreated()->json('data');
        $schedule = Schedule::where('template_application_id', $app['id'])->firstOrFail();
        $this->assertSame(['1_day', 'on_the_day'], $schedule->reminder_presets);
        $this->assertSame('2026-10-15', $schedule->starts_on->toDateString());
        $this->assertSame('2026-10-28', $schedule->ends_on->toDateString());
        $this->assertSame(['2026-10-19', '2026-10-21', '2026-10-26', '2026-10-28'], $this->dates($app['id']), 'Mondays and Wednesdays inside the window; the 15th is a Thursday');

        $every = $this->routine([], ['repeat' => ['pattern' => 'monthly', 'every' => 2, 'ends' => ['type' => 'date', 'date' => '2027-03-01']]])->assertCreated();
        $this->assertSame(['type' => 'date', 'date' => '2027-03-01'], $every->json('data.items.0.repeat.ends'));
        $batch = $this->routine([], ['repeat' => ['pattern' => 'daily', 'ends' => ['type' => 'batch_end']]])->assertCreated();
        $applied = $this->apply($batch->json('data.id'), ['production_cycle_id' => $cycle, 'start_date' => '2026-10-14'])->assertCreated()->json('data');
        $this->assertSame('batch_end', Schedule::where('template_application_id', $applied['id'])->firstOrFail()->ends_type);
        $this->assertCount(31, $this->dates($applied['id']));

        $two = ['reminders' => ['1_day'], 'repeat' => ['pattern' => 'weekly', 'weekdays' => [1, 3]]];
        $this->routine(['items' => [['title' => 'A', 'category' => 'other'] + $two, ['title' => 'B', 'category' => 'other'] + $two]])->assertCreated()->assertJsonPath('data.is_active', true)->assertJsonPath('data.items.1.reminders', ['1_day']);
        $this->routine([], ['repeat' => ['pattern' => 'daily', 'ends' => ['type' => 'days']]])->assertStatus(422);
        $this->routine([], ['reminders' => ['next_week']])->assertStatus(422)->assertJsonValidationErrors('items.0.reminders.0');
        $this->routine([], ['repeat' => ['pattern' => 'daily', 'weekdays' => [1]]])->assertStatus(422);
    }

    public function test_the_start_date_anchor_counts_day_one_as_offset_zero(): void
    {
        $id = $this->routine(['items' => [
            ['title' => 'Day one', 'category' => 'other', 'anchor' => 'start_date', 'offset_days' => 0],
            ['title' => 'Day three', 'category' => 'other', 'anchor' => 'start_date', 'offset_days' => 2],
        ]])->assertCreated()->json('data.id');
        $cycle = $this->cycle('chicken', ['start_date' => $this->day(-5)]);

        $app = $this->apply($id, ['production_cycle_id' => $cycle, 'start_date' => $this->day(3)])->assertCreated()->json('data');
        $this->assertSame($this->day(3), $app['start_date']);
        $this->assertSame([$this->day(3)], $this->dates($app['id'], 'Day one'));
        $this->assertSame([$this->day(5)], $this->dates($app['id'], 'Day three'));

        $default = $this->apply($id, ['production_cycle_id' => $cycle, 'confirm_reapply' => true])->assertCreated()->json('data');
        $this->assertSame($this->day(-5), $default['start_date'], 'a batch routine defaults to the batch start date');
        $this->assertSame(2, $default['skipped_past'], 'both steps fall before today');
        $this->assertSame([$this->day(-3)], [Schedule::where('template_application_id', $default['id'])->where('title', 'Day three')->firstOrFail()->starts_on->toDateString()]);
    }

    public function test_existing_templates_still_apply_exactly_as_before(): void
    {
        $cycle = $this->cycle('chicken', ['start_date' => $this->day(-2)]);
        $platform = WorkTemplate::whereNull('farm_id')->where('code', 'chicken-starter')->firstOrFail();
        $this->getJson('/api/v1/work-templates/'.$platform->id)->assertOk()->assertJsonPath('data.items.0.anchor', 'cycle_start')->assertJsonPath('data.scope_label', 'Chicken batches');
        $applied = $this->apply($platform->id, ['production_cycle_id' => $cycle, 'start_date' => $this->day(10)])->assertCreated()->json('data');
        $this->assertSame(5, $applied['schedules_created']);
        $this->assertSame($this->day(5), Schedule::where('template_application_id', $applied['id'])->where('title', 'Weigh a sample')->firstOrFail()->starts_on->toDateString(), 'cycle_start steps ignore start_date');
        $this->assertSame('active', $applied['status']);
    }

    // ------------------------------------------------------------------ save as routine

    public function test_save_a_single_task_as_a_routine(): void
    {
        $cycle = $this->cycle();
        $task = $this->task(['title' => 'Clean drinkers', 'category' => 'cleaning_sanitation', 'instructions' => 'Scrub', 'due_time' => '08:00', 'reminders' => ['1_day'], 'production_cycle_id' => $cycle])->assertCreated()->json('data.id');
        $routine = $this->postJson('/api/v1/tasks/'.$task.'/save-as-routine', ['name' => 'Drinker clean'])->assertCreated()->assertJsonPath('data.source', 'farm')->assertJsonPath('data.scope', 'any_batch')->json('data');
        $this->assertCount(1, $routine['items']);
        $item = $routine['items'][0];
        $this->assertSame(['Clean drinkers', 'cleaning_sanitation', 'Scrub', '08:00', ['1_day'], 'start_date', 0, null], [$item['title'], $item['category'], $item['instructions'], $item['due_time'], $item['reminders'], $item['anchor'], $item['offset_days'], $item['repeat']]);
        $this->assertSame(1, Task::count(), 'saving a routine creates no task');

        $same = $this->postJson('/api/v1/tasks/'.$task.'/save-as-routine', ['name' => 'Chickens', 'scope' => 'same_kind'])->assertCreated()->assertJsonPath('data.scope', 'specific')->json('data');
        $this->assertNotNull($same['species_id']);
        $loose = $this->task(['title' => 'Buy feed', 'category' => 'record_keeping'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/tasks/'.$loose.'/save-as-routine', ['name' => 'Buy feed'])->assertCreated()->assertJsonPath('data.scope', 'general');
        $this->postJson('/api/v1/tasks/'.$loose.'/save-as-routine', ['name' => 'Buy feed', 'scope' => 'any_batch'])->assertStatus(422)->assertJsonValidationErrors('scope');
        $this->postJson('/api/v1/tasks/'.$task.'/save-as-routine', [])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_save_a_series_as_a_routine_copies_the_repeat(): void
    {
        $cycle = $this->cycle();
        $series = $this->task(['title' => 'Weigh', 'category' => 'feeding_watering', 'production_cycle_id' => $cycle, 'reminders' => ['on_the_day', '2_days'],
            'repeat' => ['pattern' => 'weekly', 'every' => 2, 'weekdays' => [2, 4], 'ends' => ['type' => 'days', 'days' => 20]]])->assertCreated();
        $taskId = $series->json('data.tasks.1.id');
        $routine = $this->postJson('/api/v1/tasks/'.$taskId.'/save-as-routine', ['name' => 'Fortnightly weighing'])->assertCreated()->json('data');
        $item = $routine['items'][0];
        $this->assertSame(['pattern' => 'weekly', 'every' => 2, 'weekdays' => [2, 4], 'ends' => ['type' => 'days', 'days' => 20]], $item['repeat']);
        $this->assertSame(['on_the_day', '2_days'], $item['reminders']);
        $this->assertSame(0, $item['offset_days']);

        $ongoing = $this->task(['title' => 'Water', 'category' => 'feeding_watering', 'production_cycle_id' => $cycle, 'repeat' => ['pattern' => 'daily', 'ends' => ['type' => 'batch_end']]])->json('data.tasks.0.id');
        $this->postJson('/api/v1/tasks/'.$ongoing.'/save-as-routine', ['name' => 'Daily water'])->assertCreated()->assertJsonPath('data.items.0.repeat.ends.type', 'batch_end');
        $this->postJson('/api/v1/tasks/'.$ongoing.'/save-as-routine', ['name' => 'Daily water general', 'scope' => 'general'])->assertStatus(422);
    }

    public function test_a_batch_end_series_saves_as_a_routine_that_ends_with_the_batch_it_is_applied_to(): void
    {
        $cycle = $this->cycle('chicken', ['expected_end_date' => '2026-03-01']);
        $created = $this->task(['title' => 'Water', 'category' => 'feeding_watering', 'production_cycle_id' => $cycle, 'repeat' => ['pattern' => 'daily', 'ends' => ['type' => 'batch_end']]])->assertCreated();
        $taskId = $created->json('data.tasks.0.id');
        $scheduleId = Task::findOrFail($taskId)->schedule_id;
        // Whatever end date the series carries (the batch's expected end), the routine keeps "ends with the batch" alone.
        Schedule::whereKey($scheduleId)->update(['ends_on' => '2026-03-01']);

        $routine = $this->postJson('/api/v1/tasks/'.$taskId.'/save-as-routine', ['name' => 'Water until batch ends'])->assertCreated()->assertJsonPath('data.items.0.repeat.ends.type', 'batch_end')->json('data');
        $this->assertSame(['type' => 'batch_end'], $routine['items'][0]['repeat']['ends']);

        $other = $this->cycle('chicken', ['expected_end_date' => '2026-06-01']);
        $applied = $this->apply($routine['id'], ['production_cycle_id' => $other])->assertCreated()->json('data');
        $schedule = Schedule::where('template_application_id', $applied['id'])->firstOrFail();
        $this->assertSame('batch_end', $schedule->ends_type);
        $this->assertNull($schedule->occurrence_limit);
        $this->assertSame($other, $schedule->production_cycle_id);
        $this->assertSame('active', $schedule->status);
    }

    // ------------------------------------------------------------------ preview

    public function test_preview_lists_the_jobs_and_saves_nothing(): void
    {
        $id = $this->routine(['items' => [
            ['title' => 'Start', 'category' => 'other', 'anchor' => 'start_date', 'offset_days' => 0],
            ['title' => 'Daily check', 'category' => 'other', 'anchor' => 'start_date', 'offset_days' => 1, 'repeat' => ['pattern' => 'daily', 'ends' => ['type' => 'never']]],
            ['title' => 'Short run', 'category' => 'other', 'anchor' => 'start_date', 'offset_days' => 0, 'repeat' => ['pattern' => 'daily', 'ends' => ['type' => 'days', 'days' => 3]]],
        ]])->assertCreated()->json('data.id');
        $cycle = $this->cycle();
        $before = $this->counts();

        $p = $this->postJson('/api/v1/work-templates/'.$id.'/preview', ['target' => $cycle, 'start_date' => $this->day(2)])->assertOk()->json('data');
        $this->assertSame($before, $this->counts());
        $this->assertSame($this->day(2), $p['start_date']);
        $this->assertSame(['from' => $this->today(), 'to' => $this->day(30)], $p['window']);
        $this->assertSame(1 + 28 + 3, $p['counts']['jobs_in_window'], 'one-off + daily from day 3 to day 30 + three days');
        $this->assertSame(3, $p['counts']['schedules']);
        $this->assertSame(1, $p['counts']['coming_repeats'], 'only the open-ended daily check continues past the window; the short run is finished');
        $this->assertSame('Daily check', $p['coming_repeats'][0]['title']);
        $this->assertSame($this->day(31), $p['coming_repeats'][0]['next_date']);
        $this->assertNull($p['coming_repeats'][0]['remaining_after_window']);
        $this->assertSame($this->day(2), $p['jobs'][0]['due_date']);
        $this->assertSame(0, $p['times_already_applied']);

        $this->postJson('/api/v1/work-templates/'.$id.'/preview', ['start_date' => $this->day(2)])->assertStatus(422)->assertJsonValidationErrors('production_cycle_id');
        $this->closeCycle($cycle);
        $this->postJson('/api/v1/work-templates/'.$id.'/preview', ['target' => $cycle])->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
        $this->assertSame($before[2], TemplateApplication::count());
    }

    public function test_preview_of_a_general_routine_needs_no_batch(): void
    {
        $id = $this->routine(['applies_to' => 'farm'], ['title' => 'Fence check', 'category' => 'cleaning_sanitation', 'repeat' => ['pattern' => 'weekly', 'weekdays' => [5], 'ends' => ['type' => 'days', 'days' => 90]]])->assertCreated()->json('data.id');
        $p = $this->postJson('/api/v1/work-templates/'.$id.'/preview', ['start_date' => $this->today()])->assertOk()->json('data');
        $this->assertTrue($p['target']['general']);
        $this->assertSame(['2026-10-16', '2026-10-23', '2026-10-30', '2026-11-06', '2026-11-13'], array_column($p['jobs'], 'due_date'));
        $this->assertSame(1, $p['counts']['coming_repeats']);
        $this->assertGreaterThan(0, $p['coming_repeats'][0]['remaining_after_window']);
        $this->assertSame(0, Task::count());
    }

    // ------------------------------------------------------------------ re-apply

    public function test_applying_again_needs_confirmation_and_keeps_idempotency(): void
    {
        $id = $this->routine()->assertCreated()->json('data.id');
        $cycle = $this->cycle();
        $key = (string) Str::uuid();
        $first = $this->apply($id, ['production_cycle_id' => $cycle], $key)->assertCreated()->json('data');

        $conflict = $this->apply($id, ['production_cycle_id' => $cycle])->assertStatus(409)->assertJsonPath('code', 'template_already_applied');
        $conflict->assertJsonPath('details.times_applied', 1)->assertJsonPath('details.applications.0.id', $first['id']);
        $this->assertNotNull($conflict->json('details.last_applied_at'));
        $this->assertSame(1, TemplateApplication::count());

        $second = $this->apply($id, ['production_cycle_id' => $cycle, 'confirm_reapply' => true])->assertCreated()->json('data');
        $this->assertNotSame($first['id'], $second['id']);
        $this->apply($id, ['production_cycle_id' => $cycle])->assertStatus(409)->assertJsonPath('details.times_applied', 2);

        $this->assertSame($first, $this->apply($id, ['production_cycle_id' => $cycle], $key)->assertCreated()->json('data'), 'replaying the first key returns the first application');
        $this->assertSame(2, TemplateApplication::count());
        $this->apply($id, ['production_cycle_id' => $this->cycle()], $key)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');

        $general = $this->routine(['applies_to' => 'farm'])->json('data.id');
        $this->apply($general)->assertCreated();
        $this->apply($general)->assertStatus(409)->assertJsonPath('code', 'template_already_applied');
    }

    // ------------------------------------------------------------------ remove

    public function test_removing_a_routine_cancels_open_future_tasks_and_keeps_completed_ones(): void
    {
        $id = $this->routine([], ['repeat' => ['pattern' => 'daily', 'ends' => ['type' => 'never']]])->assertCreated()->json('data.id');
        $cycle = $this->cycle();
        $app = $this->apply($id, ['production_cycle_id' => $cycle, 'start_date' => $this->day(-2)])->assertCreated()->json('data');
        $this->assertSame(31, $app['tasks_created']);
        $schedule = Schedule::where('template_application_id', $app['id'])->firstOrFail();
        $doneTask = Task::where('schedule_id', $schedule->id)->where('due_date', $this->today())->firstOrFail();
        $this->postJson('/api/v1/tasks/'.$doneTask->id.'/complete', [])->assertOk();
        $before = Task::count();

        $list = $this->getJson('/api/v1/template-applications?production_cycle_id='.$cycle)->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame(['open' => 30, 'completed' => 1, 'cancelled' => 0], $list[0]['task_counts']);
        $this->assertSame('My routine', $list[0]['template']['name']);
        $this->assertSame($this->day(-2), $list[0]['start_date']);

        $removed = $this->postJson('/api/v1/template-applications/'.$app['id'].'/remove')->assertOk()->json('data');
        $this->assertSame('removed', $removed['status']);
        $this->assertSame(['open' => 0, 'completed' => 1, 'cancelled' => 30], $removed['task_counts']);
        $this->assertSame(30, $removed['tasks_cancelled']);
        $this->assertSame($before, Task::count(), 'nothing is deleted');
        $this->assertSame('ended', $schedule->fresh()->status);
        $this->assertSame('completed', $doneTask->fresh()->status->value);
        $this->assertSame(30, Task::where('schedule_id', $schedule->id)->where('status', 'cancelled')->where('cancel_reason', 'Routine removed')->count());

        $again = $this->postJson('/api/v1/template-applications/'.$app['id'].'/remove')->assertOk()->json('data');
        $this->assertSame($removed['removed_at'], $again['removed_at']);
        $this->assertSame($removed['task_counts'], $again['task_counts']);
        $this->assertSame([], $this->getJson('/api/v1/template-applications?production_cycle_id='.$cycle)->json('data'), 'removed routines leave the default list');
        $this->assertCount(1, $this->getJson('/api/v1/template-applications?production_cycle_id='.$cycle.'&status=removed')->json('data'));
        $this->apply($id, ['production_cycle_id' => $cycle])->assertCreated();
        $this->postJson('/api/v1/template-applications/'.Str::uuid().'/remove')->assertNotFound();
    }
}
