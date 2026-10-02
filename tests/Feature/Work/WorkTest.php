<?php

namespace Tests\Feature\Work;

use App\Enums\FarmRole;
use App\Models\BreedingProject;
use App\Models\HealthRecord;
use App\Models\InventoryMovement;
use App\Models\OperationalRecord;
use App\Models\OperationType;
use App\Models\PopulationMovement;
use App\Models\Schedule;
use App\Models\Species;
use App\Models\Task;
use App\Models\WorkTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\Team\TeamTestCase;

class WorkTest extends TeamTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->onPlan('farm-pro');
        $this->signInAs($this->owner);
    }

    // ------------------------------------------------------------------ helpers

    private function today(): string
    {
        return CarbonImmutable::now('Africa/Lagos')->toDateString();
    }

    private function day(int $offset): string
    {
        return CarbonImmutable::parse($this->today(), 'UTC')->addDays($offset)->toDateString();
    }

    private function at(int $hoursAgo = 1): string
    {
        return now()->utc()->subHours($hoursAgo)->format('Y-m-d\TH:i:s\Z');
    }

    private function cycle(string $species = 'chicken', array $extra = []): string
    {
        $operation = Species::where('code', $species)->firstOrFail()->operationType->code;

        return $this->postJson('/api/v1/production-cycles', array_replace(['kind' => 'livestock', 'name' => ucfirst($species).' '.Str::random(4), 'operation_type_id' => OperationType::where('code', $operation)->firstOrFail()->id,
            'species_id' => Species::where('code', $species)->firstOrFail()->id, 'initial_population' => 100, 'start_date' => '2026-01-01'], $extra))->assertCreated()->json('data.id');
    }

    private function payload(array $extra = []): array
    {
        return array_replace(['title' => 'Check drinkers', 'category' => 'feeding_watering', 'due_date' => $this->today(), 'idempotency_key' => (string) Str::uuid()], $extra);
    }

    private function task(array $extra = [])
    {
        return $this->postJson('/api/v1/tasks', $this->payload($extra));
    }

    private function make(array $extra = []): array
    {
        return $this->task($extra)->assertCreated()->json('data');
    }

    private function complete(string $task, array $extra = [])
    {
        return $this->postJson('/api/v1/tasks/'.$task.'/complete', $extra);
    }

    private function note(string $cycle, string $type = 'general_note'): array
    {
        return $this->postJson('/api/v1/records', ['production_cycle_id' => $cycle, 'type' => $type, 'recorded_at' => $this->at(2), 'idempotency_key' => (string) Str::uuid(),
            'details' => ['text' => 'Observed']])->assertCreated()->json('data');
    }

    private function schedule(array $extra = [])
    {
        return $this->postJson('/api/v1/schedules', array_replace(['title' => 'Feed', 'category' => 'feeding_watering', 'recurrence' => 'daily', 'starts_on' => $this->today(), 'idempotency_key' => (string) Str::uuid()], $extra));
    }

    private function project(string $cycle, array $extra = [])
    {
        return $this->postJson('/api/v1/breeding-projects', array_replace(['production_cycle_id' => $cycle, 'workflow' => 'incubation', 'start_date' => $this->day(-3), 'eggs_set' => 50, 'idempotency_key' => (string) Str::uuid()], $extra));
    }

    private function apply(string $template, array $target, ?string $key = null)
    {
        return $this->postJson('/api/v1/work-templates/'.$template.'/apply', $target + ['idempotency_key' => $key ?? (string) Str::uuid()]);
    }

    private function platform(string $code): string
    {
        return WorkTemplate::whereNull('farm_id')->where('code', $code)->firstOrFail()->id;
    }

    private function records(): array
    {
        return [OperationalRecord::count(), HealthRecord::count(), PopulationMovement::count(), InventoryMovement::count()];
    }

    private function closeCycle(string $cycle): void
    {
        $this->postJson('/api/v1/production-cycles/'.$cycle.'/close', ['end_date' => $this->today(), 'reason' => 'Done'])->assertOk();
    }

    // ------------------------------------------------------------------ task basics & lifecycle

    public function test_create_standalone_and_cycle_task_without_any_operational_record(): void
    {
        $cycle = $this->cycle();
        $before = $this->records();
        $standalone = $this->make();
        $linked = $this->make(['production_cycle_id' => $cycle, 'linked_record_type' => 'feed_use', 'due_time' => '06:00']);
        $this->assertMatchesRegularExpression('/^TSK-\d{4}-00001$/', $standalone['reference']);
        $this->assertSame('open', $standalone['status']);
        $this->assertSame($cycle, $linked['production_cycle_id']);
        $this->assertSame('06:00', $linked['due_time']);
        $this->assertSame('Africa/Lagos', $linked['timezone']);
        $this->assertSame($before, $this->records(), 'creating tasks must not create actual records');
        $this->assertTrue(Str::isUuid($standalone['id']));
    }

    public function test_due_state_is_derived_and_respects_the_farm_timezone_boundary(): void
    {
        // 22:59 UTC = 23:59 in Lagos on 2 Oct; 23:00 UTC = 00:00 on 3 Oct.
        $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00 UTC'));
        $dayTask = $this->make(['due_date' => '2026-10-02', 'title' => 'Day task']);
        $timed = $this->make(['due_date' => '2026-10-03', 'due_time' => '06:00', 'title' => 'Timed']);
        $future = $this->make(['due_date' => '2026-10-04', 'title' => 'Future']);
        $state = fn (string $id) => $this->getJson('/api/v1/tasks/'.$id)->assertOk()->json('data.due_state');

        $this->assertSame('due_today', $state($dayTask['id']));
        $this->assertSame('upcoming', $state($timed['id']));
        $this->travelTo(CarbonImmutable::parse('2026-10-02 22:59:00 UTC'));
        $this->assertSame('due_today', $state($dayTask['id']));
        $this->travelTo(CarbonImmutable::parse('2026-10-02 23:00:00 UTC'));
        $this->assertSame('overdue', $state($dayTask['id']));
        $this->assertSame('due_today', $state($timed['id']));
        $this->assertSame('upcoming', $state($future['id']));
        $this->travelTo(CarbonImmutable::parse('2026-10-03 04:59:00 UTC'));
        $this->assertSame('due_today', $state($timed['id']));
        $this->travelTo(CarbonImmutable::parse('2026-10-03 05:00:00 UTC'));
        $this->assertSame('overdue', $state($timed['id']));

        $this->assertSame([$dayTask['id'], $timed['id']], collect($this->getJson('/api/v1/tasks?due_state=overdue')->json('data'))->pluck('id')->all());
        $this->assertSame([$future['id']], collect($this->getJson('/api/v1/tasks?due_state=upcoming')->json('data'))->pluck('id')->all());
        $this->assertSame([], $this->getJson('/api/v1/tasks?due_state=due_today')->json('data'));
        $this->assertNotContains('due_state', array_keys(Task::first()->getAttributes()), 'due state is never stored');
    }

    public function test_completed_and_cancelled_states_win_over_overdue_and_are_final(): void
    {
        $done = $this->make(['due_date' => $this->day(-5)]);
        $cancelled = $this->make(['due_date' => $this->day(-5)]);
        $this->assertSame('overdue', $this->getJson('/api/v1/tasks/'.$done['id'])->json('data.due_state'));
        $this->complete($done['id'], ['note' => 'Done late'])->assertOk()->assertJsonPath('data.due_state', 'completed')->assertJsonPath('data.completion.note', 'Done late')->assertJsonPath('data.completion.evidence', null);
        $this->postJson('/api/v1/tasks/'.$cancelled['id'].'/cancel', ['reason' => 'No longer needed'])->assertOk()->assertJsonPath('data.due_state', 'cancelled')->assertJsonPath('data.cancellation.reason', 'No longer needed');
        $this->patchJson('/api/v1/tasks/'.$done['id'], ['title' => 'x'])->assertStatus(409)->assertJsonPath('code', 'task_not_open');
        $this->postJson('/api/v1/tasks/'.$cancelled['id'].'/cancel', ['reason' => 'again'])->assertStatus(409)->assertJsonPath('code', 'task_not_open');
        $this->complete($cancelled['id'])->assertStatus(409)->assertJsonPath('code', 'task_not_open');
        $this->getJson('/api/v1/tasks?due_state=completed')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/tasks?status=cancelled')->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/tasks/'.$done['id'].'/cancel', ['reason' => 'x'])->assertStatus(409);
    }

    public function test_update_reschedules_and_recomputes_due_at(): void
    {
        $task = $this->make(['due_date' => $this->day(-2)]);
        $this->assertSame('overdue', $task['due_state']);
        $updated = $this->patchJson('/api/v1/tasks/'.$task['id'], ['due_date' => $this->day(3), 'due_time' => '07:30', 'title' => 'Moved'])->assertOk()->json('data');
        $this->assertSame('upcoming', $updated['due_state']);
        $this->assertSame('Moved', $updated['title']);
        $expected = CarbonImmutable::parse($this->day(3).' 07:30', 'Africa/Lagos')->utc()->toISOString();
        $this->assertSame($expected, $updated['due_at']);
        $this->patchJson('/api/v1/tasks/'.$task['id'], ['production_cycle_id' => (string) Str::uuid()])->assertStatus(422);
        $this->patchJson('/api/v1/tasks/'.$task['id'], ['status' => 'completed'])->assertStatus(422);
    }

    public function test_task_identity_is_immutable_and_never_deleted(): void
    {
        $task = Task::findOrFail($this->make()['id']);
        $this->expectException(LogicException::class);
        try {
            $task->update(['reference' => 'TSK-X']);
        } finally {
            $this->expectException(LogicException::class);
            $task->delete();
        }
    }

    public function test_categories_master_endpoint_and_no_missed_state(): void
    {
        $codes = collect($this->getJson('/api/v1/master/task-categories')->assertOk()->json('data'))->pluck('code');
        $this->assertContains('vaccination_medication', $codes);
        $this->assertContains('payment', $codes);
        $this->assertCount(15, $codes);
        $this->getJson('/api/v1/tasks?due_state=missed')->assertStatus(422);
        $this->task(['category' => 'invalid'])->assertStatus(422)->assertJsonValidationErrors('category');
    }

    // ------------------------------------------------------------------ completion vs actual records

    public function test_completion_without_evidence_creates_no_fake_record_even_for_a_record_linked_task(): void
    {
        $cycle = $this->cycle();
        $task = $this->make(['production_cycle_id' => $cycle, 'linked_record_type' => 'mortality', 'title' => 'Count losses']);
        $before = $this->records();
        $this->complete($task['id'])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame($before, $this->records(), 'a plain completion creates no operational, health, population or inventory effect');
        $this->assertSame(100, $this->getJson('/api/v1/production-cycles/'.$cycle)->json('data.livestock.current_population'));
    }

    public function test_record_prefill_is_read_only_then_evidence_completion_links_the_saved_record(): void
    {
        $cycle = $this->cycle();
        $task = $this->make(['production_cycle_id' => $cycle, 'linked_record_type' => 'general_note', 'requires_evidence' => true]);
        $before = $this->records();
        $prefill = $this->getJson('/api/v1/tasks/'.$task['id'].'/record-prefill')->assertOk()->json('data');
        $this->assertSame($before, $this->records());
        $this->assertSame('/api/v1/records', $prefill['endpoint']);
        $this->assertSame($cycle, $prefill['prefill']['production_cycle_id']);
        $this->assertSame('general_note', $prefill['prefill']['type']);
        $this->assertSame($this->today(), $prefill['prefill']['date']);

        $this->complete($task['id'])->assertStatus(422)->assertJsonValidationErrors('evidence');
        $this->assertSame('open', $this->getJson('/api/v1/tasks/'.$task['id'])->json('data.status'));

        $record = $this->note($cycle);
        $payload = ['evidence' => ['type' => 'operational_record', 'id' => $record['id']], 'note' => 'Saved'];
        $first = $this->complete($task['id'], $payload)->assertOk()->json('data');
        $this->assertSame(['type' => 'operational_record', 'id' => $record['id']], $first['completion']['evidence']);
        $this->assertSame($first, $this->complete($task['id'], $payload)->assertOk()->json('data'), 'identical retry returns the original');
        $other = $this->note($cycle);
        $this->complete($task['id'], ['evidence' => ['type' => 'operational_record', 'id' => $other['id']]])->assertStatus(409)->assertJsonPath('code', 'task_already_completed');
        $this->assertSame(2, OperationalRecord::count(), 'linking creates nothing');
    }

    public function test_evidence_must_match_type_cycle_state_and_be_used_once(): void
    {
        $cycle = $this->cycle('chicken');
        $otherCycle = $this->cycle('chicken');
        $task = $this->make(['production_cycle_id' => $cycle, 'linked_record_type' => 'general_note']);
        $link = fn (string $id, string $type = 'operational_record') => ['evidence' => ['type' => $type, 'id' => $id]];

        $wrongCycle = $this->note($otherCycle);
        $this->complete($task['id'], $link($wrongCycle['id']))->assertStatus(422)->assertJsonValidationErrors('evidence.id');
        $mortality = $this->postJson('/api/v1/records', ['production_cycle_id' => $cycle, 'type' => 'mortality', 'recorded_at' => $this->at(2), 'idempotency_key' => (string) Str::uuid(), 'details' => ['quantity' => 2, 'cause' => 'x']])->assertCreated()->json('data');
        $this->complete($task['id'], $link($mortality['id']))->assertStatus(422)->assertJsonValidationErrors('evidence.id');
        $this->complete($task['id'], $link($mortality['id'], 'health_record'))->assertStatus(422)->assertJsonValidationErrors('evidence.type');
        $this->complete($task['id'], $link((string) Str::uuid()))->assertStatus(422)->assertJsonValidationErrors('evidence.id');

        $reversed = $this->note($cycle);
        $this->postJson('/api/v1/records/'.$reversed['id'].'/reverse', ['reason' => 'Wrong', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $this->complete($task['id'], $link($reversed['id']))->assertStatus(422)->assertJsonValidationErrors('evidence.id');

        $good = $this->note($cycle);
        $this->complete($task['id'], $link($good['id']))->assertOk();
        $second = $this->make(['production_cycle_id' => $cycle, 'linked_record_type' => 'general_note']);
        $this->complete($second['id'], $link($good['id']))->assertStatus(409)->assertJsonPath('code', 'evidence_already_linked');
    }

    public function test_health_record_can_evidence_a_task_and_foreign_farm_records_are_rejected(): void
    {
        $cycle = $this->cycle();
        $task = $this->make(['production_cycle_id' => $cycle, 'linked_record_type' => 'health']);
        $visit = $this->postJson('/api/v1/health-records', ['production_cycle_id' => $cycle, 'type' => 'vet_visit', 'details' => ['vet_name' => 'Dr Ade'], 'recorded_at' => $this->at(2), 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->assertSame('/api/v1/health-records', $this->getJson('/api/v1/tasks/'.$task['id'].'/record-prefill')->json('data.endpoint'));
        $this->complete($task['id'], ['evidence' => ['type' => 'health_record', 'id' => $visit['id']]])->assertOk()->assertJsonPath('data.completion.evidence.type', 'health_record');

        [$otherOwner, $otherFarm] = $this->otherFarm();
        $this->onPlan('farm-pro', $otherFarm);
        $this->signInAs($otherOwner);
        $foreignCycle = $this->cycle();
        $foreignNote = $this->note($foreignCycle);
        $this->signInAs($this->owner);
        $again = $this->make(['production_cycle_id' => $cycle, 'linked_record_type' => 'general_note']);
        $this->complete($again['id'], ['evidence' => ['type' => 'operational_record', 'id' => $foreignNote['id']]])->assertStatus(422)->assertJsonValidationErrors('evidence.id');
    }

    public function test_prefill_requires_a_linked_record_type(): void
    {
        $task = $this->make();
        $this->getJson('/api/v1/tasks/'.$task['id'].'/record-prefill')->assertStatus(409)->assertJsonPath('code', 'task_has_no_linked_record');
        $this->task(['linked_record_type' => 'feed_use'])->assertStatus(422)->assertJsonValidationErrors('linked_record_type');
        $cycle = $this->cycle();
        $this->task(['production_cycle_id' => $cycle, 'linked_record_type' => 'irrigation'])->assertStatus(422)->assertJsonValidationErrors('linked_record_type');
        $this->task(['production_cycle_id' => $cycle, 'linked_record_type' => 'breeding_check'])->assertStatus(422)->assertJsonValidationErrors('linked_record_type');
    }

    // ------------------------------------------------------------------ recurrence

    public function test_daily_schedule_generates_a_rolling_horizon_of_tasks_only(): void
    {
        $cycle = $this->cycle();
        $before = $this->records();
        $schedule = $this->schedule(['production_cycle_id' => $cycle, 'due_time' => '06:00', 'linked_record_type' => 'feed_use'])->assertCreated()->json('data');
        $this->assertSame(31, $schedule['tasks_count']);
        $this->assertSame('active', $schedule['status']);
        $this->assertSame(31, Task::where('schedule_id', $schedule['id'])->count());
        $this->assertSame($this->day(30), Task::where('schedule_id', $schedule['id'])->max('occurrence_date'));
        $this->assertSame($before, $this->records());
        $first = Task::where('schedule_id', $schedule['id'])->orderBy('occurrence_date')->first();
        $this->assertSame($this->today(), $first->due_date->toDateString());
        $this->assertSame('feed_use', $first->linked_record_type);
    }

    public function test_generation_is_deduplicated_and_tops_up_as_days_pass(): void
    {
        $schedule = $this->schedule()->assertCreated()->json('data');
        Artisan::call('work:generate-tasks');
        Artisan::call('work:generate-tasks');
        $this->assertSame(31, Task::where('schedule_id', $schedule['id'])->count(), 'rerunning never duplicates');
        $this->travelTo(now()->addDays(10));
        Artisan::call('work:generate-tasks');
        Artisan::call('work:generate-tasks');
        $this->assertSame(41, Task::where('schedule_id', $schedule['id'])->count());
        $this->assertSame(41, Task::where('schedule_id', $schedule['id'])->distinct()->count('occurrence_date'));
    }

    public function test_weekly_biweekly_limit_end_date_and_one_off_recurrence(): void
    {
        $monday = CarbonImmutable::parse($this->today(), 'UTC')->next('monday')->toDateString();
        $weekly = $this->schedule(['recurrence' => 'weekly', 'weekdays' => [1, 4], 'starts_on' => $monday, 'ends_on' => CarbonImmutable::parse($monday)->addDays(13)->toDateString()])->assertCreated()->json('data');
        $dates = Task::where('schedule_id', $weekly['id'])->orderBy('occurrence_date')->get()->map(fn ($t) => $t->occurrence_date->toDateString())->all();
        $this->assertSame([$monday, CarbonImmutable::parse($monday)->addDays(3)->toDateString(), CarbonImmutable::parse($monday)->addDays(7)->toDateString(), CarbonImmutable::parse($monday)->addDays(10)->toDateString()], $dates);
        $this->assertSame('ended', $weekly['status'] === 'active' ? $this->getJson('/api/v1/schedules/'.$weekly['id'])->json('data.status') : 'ended', 'a bounded schedule ends once fully generated');

        $biweekly = $this->schedule(['recurrence' => 'weekly', 'interval_value' => 2, 'starts_on' => $this->today(), 'occurrence_limit' => 3])->assertCreated()->json('data');
        $this->assertSame(3, $biweekly['tasks_count']);
        $dates = Task::where('schedule_id', $biweekly['id'])->orderBy('occurrence_date')->pluck('occurrence_date')->map->toDateString()->all();
        $this->assertSame([$this->day(0), $this->day(14), $this->day(28)], $dates);

        $every3 = $this->schedule(['interval_value' => 3, 'occurrence_limit' => 4])->assertCreated()->json('data');
        $this->assertSame([0, 3, 6, 9], Task::where('schedule_id', $every3['id'])->orderBy('occurrence_date')->get()->map(fn ($t) => (int) CarbonImmutable::parse($this->today())->diffInDays($t->occurrence_date))->all());

        $once = $this->schedule(['recurrence' => 'none', 'starts_on' => $this->day(2)])->assertCreated()->json('data');
        $this->assertSame(1, $once['tasks_count']);
        $this->schedule(['recurrence' => 'none', 'ends_on' => $this->day(5)])->assertStatus(422);
        $this->schedule(['recurrence' => 'daily', 'weekdays' => [1]])->assertStatus(422)->assertJsonValidationErrors('weekdays');
        $this->schedule(['recurrence' => 'monthly'])->assertStatus(422)->assertJsonValidationErrors('recurrence');
    }

    public function test_past_dates_are_never_generated_as_overdue_noise(): void
    {
        $schedule = $this->schedule(['starts_on' => $this->day(-10), 'ends_on' => $this->day(2)])->assertCreated()->json('data');
        $this->assertSame(3, $schedule['tasks_count']);
        $this->assertSame(0, Task::where('schedule_id', $schedule['id'])->where('due_date', '<', $this->today())->count());
    }

    public function test_ending_a_schedule_stops_generation_and_optionally_cancels_future_tasks(): void
    {
        $schedule = $this->schedule()->assertCreated()->json('data');
        $keep = Task::where('schedule_id', $schedule['id'])->where('due_date', $this->today())->first();
        $this->complete($keep->id)->assertOk();
        $ended = $this->postJson('/api/v1/schedules/'.$schedule['id'].'/end', ['cancel_future_tasks' => true])->assertOk()->json('data');
        $this->assertSame('ended', $ended['status']);
        $this->assertSame(0, Task::where('schedule_id', $schedule['id'])->where('status', 'open')->where('due_date', '>', $this->today())->count());
        $this->assertSame('completed', $keep->fresh()->status->value);
        $this->postJson('/api/v1/schedules/'.$schedule['id'].'/end')->assertOk();
        $before = Task::count();
        $this->travelTo(now()->addDays(20));
        Artisan::call('work:generate-tasks');
        $this->assertSame($before, Task::count());
    }

    // ------------------------------------------------------------------ templates

    public function test_platform_templates_are_read_only_clonable_and_versioned(): void
    {
        $platform = $this->platform('chicken-starter');
        $this->getJson('/api/v1/work-templates/'.$platform)->assertOk()->assertJsonPath('data.source', 'platform')->assertJsonPath('data.version', 1)->assertJsonCount(5, 'data.items');
        $this->patchJson('/api/v1/work-templates/'.$platform, ['name' => 'Hacked'])->assertForbidden()->assertJsonPath('code', 'platform_template_readonly');

        $copy = $this->postJson('/api/v1/work-templates/'.$platform.'/clone', ['name' => 'My broilers'])->assertCreated()->json('data');
        $this->assertSame('farm', $copy['source']);
        $this->assertSame($platform, $copy['cloned_from_id']);
        $this->assertCount(5, $copy['items']);

        $cycle = $this->cycle('chicken');
        $applied = $this->apply($copy['id'], ['production_cycle_id' => $cycle])->assertCreated()->json('data');
        $this->assertSame(1, $applied['template_version']);
        $schedulesBefore = Schedule::where('template_application_id', $applied['id'])->get()->map(fn ($s) => [$s->title, $s->recurrence->value, $s->starts_on->toDateString()])->all();

        $updated = $this->patchJson('/api/v1/work-templates/'.$copy['id'], ['items' => [['title' => 'Only one', 'category' => 'other', 'anchor' => 'cycle_start']]])->assertOk()->json('data');
        $this->assertSame(2, $updated['version']);
        $this->assertCount(1, $updated['items']);
        $this->assertSame($schedulesBefore, Schedule::where('template_application_id', $applied['id'])->get()->map(fn ($s) => [$s->title, $s->recurrence->value, $s->starts_on->toDateString()])->all(), 'applied schedules are independent copies');
        $this->assertSame(5, Schedule::where('template_application_id', $applied['id'])->count());
        $this->assertSame(5, WorkTemplate::whereNull('farm_id')->where('code', 'chicken-starter')->firstOrFail()->items()->count(), 'platform template untouched');
    }

    public function test_apply_template_materialises_tasks_relative_to_the_cycle_without_records(): void
    {
        $cycle = $this->cycle('chicken', ['start_date' => $this->day(-2)]);
        $recommended = $this->getJson('/api/v1/work-templates/recommended?production_cycle_id='.$cycle)->assertOk()->json('data');
        $this->assertSame(['chicken-starter'], collect($recommended)->pluck('code')->all(), 'only fitting templates are recommended (not crop or breeding)');
        $this->assertNull($recommended[0]['application_id']);

        $before = $this->records();
        $result = $this->apply($platform = $this->platform('chicken-starter'), ['production_cycle_id' => $cycle])->assertCreated()->json('data');
        $this->assertSame($before, $this->records());
        $this->assertSame(5, $result['schedules_created']);
        $this->assertGreaterThan(0, $result['tasks_created']);
        $this->assertGreaterThan(0, $result['skipped_past'], 'day 0 and the first early-care days were already in the past');
        $setup = Schedule::where('template_application_id', $result['id'])->where('title', 'Placement and setup check')->firstOrFail();
        $this->assertSame($this->day(-2), $setup->starts_on->toDateString());
        $this->assertSame(0, $setup->tasks()->count());
        $feeding = Schedule::where('template_application_id', $result['id'])->where('title', 'Feeding')->firstOrFail();
        $this->assertSame('06:00', substr($feeding->due_time, 0, 5));
        $this->assertSame('feed_use', $feeding->linked_record_type);
        $weigh = Schedule::where('template_application_id', $result['id'])->where('title', 'Weigh a sample')->firstOrFail();
        $this->assertSame($this->day(5), $weigh->starts_on->toDateString(), 'offset 7 from the cycle start');

        $this->apply($platform, ['production_cycle_id' => $cycle])->assertStatus(409)->assertJsonPath('code', 'template_already_applied');
        $this->assertSame($platform, $this->getJson('/api/v1/work-templates/recommended?production_cycle_id='.$cycle)->json('data.0.id'));
        $this->assertNotNull($this->getJson('/api/v1/work-templates/recommended?production_cycle_id='.$cycle)->json('data.0.application_id'));
    }

    public function test_apply_is_idempotent_and_validates_the_target(): void
    {
        $cycle = $this->cycle('chicken');
        $template = $this->platform('chicken-starter');
        $key = (string) Str::uuid();
        $first = $this->apply($template, ['production_cycle_id' => $cycle], $key)->assertCreated()->json('data');
        $tasks = Task::count();
        $this->assertSame($first, $this->apply($template, ['production_cycle_id' => $cycle], $key)->assertCreated()->json('data'));
        $this->assertSame($tasks, Task::count());
        $this->apply($template, ['production_cycle_id' => $this->cycle('goat')], $key)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');

        $this->apply($template, [])->assertStatus(422)->assertJsonValidationErrors('production_cycle_id');
        $this->apply($this->platform('chicken-incubation'), ['production_cycle_id' => $cycle])->assertStatus(422)->assertJsonValidationErrors('breeding_project_id');
        $this->apply($this->platform('crop-starter'), ['production_cycle_id' => $this->cycle('chicken')])->assertStatus(422)->assertJsonValidationErrors('production_cycle_id');
        $this->apply($this->platform('chicken-starter'), ['production_cycle_id' => $this->cycle('goat')])->assertStatus(422)->assertJsonValidationErrors('production_cycle_id');
        $this->getJson('/api/v1/work-templates/recommended')->assertStatus(422);
    }

    public function test_custom_template_validation_and_deactivation(): void
    {
        $base = ['name' => 'Mine', 'applies_to' => 'production_cycle', 'items' => [['title' => 'Walk', 'category' => 'crop_care', 'anchor' => 'cycle_start', 'offset_days' => 1]]];
        $created = $this->postJson('/api/v1/work-templates', $base)->assertCreated()->json('data');
        $this->assertSame(1, $created['version']);
        $this->postJson('/api/v1/work-templates', array_replace($base, ['items' => [['title' => 'x', 'category' => 'other', 'anchor' => 'breeding_start']]]))->assertStatus(422)->assertJsonValidationErrors('items.0.anchor');
        $this->postJson('/api/v1/work-templates', array_replace($base, ['breeding_workflow' => 'incubation']))->assertStatus(422);
        $this->postJson('/api/v1/work-templates', array_replace($base, ['items' => [['title' => 'x', 'category' => 'other', 'anchor' => 'cycle_start', 'offset_days' => 5, 'recurrence' => 'daily', 'until_offset_days' => 2]]]))->assertStatus(422);
        $this->postJson('/api/v1/work-templates', array_replace($base, ['items' => [['title' => 'x', 'category' => 'other', 'anchor' => 'cycle_start', 'weekdays' => [1]]]]))->assertStatus(422);
        $this->patchJson('/api/v1/work-templates/'.$created['id'], ['is_active' => false])->assertOk()->assertJsonPath('data.version', 2);
        $this->apply($created['id'], ['production_cycle_id' => $this->cycle()])->assertStatus(409)->assertJsonPath('code', 'template_inactive');
        $this->assertSame([], collect($this->getJson('/api/v1/work-templates?source=farm&is_active=1')->json('data'))->all());
    }

    // ------------------------------------------------------------------ breeding

    public function test_breeding_template_uses_start_and_exact_expected_date_without_touching_the_breeding_project(): void
    {
        $cycle = $this->cycle('chicken');
        $project = $this->project($cycle)->assertCreated()->json('data');
        $this->assertSame($this->day(18), $project['expectation']['date']);
        $row = BreedingProject::findOrFail($project['id'])->only(['expected_date', 'expected_from', 'expected_to', 'reference_snapshot', 'status', 'start_date']);

        $this->assertSame(['chicken-incubation'], collect($this->getJson('/api/v1/work-templates/recommended?breeding_project_id='.$project['id'])->json('data'))->pluck('code')->all());
        $before = $this->records();
        $applied = $this->apply($this->platform('chicken-incubation'), ['breeding_project_id' => $project['id']])->assertCreated()->json('data');
        $this->assertSame($before, $this->records());
        $this->assertSame($project['id'], $applied['breeding_project_id']);
        $this->assertSame($cycle, $applied['production_cycle_id']);
        $this->assertSame(4, $applied['schedules_created']);
        $this->assertSame(0, $applied['skipped_no_anchor']);

        $tasks = Task::where('breeding_project_id', $project['id'])->orderBy('due_date')->get();
        $this->assertSame([$this->day(4), $this->day(11), $this->day(15), $this->day(18)], $tasks->map(fn ($t) => $t->due_date->toDateString())->all(), 'start+7 / start+14 / start+18 / expected date');
        $hatch = $tasks->last();
        $this->assertTrue($hatch->requires_evidence);
        $this->assertSame('breeding_outcome', $hatch->linked_record_type);
        $this->assertSame($cycle, $hatch->production_cycle_id);
        $this->assertEquals($row, BreedingProject::findOrFail($project['id'])->only(['expected_date', 'expected_from', 'expected_to', 'reference_snapshot', 'status', 'start_date']), 'scheduling never changes the breeding project');

        $check = $tasks->first();
        $this->assertSame('/api/v1/breeding-projects/'.$project['id'].'/checks', $this->getJson('/api/v1/tasks/'.$check->id.'/record-prefill')->json('data.endpoint'));
        $saved = $this->postJson('/api/v1/breeding-projects/'.$project['id'].'/checks', ['checked_on' => $this->today(), 'result' => 'positive', 'fertile_count' => 40])->assertCreated()->json('data');
        $checkId = collect($saved['checks'])->first()['id'];
        $this->complete($check->id, ['evidence' => ['type' => 'breeding_check', 'id' => $checkId]])->assertOk()->assertJsonPath('data.completion.evidence.type', 'breeding_check');
        $this->complete($hatch->id)->assertStatus(422)->assertJsonValidationErrors('evidence');
    }

    public function test_breeding_window_anchors_and_missing_expectation(): void
    {
        $guinea = $this->project($this->cycle('guinea_fowl'), ['start_date' => $this->day(-1)])->assertCreated()->json('data');
        $this->assertSame('window', $guinea['expectation']['type']);
        $template = $this->postJson('/api/v1/work-templates', ['name' => 'Window', 'applies_to' => 'breeding_project', 'items' => [
            ['title' => 'Window opens', 'category' => 'breeding_reproduction', 'anchor' => 'breeding_expected', 'offset_days' => 0],
            ['title' => 'Window closes', 'category' => 'breeding_reproduction', 'anchor' => 'breeding_expected_to', 'offset_days' => 1],
        ]])->assertCreated()->json('data.id');
        $applied = $this->apply($template, ['breeding_project_id' => $guinea['id']])->assertCreated()->json('data');
        $this->assertSame(2, $applied['schedules_created']);
        $dates = Task::where('breeding_project_id', $guinea['id'])->orderBy('due_date')->get()->map(fn ($t) => $t->due_date->toDateString())->all();
        $this->assertSame([$guinea['expectation']['from'], CarbonImmutable::parse($guinea['expectation']['to'])->addDay()->toDateString()], $dates);

        $snail = $this->project($this->cycle('snail'), ['workflow' => 'incubation', 'eggs_set' => 10])->assertCreated()->json('data');
        $this->assertSame('none', $snail['expectation']['type']);
        $none = $this->apply($template, ['breeding_project_id' => $snail['id']])->assertCreated()->json('data');
        $this->assertSame(0, $none['schedules_created']);
        $this->assertSame(2, $none['skipped_no_anchor']);
        $this->assertSame(0, Task::where('breeding_project_id', $snail['id'])->count());
    }

    public function test_breeding_project_state_gates_new_and_completed_work(): void
    {
        $project = $this->project($this->cycle('chicken'))->assertCreated()->json('data');
        $task = $this->make(['breeding_project_id' => $project['id'], 'production_cycle_id' => null]);
        $this->assertSame($project['production_cycle_id'], $task['production_cycle_id'], 'a project implies its cycle');
        $this->postJson('/api/v1/breeding-projects/'.$project['id'].'/cancel', ['reason' => 'Power failure'])->assertOk();
        $this->task(['breeding_project_id' => $project['id']])->assertStatus(409)->assertJsonPath('code', 'project_not_active');
        $this->apply($this->platform('chicken-incubation'), ['breeding_project_id' => $project['id']])->assertStatus(409)->assertJsonPath('code', 'project_not_active');
        $this->complete($task['id'])->assertStatus(409)->assertJsonPath('code', 'project_not_active');
        $this->postJson('/api/v1/tasks/'.$task['id'].'/cancel', ['reason' => 'Project cancelled'])->assertOk();
        $this->task(['breeding_project_id' => $project['id'], 'production_cycle_id' => $this->cycle()])->assertStatus(422)->assertJsonValidationErrors('production_cycle_id');
    }

    // ------------------------------------------------------------------ closed cycle

    public function test_closed_cycle_blocks_new_and_completed_work_but_allows_cancel_and_resumes_on_reopen(): void
    {
        $cycle = $this->cycle();
        $open = $this->make(['production_cycle_id' => $cycle]);
        $schedule = $this->schedule(['production_cycle_id' => $cycle, 'ends_on' => $this->day(60)])->assertCreated()->json('data');
        $this->closeCycle($cycle);

        $this->task(['production_cycle_id' => $cycle])->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
        $this->schedule(['production_cycle_id' => $cycle])->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
        $this->apply($this->platform('chicken-starter'), ['production_cycle_id' => $cycle])->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
        $this->complete($open['id'])->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
        $this->patchJson('/api/v1/tasks/'.$open['id'], ['title' => 'x'])->assertStatus(409)->assertJsonPath('code', 'cycle_closed');

        $count = Task::where('schedule_id', $schedule['id'])->count();
        $this->travelTo(now()->addDays(15));
        Artisan::call('work:generate-tasks');
        $this->assertSame($count, Task::where('schedule_id', $schedule['id'])->count(), 'no generation while closed');
        $this->postJson('/api/v1/tasks/'.$open['id'].'/cancel', ['reason' => 'Cycle closed'])->assertOk();

        $this->postJson('/api/v1/production-cycles/'.$cycle.'/reopen', ['reason' => 'Mistake'])->assertOk();
        Artisan::call('work:generate-tasks');
        $this->assertGreaterThan($count, Task::where('schedule_id', $schedule['id'])->count(), 'generation resumes after reopening');
    }

    // ------------------------------------------------------------------ assignment, permissions, isolation

    public function test_assignment_to_members_and_roles(): void
    {
        $worker = $this->member(FarmRole::FarmWorker, name: 'Wale Worker');
        $stranger = $this->member(FarmRole::FarmWorker, $this->otherFarm()[1]);
        $removed = $this->member(FarmRole::FarmWorker);
        $this->removeMembership($removed);

        $task = $this->make(['assigned_user_id' => $worker->id]);
        $this->assertSame($worker->id, $task['assigned_user_id']);
        $this->assertSame('Wale Worker', $task['assigned_user_name']);
        $this->task(['assigned_user_id' => $stranger->id])->assertStatus(422)->assertJsonValidationErrors('assigned_user_id');
        $this->task(['assigned_user_id' => $removed->id])->assertStatus(422)->assertJsonValidationErrors('assigned_user_id');
        $this->task(['assigned_role' => 'wizard'])->assertStatus(422)->assertJsonValidationErrors('assigned_role');
        $this->patchJson('/api/v1/tasks/'.$task['id'], ['assigned_user_id' => null, 'assigned_role' => 'farm_worker'])->assertOk()->assertJsonPath('data.assigned_user_id', null)->assertJsonPath('data.assigned_role', 'farm_worker');
        $this->getJson('/api/v1/tasks?assigned_to='.$worker->id)->assertJsonCount(0, 'data');
        $this->patchJson('/api/v1/tasks/'.$task['id'], ['assigned_user_id' => $worker->id, 'assigned_role' => null])->assertOk();
        $this->getJson('/api/v1/tasks?assigned_to='.$worker->id)->assertJsonCount(1, 'data');
    }

    public function test_role_visibility_and_permissions(): void
    {
        $worker = $this->member(FarmRole::FarmWorker);
        $vet = $this->member(FarmRole::Vet);
        $finance = $this->member(FarmRole::Finance);
        $manager = $this->member(FarmRole::Manager);
        $mine = $this->make(['assigned_user_id' => $worker->id, 'title' => 'Mine']);
        $roleWide = $this->make(['assigned_role' => 'farm_worker', 'title' => 'Any worker']);
        $this->make(['category' => 'vaccination_medication', 'title' => 'Vaccinate']);
        $payment = $this->make(['category' => 'payment', 'title' => 'Pay supplier']);
        $other = $this->make(['title' => 'Unrelated']);
        $titles = fn () => collect($this->getJson('/api/v1/tasks')->assertOk()->json('data'))->pluck('title')->sort()->values()->all();

        $this->signInAs($worker);
        $this->assertSame(['Any worker', 'Mine'], $titles());
        $this->getJson('/api/v1/tasks/'.$other['id'])->assertNotFound();
        $this->task()->assertForbidden();
        $this->patchJson('/api/v1/tasks/'.$mine['id'], ['title' => 'x'])->assertForbidden();
        $this->postJson('/api/v1/tasks/'.$mine['id'].'/cancel', ['reason' => 'x'])->assertForbidden();
        $this->getJson('/api/v1/schedules')->assertForbidden();
        $this->postJson('/api/v1/work-templates', ['name' => 'x'])->assertForbidden();
        $this->complete($other['id'])->assertNotFound();
        $this->complete($mine['id'])->assertOk()->assertJsonPath('data.completion.completed_by', $worker->id);

        $this->signInAs($vet);
        $this->assertSame(['Vaccinate'], $titles());
        $this->signInAs($finance);
        $this->assertSame(['Pay supplier'], $titles());
        $this->complete($payment['id'])->assertOk();
        $this->signInAs($manager);
        $this->assertCount(5, $this->getJson('/api/v1/tasks')->json('data'));
        $this->task()->assertCreated();
    }

    public function test_calendar_respects_task_visibility(): void
    {
        $worker = $this->member(FarmRole::FarmWorker);
        $this->make(['assigned_user_id' => $worker->id, 'title' => 'Mine']);
        $this->make(['title' => 'Hidden']);
        $this->signInAs($worker);
        $items = $this->getJson('/api/v1/calendar?from='.$this->today().'&to='.$this->today())->assertOk()->json('data');
        $this->assertSame(['Mine'], collect($items)->where('kind', 'task')->pluck('task.title')->all());
    }

    public function test_farm_isolation_for_tasks_schedules_templates_and_context(): void
    {
        $cycle = $this->cycle();
        $task = $this->make(['production_cycle_id' => $cycle]);
        $schedule = $this->schedule(['production_cycle_id' => $cycle])->assertCreated()->json('data');
        $custom = $this->postJson('/api/v1/work-templates', ['name' => 'Private', 'applies_to' => 'production_cycle', 'items' => [['title' => 'x', 'category' => 'other', 'anchor' => 'cycle_start']]])->assertCreated()->json('data');

        [$otherOwner, $otherFarm] = $this->otherFarm();
        $this->onPlan('farm-pro', $otherFarm);
        $this->signInAs($otherOwner);
        $this->getJson('/api/v1/tasks/'.$task['id'])->assertNotFound();
        $this->complete($task['id'])->assertNotFound();
        $this->patchJson('/api/v1/tasks/'.$task['id'], ['title' => 'x'])->assertNotFound();
        $this->postJson('/api/v1/tasks/'.$task['id'].'/cancel', ['reason' => 'x'])->assertNotFound();
        $this->getJson('/api/v1/schedules/'.$schedule['id'])->assertNotFound();
        $this->postJson('/api/v1/schedules/'.$schedule['id'].'/end')->assertNotFound();
        $this->getJson('/api/v1/work-templates/'.$custom['id'])->assertNotFound();
        $this->apply($custom['id'], ['production_cycle_id' => $cycle])->assertNotFound();
        $this->task(['production_cycle_id' => $cycle])->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/tasks')->json('data'));
        $this->assertSame([], $this->getJson('/api/v1/calendar?from='.$this->today().'&to='.$this->day(5))->json('data'));
        $this->assertNotEmpty($this->getJson('/api/v1/work-templates')->json('data'), 'platform templates are visible to every farm');
        $this->assertNotContains($custom['id'], collect($this->getJson('/api/v1/work-templates')->json('data'))->pluck('id')->all());
        $this->task(['farm_id' => $this->farm->id])->assertStatus(422)->assertJsonValidationErrors('farm_id');
    }

    // ------------------------------------------------------------------ idempotency

    public function test_task_and_schedule_creation_are_idempotent(): void
    {
        $payload = $this->payload();
        $first = $this->postJson('/api/v1/tasks', $payload)->assertCreated()->json('data');
        $this->assertSame($first, $this->postJson('/api/v1/tasks', $payload)->assertCreated()->json('data'));
        $this->assertSame(1, Task::count());
        $this->postJson('/api/v1/tasks', ['title' => 'Different'] + $payload)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->postJson('/api/v1/tasks', array_diff_key($payload, ['idempotency_key' => 1]))->assertStatus(422)->assertJsonValidationErrors('idempotency_key');

        $key = (string) Str::uuid();
        $s1 = $this->schedule(['idempotency_key' => $key])->assertCreated()->json('data');
        $s2 = $this->schedule(['idempotency_key' => $key])->assertCreated()->json('data');
        $this->assertSame($s1['id'], $s2['id']);
        $this->assertSame(1, Schedule::count());
        $this->assertSame(32, Task::count());
        $this->schedule(['idempotency_key' => $key, 'title' => 'Other'])->assertStatus(409);
    }

    public function test_task_list_filters_and_pagination(): void
    {
        $cycle = $this->cycle();
        $this->make(['production_cycle_id' => $cycle, 'due_date' => $this->day(1), 'category' => 'harvest']);
        $this->make(['due_date' => $this->day(3)]);
        $this->make(['due_date' => $this->day(10)]);
        $this->assertCount(1, $this->getJson('/api/v1/tasks?production_cycle_id='.$cycle)->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/tasks?category=harvest')->json('data'));
        $this->assertCount(2, $this->getJson('/api/v1/tasks?from='.$this->day(0).'&to='.$this->day(5))->json('data'));
        $this->assertSame(3, $this->getJson('/api/v1/tasks?per_page=2')->json('meta.total'));
        $this->assertCount(2, $this->getJson('/api/v1/tasks?per_page=2')->json('data'));
        $this->getJson('/api/v1/tasks?assigned_to=me')->assertJsonCount(0, 'data');
    }

    // ------------------------------------------------------------------ calendar

    public function test_calendar_is_a_read_model_of_tasks_and_milestones_and_creates_nothing(): void
    {
        $cycle = $this->cycle('chicken', ['expected_end_date' => $this->day(40)]);
        $project = $this->project($cycle)->assertCreated()->json('data');
        $this->make(['title' => 'Cycle task', 'production_cycle_id' => $cycle, 'due_date' => $this->day(2), 'due_time' => '08:00']);
        $this->postJson('/api/v1/health-records', ['production_cycle_id' => $cycle, 'type' => 'vet_visit', 'details' => ['vet_name' => 'Dr Ade'], 'follow_up_on' => $this->day(5), 'recorded_at' => $this->at(2), 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $taskCount = Task::count();

        $calendar = $this->getJson('/api/v1/calendar?from='.$this->day(-5).'&to='.$this->day(45))->assertOk();
        $calendar->assertJsonPath('meta.timezone', 'Africa/Lagos')->assertJsonPath('meta.today', $this->today());
        $items = collect($calendar->json('data'));
        $milestones = $items->where('kind', 'milestone')->keyBy('code');
        $this->assertSame($this->day(18), $milestones['breeding_expected']['date']);
        $this->assertSame($project['id'], $milestones['breeding_expected']['source']['id']);
        $this->assertSame($this->day(40), $milestones['cycle_expected_end']['date']);
        $this->assertSame($this->day(5), $milestones['health_follow_up']['date']);
        $this->assertArrayNotHasKey('cycle_start', $milestones->all(), 'the 2026-01-01 start is outside the range');
        $task = $items->firstWhere('kind', 'task');
        $this->assertSame('Cycle task', $task['task']['title']);
        $this->assertSame('08:00', $task['time']);
        $this->assertSame($items->pluck('date')->sort()->values()->all(), $items->pluck('date')->all(), 'ordered by date');
        $this->assertSame($taskCount, Task::count(), 'the calendar creates and duplicates nothing');
        $this->assertSame(1, Task::count());

        $this->getJson('/api/v1/calendar?from='.$this->day(-5).'&to='.$this->day(45).'&category=harvest')->assertJsonCount(0, 'data');
        $this->assertSame([], collect($this->getJson('/api/v1/calendar?from='.$this->day(-5).'&to='.$this->day(45).'&include_milestones=0')->json('data'))->where('kind', 'milestone')->all());
        $this->getJson('/api/v1/calendar?from='.$this->day(0).'&to='.$this->day(120))->assertStatus(422)->assertJsonValidationErrors('to');
        $this->getJson('/api/v1/calendar?from='.$this->day(5).'&to='.$this->day(1))->assertStatus(422);
        $this->getJson('/api/v1/calendar')->assertStatus(422);
    }

    public function test_calendar_shows_window_milestones_that_overlap_and_hides_milestones_without_view_permission(): void
    {
        $guinea = $this->project($this->cycle('guinea_fowl'), ['start_date' => $this->day(-1)])->assertCreated()->json('data');
        $from = $guinea['expectation']['from'];
        $to = $guinea['expectation']['to'];
        $overlapping = $this->getJson('/api/v1/calendar?from='.$to.'&to='.CarbonImmutable::parse($to)->addDays(3)->toDateString())->assertOk()->json('data');
        $window = collect($overlapping)->firstWhere('code', 'breeding_expected_window');
        $this->assertSame([$from, $to], [$window['date'], $window['end_date']]);
        $this->assertSame([], collect($this->getJson('/api/v1/calendar?from='.CarbonImmutable::parse($to)->addDay()->toDateString().'&to='.CarbonImmutable::parse($to)->addDays(4)->toDateString())->json('data'))->where('code', 'breeding_expected_window')->all());

        $finance = $this->member(FarmRole::Finance);
        $this->make(['category' => 'payment', 'title' => 'Pay']);
        $this->signInAs($finance);
        $items = collect($this->getJson('/api/v1/calendar?from='.$from.'&to='.$to)->assertOk()->json('data'));
        $this->assertSame([], $items->where('kind', 'milestone')->all(), 'finance has no breeding or health view');
    }

    public function test_cancelled_or_completed_breeding_projects_and_closed_cycles_leave_the_calendar_milestones(): void
    {
        $cycle = $this->cycle('chicken');
        $project = $this->project($cycle)->assertCreated()->json('data');
        $range = '/api/v1/calendar?from='.$this->day(-5).'&to='.$this->day(30);
        $this->assertNotNull(collect($this->getJson($range)->json('data'))->firstWhere('code', 'breeding_expected'));
        $this->postJson('/api/v1/breeding-projects/'.$project['id'].'/cancel', ['reason' => 'x'])->assertOk();
        $this->assertNull(collect($this->getJson($range)->json('data'))->firstWhere('code', 'breeding_expected'));
    }
}
