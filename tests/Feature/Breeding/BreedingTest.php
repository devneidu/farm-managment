<?php

namespace Tests\Feature\Breeding;

use App\Enums\FarmRole;
use App\Events\Breeding\BreedingOutcomeRecorded;
use App\Models\BreedingOutcome;
use App\Models\BreedingProject;
use App\Models\CropType;
use App\Models\MasterCapability;
use App\Models\OperationalRecord;
use App\Models\OperationType;
use App\Models\PopulationMovement;
use App\Models\Species;
use App\Models\SpeciesCapability;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\Team\TeamTestCase;

class BreedingTest extends TeamTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->onPlan('farm-pro');
        $this->signInAs($this->owner);
    }

    // ------------------------------------------------------------------ helpers

    private function at(int $hoursAgo = 1): string
    {
        return now()->utc()->subHours($hoursAgo)->format('Y-m-d\TH:i:s\Z');
    }

    private function cycle(string $species = 'chicken', int $population = 100, string $start = '2026-01-01'): string
    {
        $operation = Species::where('code', $species)->firstOrFail()->operationType->code;

        return $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => ucfirst($species).' '.Str::random(4), 'operation_type_id' => OperationType::where('code', $operation)->firstOrFail()->id,
            'species_id' => Species::where('code', $species)->firstOrFail()->id, 'production_purpose' => ($species === 'honeybee' ? 'colony_breeding' : 'breeding'), 'initial_population' => $population, 'start_date' => $start])->assertCreated()->json('data.id');
    }

    private function population(string $cycle): int
    {
        return $this->getJson('/api/v1/production-cycles/'.$cycle)->assertOk()->json('data.livestock.current_population');
    }

    private function start(string $cycle, array $extra = [])
    {
        return $this->postJson('/api/v1/breeding-projects', array_replace(['production_cycle_id' => $cycle, 'workflow' => 'incubation', 'start_date' => '2026-08-01', 'eggs_set' => 50,
            'idempotency_key' => (string) Str::uuid()], $extra));
    }

    private function outcome(string $project, array $extra = [])
    {
        return $this->postJson('/api/v1/breeding-projects/'.$project.'/outcomes', array_replace(['live_count' => 37, 'loss_count' => 13,
            'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()], $extra));
    }

    private function reverse(string $project, string $outcome, array $extra = [])
    {
        return $this->postJson('/api/v1/breeding-projects/'.$project.'/outcomes/'.$outcome.'/reverse', array_replace(['reason' => 'Miscounted', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()], $extra));
    }

    private function expectation(string $species, string $workflow, array $extra = []): array
    {
        return $this->start($this->cycle($species), ['workflow' => $workflow, 'eggs_set' => $workflow === 'incubation' ? 20 : null] + $extra)->assertCreated()->json('data');
    }

    // ------------------------------------------------------------------ expected dates

    public function test_chicken_exact_21_day_expected_hatch_date(): void
    {
        $data = $this->expectation('chicken', 'incubation');
        $this->assertSame(['type' => 'exact', 'source' => 'reference', 'date' => '2026-08-22', 'from' => null, 'to' => null, 'no_expectation_reason' => null], $data['expectation']);
        $this->assertSame('exact', $data['biological_reference']['kind']);
        $this->assertSame(21, $data['biological_reference']['days']);
        $this->assertMatchesRegularExpression('/^BRD-\d{4}-00001$/', $data['reference']);
    }

    public function test_guinea_fowl_range_gives_a_window_not_a_midpoint(): void
    {
        $e = $this->expectation('guinea_fowl', 'incubation')['expectation'];
        $this->assertSame('window', $e['type']);
        $this->assertSame(['2026-08-27', '2026-08-29'], [$e['from'], $e['to']]);
        $this->assertNull($e['date']);
    }

    public function test_camel_gestation_gives_a_birth_window(): void
    {
        $e = $this->expectation('camel', 'pregnancy', ['start_date' => '2026-01-10'])['expectation'];
        $this->assertSame('window', $e['type']);
        $this->assertSame(['2027-01-10', '2027-02-14'], [$e['from'], $e['to']]); // +365 .. +400 days
        $this->assertNull($e['date']);
    }

    public function test_cattle_keeps_its_exact_default_with_the_range_preserved_in_the_snapshot(): void
    {
        $data = $this->expectation('cattle', 'pregnancy', ['start_date' => '2026-01-01']);
        $this->assertSame('exact', $data['expectation']['type']);
        $this->assertSame('2026-10-11', $data['expectation']['date']); // +283 days
        $this->assertSame([280, 285], [$data['biological_reference']['days_min'], $data['biological_reference']['days_max']]);
        $this->assertTrue($data['biological_reference']['approximate']);
    }

    public function test_snail_has_no_fabricated_expectation_but_accepts_a_manual_one(): void
    {
        $data = $this->expectation('snail', 'incubation');
        $this->assertSame('none', $data['expectation']['type']);
        $this->assertSame('no_numeric_reference', $data['expectation']['no_expectation_reason']);
        $this->assertNull($data['expectation']['date']);
        $this->assertNull($data['expectation']['from']);
        $this->assertSame('none', $data['biological_reference']['kind']);

        $manual = $this->expectation('snail', 'incubation', ['expected_date' => '2026-08-20']);
        $this->assertSame(['type' => 'exact', 'source' => 'manual', 'date' => '2026-08-20', 'from' => null, 'to' => null, 'no_expectation_reason' => null], $manual['expectation']);
    }

    public function test_honeybee_16_24_caste_reference_is_preserved_but_not_applied_automatically(): void
    {
        $data = $this->expectation('honeybee', 'incubation');
        $this->assertSame('none', $data['expectation']['type']);
        $this->assertSame('qualified_reference', $data['expectation']['no_expectation_reason']);
        $this->assertSame('range', $data['biological_reference']['kind']);
        $this->assertSame([16, 24], [$data['biological_reference']['days_min'], $data['biological_reference']['days_max']]);
        $this->assertStringContainsString('caste', $data['biological_reference']['note']);
        $this->assertFalse($data['biological_reference']['automatic_expectation']);
    }

    public function test_manual_window_overrides_without_touching_the_reference_and_can_be_reverted(): void
    {
        $project = $this->expectation('chicken', 'incubation', ['expected_from' => '2026-08-21', 'expected_to' => '2026-08-24']);
        $this->assertSame(['window', 'manual', '2026-08-21', '2026-08-24'], [$project['expectation']['type'], $project['expectation']['source'], $project['expectation']['from'], $project['expectation']['to']]);
        $this->assertSame(21, $project['biological_reference']['days']);

        $this->patchJson('/api/v1/breeding-projects/'.$project['id'], ['revert_to_reference' => true])->assertOk()
            ->assertJsonPath('data.expectation.type', 'exact')->assertJsonPath('data.expectation.source', 'reference')->assertJsonPath('data.expectation.date', '2026-08-22');
        $this->startFails(['expected_date' => '2026-08-01', 'expected_from' => '2026-08-02', 'expected_to' => '2026-08-03']);
        $this->startFails(['expected_date' => '2026-07-01']); // before the start
    }

    private function startFails(array $extra): void
    {
        $this->start($this->cycle(), $extra)->assertStatus(422);
    }

    public function test_changing_the_start_recalculates_a_reference_expectation_from_the_snapshot_until_an_outcome(): void
    {
        $project = $this->expectation('guinea_fowl', 'incubation');
        $this->patchJson('/api/v1/breeding-projects/'.$project['id'], ['start_date' => '2026-08-05'])->assertOk()
            ->assertJsonPath('data.expectation.from', '2026-08-31')->assertJsonPath('data.expectation.to', '2026-09-02');
        $this->outcome($project['id'], ['live_count' => 5, 'loss_count' => 0])->assertCreated();
        $this->patchJson('/api/v1/breeding-projects/'.$project['id'], ['start_date' => '2026-08-06'])->assertStatus(409)->assertJsonPath('code', 'project_not_active');
    }

    public function test_manual_expectation_is_kept_when_the_start_changes(): void
    {
        $project = $this->expectation('chicken', 'incubation', ['expected_date' => '2026-08-23']);
        $this->patchJson('/api/v1/breeding-projects/'.$project['id'], ['start_date' => '2026-08-02'])->assertOk()
            ->assertJsonPath('data.expectation.source', 'manual')->assertJsonPath('data.expectation.date', '2026-08-23');
        $this->patchJson('/api/v1/breeding-projects/'.$project['id'], ['start_date' => '2026-08-24'])->assertStatus(422);
    }

    public function test_biological_reference_snapshot_is_stable_when_master_data_changes(): void
    {
        $project = $this->expectation('chicken', 'incubation');
        $capability = MasterCapability::where('code', 'supports_incubation')->firstOrFail();
        SpeciesCapability::where('species_id', Species::where('code', 'chicken')->firstOrFail()->id)->where('capability_id', $capability->id)->firstOrFail()->update(['reference_config' => ['incubation_days' => 30]]);

        $shown = $this->getJson('/api/v1/breeding-projects/'.$project['id'])->assertOk()->json('data');
        $this->assertSame(21, $shown['biological_reference']['days']);
        $this->patchJson('/api/v1/breeding-projects/'.$project['id'], ['start_date' => '2026-08-02'])->assertOk()->assertJsonPath('data.expectation.date', '2026-08-23');
        // A new project uses the new reference.
        $this->assertSame('2026-08-31', $this->expectation('chicken', 'incubation')['expectation']['date']);
        $this->assertSame(21, $this->getJson('/api/v1/breeding-projects/'.$project['id'])->json('data.biological_reference.days'));
    }

    // ------------------------------------------------------------------ capabilities and fields

    public function test_incompatible_workflows_are_rejected_through_capabilities(): void
    {
        $this->start($this->cycle('chicken'), ['workflow' => 'pregnancy', 'eggs_set' => null])->assertStatus(422)->assertJsonValidationErrors('workflow');
        $this->start($this->cycle('cattle'), ['workflow' => 'incubation'])->assertStatus(422)->assertJsonValidationErrors('workflow');
        // Capability-driven, not name-driven: disabling breeding blocks even a supported workflow.
        $species = Species::where('code', 'chicken')->firstOrFail();
        SpeciesCapability::where('species_id', $species->id)->where('capability_id', MasterCapability::where('code', 'supports_breeding')->firstOrFail()->id)->update(['enabled' => false]);
        $this->start($this->cycle('chicken'))->assertStatus(422)->assertJsonValidationErrors('workflow');
    }

    public function test_incubation_and_pregnancy_fields_apply_only_to_their_workflow(): void
    {
        $this->start($this->cycle())->assertCreated()->assertJsonPath('data.females_bred', null);
        $this->start($this->cycle(), ['eggs_set' => null])->assertStatus(422)->assertJsonValidationErrors('eggs_set');
        $this->start($this->cycle(), ['females_bred' => 3])->assertStatus(422)->assertJsonValidationErrors('females_bred');
        $this->start($this->cycle('cattle'), ['workflow' => 'pregnancy', 'eggs_set' => 5])->assertStatus(422)->assertJsonValidationErrors('eggs_set');
        $this->start($this->cycle('cattle'), ['workflow' => 'pregnancy', 'eggs_set' => null, 'females_bred' => 3, 'expected_offspring' => 3])->assertCreated()->assertJsonPath('data.eggs_set', null)->assertJsonPath('data.females_bred', 3);
    }

    public function test_crop_cycles_and_invalid_dates_are_rejected(): void
    {
        $crop = $this->postJson('/api/v1/production-cycles', ['kind' => 'crop', 'name' => 'Yam '.Str::random(4), 'operation_type_id' => OperationType::where('code', 'crops')->firstOrFail()->id,
            'crop_type_id' => CropType::where('code', 'yam')->firstOrFail()->id, 'planting_material_type' => 'tuber', 'planting_unit_type' => 'heap', 'initial_planting_units' => 800, 'planting_date' => '2026-01-10'])->assertCreated()->json('data.id');
        $this->start($crop)->assertStatus(422)->assertJsonValidationErrors('production_cycle_id');
        $cycle = $this->cycle();
        $this->start($cycle, ['start_date' => '2025-12-31'])->assertStatus(422)->assertJsonValidationErrors('start_date');
        $this->start($cycle, ['start_date' => now()->addDays(2)->toDateString()])->assertStatus(422)->assertJsonValidationErrors('start_date');
    }

    // ------------------------------------------------------------------ outcomes and population

    public function test_eggs_set_and_expected_offspring_never_change_population(): void
    {
        $cycle = $this->cycle();
        $this->start($cycle, ['eggs_set' => 50, 'expected_offspring' => 40])->assertCreated();
        $this->assertSame(100, $this->population($cycle));
        $this->assertSame(1, PopulationMovement::count());
    }

    public function test_50_eggs_expected_40_actual_37_adds_exactly_37_once(): void
    {
        Event::fake([BreedingOutcomeRecorded::class]);
        $cycle = $this->cycle();
        $project = $this->start($cycle, ['eggs_set' => 50, 'expected_offspring' => 40])->assertCreated()->json('data');
        $payload = ['live_count' => 37, 'loss_count' => 13, 'recorded_at' => $this->at(2), 'idempotency_key' => 'hatch-1'];
        $first = $this->postJson('/api/v1/breeding-projects/'.$project['id'].'/outcomes', $payload)->assertCreated()->json('data');
        $this->assertSame(137, $this->population($cycle));

        // Retry: same key + same payload returns the original; no second movement.
        $retry = $this->postJson('/api/v1/breeding-projects/'.$project['id'].'/outcomes', $payload)->assertCreated()->json('data');
        $this->assertSame($first['id'], $retry['id']);
        $this->assertSame(137, $this->population($cycle));
        $this->assertSame(1, BreedingOutcome::count());
        $this->assertSame(2, PopulationMovement::where('production_cycle_id', $cycle)->count());
        Event::assertDispatchedTimes(BreedingOutcomeRecorded::class, 1);

        // Same key, changed payload -> conflict.
        $this->postJson('/api/v1/breeding-projects/'.$project['id'].'/outcomes', ['live_count' => 38] + $payload)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame(137, $this->population($cycle));

        // Traceable: the movement belongs to an operational record linked from the outcome.
        $record = OperationalRecord::findOrFail($first['operational_record_id']);
        $this->assertSame(['breeding_outcome', 37], [$record->type, $record->population_delta]);
        $this->assertSame($record->id, PopulationMovement::where('production_cycle_id', $cycle)->where('type', 'breeding_outcome')->firstOrFail()->operational_record_id);
        $this->assertSame('completed', $this->getJson('/api/v1/breeding-projects/'.$project['id'])->json('data.status'));
        $this->assertSame(['live_count' => 37, 'expected_offspring' => 40, 'variance' => -3], array_intersect_key($this->getJson('/api/v1/breeding-projects/'.$project['id'])->json('data.result'), array_flip(['live_count', 'expected_offspring', 'variance'])));
        $this->assertSame($payload['recorded_at'], substr($first['recorded_at'], 0, 19).'Z');
        // The outcome date is the farm-local date of recorded_at (2 h ago), which is not 'today' just after Lagos midnight.
        $this->assertSame(now('Africa/Lagos')->subHours(2)->toDateString(), $first['outcome_date']);
    }

    public function test_mammal_birth_adds_live_offspring_only(): void
    {
        $cycle = $this->cycle('cattle', 10, '2026-01-01');
        $project = $this->start($cycle, ['workflow' => 'pregnancy', 'eggs_set' => null, 'females_bred' => 1, 'expected_offspring' => 2, 'start_date' => '2026-01-10'])->assertCreated()->json('data');
        $this->outcome($project['id'], ['live_count' => 1, 'loss_count' => 1])->assertCreated();
        $this->assertSame(11, $this->population($cycle));
    }

    public function test_zero_live_offspring_adds_no_population_and_the_client_has_no_flag(): void
    {
        $cycle = $this->cycle();
        $failed = $this->start($cycle)->assertCreated()->json('data');
        $this->outcome($failed['id'], ['live_count' => 0, 'loss_count' => 50])->assertCreated()->assertJsonPath('data.operational_record_id', null)->assertJsonMissingPath('data.add_to_population');
        $this->assertSame(100, $this->population($cycle));
        $this->assertSame(0, OperationalRecord::count());

        // The former flag is not part of the contract: positive live offspring join the population regardless of what a client sends.
        $this->outcome($this->start($cycle)->json('data.id'), ['live_count' => 10, 'add_to_population' => false])->assertCreated();
        $this->assertSame(110, $this->population($cycle));
    }

    public function test_expected_offspring_and_eggs_set_never_change_population(): void
    {
        $cycle = $this->cycle();
        $this->start($cycle, ['eggs_set' => 500, 'expected_offspring' => 400])->assertCreated();
        $this->assertSame(100, $this->population($cycle));
        $this->assertSame(0, OperationalRecord::count());
    }

    public function test_reference_note_is_informational_and_the_calculation_policy_is_snapshotted(): void
    {
        // An ordinary numeric reference with an informational note still calculates.
        $capability = MasterCapability::where('code', 'supports_incubation')->firstOrFail();
        $row = SpeciesCapability::where('species_id', Species::where('code', 'duck')->firstOrFail()->id)->where('capability_id', $capability->id)->firstOrFail();
        $row->update(['reference_config' => ['incubation_days' => 28, 'note' => 'Muscovy ducks need about 35 days.']]);
        $duck = $this->expectation('duck', 'incubation');
        $this->assertSame('exact', $duck['expectation']['type']);
        $this->assertSame('2026-08-29', $duck['expectation']['date']);
        $this->assertTrue($duck['biological_reference']['automatic_expectation']);

        // Honeybee is non-automatic because of the explicit flag, which the snapshot keeps even if master data changes later.
        $bee = $this->expectation('honeybee', 'incubation');
        $this->assertFalse($bee['biological_reference']['automatic_expectation']);
        $this->assertSame('qualified_reference', $bee['expectation']['no_expectation_reason']);
        $bee_row = SpeciesCapability::where('species_id', Species::where('code', 'honeybee')->firstOrFail()->id)->where('capability_id', $capability->id)->firstOrFail();
        $bee_row->update(['reference_config' => ['incubation_days_min' => 16, 'incubation_days_max' => 24, 'automatic_expectation' => true]]);
        $this->assertFalse($this->getJson('/api/v1/breeding-projects/'.$bee['id'])->json('data.biological_reference.automatic_expectation'));
        $this->patchJson('/api/v1/breeding-projects/'.$bee['id'], ['start_date' => '2026-08-02'])->assertOk()->assertJsonPath('data.expectation.type', 'none');

        // The same range WITHOUT the flag (and with a note) calculates; note text alone never suppresses it.
        $bee_row->update(['reference_config' => ['incubation_days_min' => 16, 'incubation_days_max' => 24, 'note' => 'caste']]);
        $this->assertSame('window', $this->expectation('honeybee', 'incubation')['expectation']['type']);

        // Snail: no numeric duration, hence none.
        $this->assertSame('no_numeric_reference', $this->expectation('snail', 'incubation')['expectation']['no_expectation_reason']);
    }

    public function test_hatched_and_lost_cannot_exceed_eggs_set_and_one_outcome_per_project(): void
    {
        $cycle = $this->cycle();
        $project = $this->start($cycle, ['eggs_set' => 50])->json('data');
        $this->outcome($project['id'], ['live_count' => 40, 'loss_count' => 11])->assertStatus(422)->assertJsonValidationErrors('live_count');
        $this->outcome($project['id'], ['live_count' => 40, 'loss_count' => 10])->assertCreated();
        $this->outcome($project['id'], ['live_count' => 1, 'loss_count' => 0])->assertStatus(409)->assertJsonPath('code', 'project_not_active');
        $this->outcome($project['id'], ['recorded_at' => now()->addHour()->utc()->format('Y-m-d\TH:i:s\Z'), 'idempotency_key' => 'future'])->assertStatus(409);
    }

    public function test_outcome_cannot_precede_the_project_start(): void
    {
        $project = $this->start($this->cycle(), ['start_date' => now('Africa/Lagos')->toDateString()])->json('data');
        $this->outcome($project['id'], ['recorded_at' => now()->utc()->subDays(3)->format('Y-m-d\TH:i:s\Z')])->assertStatus(422)->assertJsonValidationErrors('recorded_at');
    }

    public function test_reversal_compensates_population_and_cannot_be_repeated(): void
    {
        $cycle = $this->cycle();
        $project = $this->start($cycle)->json('data');
        $outcome = $this->outcome($project['id'])->assertCreated()->json('data');
        $this->assertSame(137, $this->population($cycle));

        $payload = ['reason' => 'Counted twice', 'recorded_at' => $this->at(1), 'idempotency_key' => 'rev-1'];
        $reversal = $this->postJson('/api/v1/breeding-projects/'.$project['id'].'/outcomes/'.$outcome['id'].'/reverse', $payload)->assertCreated()->json('data');
        $this->assertSame(100, $this->population($cycle));
        $this->assertSame(['reversal', $outcome['id']], [$reversal['kind'], $reversal['reverses_outcome_id']]);
        $this->assertSame(-37, OperationalRecord::findOrFail($reversal['operational_record_id'])->population_delta);
        // Retry is harmless; a different key cannot reverse again.
        $this->postJson('/api/v1/breeding-projects/'.$project['id'].'/outcomes/'.$outcome['id'].'/reverse', $payload)->assertCreated()->assertJsonPath('data.id', $reversal['id']);
        $this->reverse($project['id'], $outcome['id'])->assertStatus(409)->assertJsonPath('code', 'outcome_already_reversed');
        $this->reverse($project['id'], $reversal['id'])->assertStatus(409)->assertJsonPath('code', 'outcome_already_reversed');
        $this->assertSame(100, $this->population($cycle));
        $this->assertSame('active', $this->getJson('/api/v1/breeding-projects/'.$project['id'])->json('data.status'));
        // History is kept: the original, reversal and both records still exist.
        $this->assertSame(2, BreedingOutcome::count());
        $this->assertSame(2, OperationalRecord::count());
    }

    public function test_correction_produces_the_right_final_population(): void
    {
        $cycle = $this->cycle();
        $project = $this->start($cycle)->json('data');
        $wrong = $this->outcome($project['id'], ['live_count' => 37])->json('data');
        $this->reverse($project['id'], $wrong['id'])->assertCreated();
        $right = $this->outcome($project['id'], ['live_count' => 35, 'loss_count' => 15, 'corrects_outcome_id' => $wrong['id']])->assertCreated()->json('data');
        $this->assertSame(135, $this->population($cycle));
        $this->assertSame($wrong['id'], $right['corrects_outcome_id']);
        $this->assertSame('completed', $this->getJson('/api/v1/breeding-projects/'.$project['id'])->json('data.status'));
        $this->assertSame(35, $this->getJson('/api/v1/breeding-projects/'.$project['id'])->json('data.result.live_count'));
        // A reversed outcome can be replaced only once, and an unreversed one cannot be "corrected".
        $this->reverse($project['id'], $right['id'])->assertCreated();
        $this->outcome($project['id'], ['live_count' => 34, 'loss_count' => 0, 'corrects_outcome_id' => $wrong['id']])->assertStatus(409)->assertJsonPath('code', 'invalid_correction');
        $this->assertSame(100, $this->population($cycle));
    }

    public function test_reversal_is_refused_when_it_would_make_the_dated_ledger_negative(): void
    {
        $cycle = $this->cycle('chicken', 10);
        $project = $this->start($cycle, ['eggs_set' => 50])->json('data');
        $outcome = $this->outcome($project['id'], ['live_count' => 5, 'loss_count' => 0, 'recorded_at' => $this->at(10)])->assertCreated()->json('data');
        $this->postJson('/api/v1/records', ['production_cycle_id' => $cycle, 'type' => 'mortality', 'recorded_at' => $this->at(5), 'idempotency_key' => (string) Str::uuid(),
            'details' => ['quantity' => 15, 'cause' => 'Disease']])->assertCreated();
        $this->assertSame(0, $this->population($cycle));
        $this->reverse($project['id'], $outcome['id'])->assertStatus(409)->assertJsonPath('code', 'insufficient_population');
        $this->assertSame(0, $this->population($cycle));
        $this->assertSame(1, BreedingOutcome::count());
    }

    public function test_breeding_population_records_are_only_reversible_through_the_outcome(): void
    {
        $cycle = $this->cycle();
        $project = $this->start($cycle)->json('data');
        $outcome = $this->outcome($project['id'])->json('data');
        $this->postJson('/api/v1/records/'.$outcome['operational_record_id'].'/reverse', ['reason' => 'x', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])
            ->assertStatus(409)->assertJsonPath('code', 'reverse_via_breeding_outcome');
        $this->assertSame(137, $this->population($cycle));
        $this->getJson('/api/v1/records?type=breeding_outcome&production_cycle_id='.$cycle)->assertOk()->assertJsonCount(1, 'data');
        // Not creatable through the generic record API either.
        $this->postJson('/api/v1/records', ['production_cycle_id' => $cycle, 'type' => 'breeding_outcome', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid(), 'details' => []])->assertStatus(422);
    }

    public function test_outcome_rows_are_append_only(): void
    {
        $project = $this->start($this->cycle())->json('data');
        $this->outcome($project['id'])->assertCreated();
        $this->expectException(LogicException::class);
        BreedingOutcome::firstOrFail()->update(['live_count' => 99]);
    }

    public function test_project_identity_and_snapshot_are_immutable_and_never_deleted(): void
    {
        $project = BreedingProject::findOrFail($this->start($this->cycle())->json('data.id'));
        try {
            $project->update(['reference_snapshot' => ['kind' => 'none']]);
            $this->fail('snapshot must be immutable');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
        $this->expectException(LogicException::class);
        $project->delete();
    }

    // ------------------------------------------------------------------ lifecycle

    public function test_lifecycle_checks_cancel_and_milestones(): void
    {
        $cycle = $this->cycle();
        $project = $this->start($cycle, ['eggs_set' => 50, 'start_date' => '2026-08-01'])->json('data');
        $url = '/api/v1/breeding-projects/'.$project['id'];
        $this->postJson($url.'/checks', ['checked_on' => '2026-08-08', 'result' => 'positive', 'fertile_count' => 45])->assertCreated()->assertJsonPath('data.checks.0.fertile_count', 45);
        $this->postJson($url.'/checks', ['checked_on' => '2026-08-08', 'result' => 'positive', 'fertile_count' => 51])->assertStatus(422)->assertJsonValidationErrors('fertile_count');
        $this->postJson($url.'/checks', ['checked_on' => '2026-07-31', 'result' => 'positive'])->assertStatus(422)->assertJsonValidationErrors('checked_on');
        $this->patchJson($url, ['start_date' => '2026-08-09'])->assertStatus(422)->assertJsonValidationErrors('start_date');

        $codes = array_column($this->getJson($url.'/milestones')->assertOk()->json('data'), 'status', 'code');
        $this->assertSame(['started' => 'done', 'expected_outcome' => 'overdue', 'check' => 'done'], $codes);

        $this->postJson($url.'/cancel', ['reason' => 'Power cut'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson($url.'/cancel', ['reason' => 'again'])->assertStatus(409)->assertJsonPath('code', 'project_not_active');
        $this->postJson($url.'/checks', ['checked_on' => '2026-08-09', 'result' => 'negative'])->assertStatus(409);
        $this->outcome($project['id'])->assertStatus(409)->assertJsonPath('code', 'project_not_active');
        $this->assertSame(100, $this->population($cycle));
        $this->assertSame('cancelled', $this->getJson($url.'/milestones')->json('data.1.status'));
        $this->deleteJson($url)->assertStatus(405);
    }

    public function test_pregnancy_checks_reject_fertile_counts(): void
    {
        $project = $this->start($this->cycle('cattle'), ['workflow' => 'pregnancy', 'eggs_set' => null, 'start_date' => '2026-08-01'])->json('data');
        $this->postJson('/api/v1/breeding-projects/'.$project['id'].'/checks', ['checked_on' => '2026-08-20', 'result' => 'positive', 'fertile_count' => 1])->assertStatus(422)->assertJsonValidationErrors('fertile_count');
        $this->postJson('/api/v1/breeding-projects/'.$project['id'].'/checks', ['checked_on' => '2026-08-20', 'result' => 'positive'])->assertCreated();
    }

    public function test_parents_must_be_same_farm_same_species_livestock_cycles(): void
    {
        $cycle = $this->cycle();
        $dam = $this->cycle();
        $cattle = $this->cycle('cattle');
        $ok = $this->start($cycle, ['parents' => [['role' => 'dam', 'production_cycle_id' => $dam, 'head_count' => 20], ['role' => 'sire', 'production_cycle_id' => $dam, 'head_count' => 3]]])->assertCreated();
        $this->assertCount(2, $ok->json('data.parents'));
        $this->start($cycle, ['parents' => [['role' => 'dam', 'production_cycle_id' => $cattle]]])->assertStatus(422);
        $this->start($cycle, ['parents' => [['role' => 'dam', 'production_cycle_id' => $dam], ['role' => 'dam', 'production_cycle_id' => $dam]]])->assertStatus(422);
        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $foreign = $this->cycle();
        $this->signInAs($this->owner);
        $this->start($cycle, ['parents' => [['role' => 'dam', 'production_cycle_id' => $foreign]]])->assertStatus(422);
    }

    public function test_closed_cycles_reject_writes_but_stay_readable(): void
    {
        $cycle = $this->cycle();
        $project = $this->start($cycle)->json('data');
        $outcome = $this->outcome($this->start($cycle)->json('data.id'))->json('data');
        $this->postJson('/api/v1/production-cycles/'.$cycle.'/close', ['end_date' => now('Africa/Lagos')->toDateString(), 'reason' => 'Done'])->assertOk();
        $url = '/api/v1/breeding-projects/'.$project['id'];
        $this->start($cycle)->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
        $this->outcome($project['id'])->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
        $this->reverse($outcome['breeding_project_id'], $outcome['id'])->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
        $this->postJson($url.'/checks', ['checked_on' => '2026-08-02', 'result' => 'positive'])->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
        $this->postJson($url.'/cancel', ['reason' => 'x'])->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
        $this->patchJson($url, ['notes' => 'x'])->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
        $this->getJson($url)->assertOk();
        $this->getJson($url.'/milestones')->assertOk();
        $this->getJson('/api/v1/breeding-projects?production_cycle_id='.$cycle)->assertOk()->assertJsonCount(2, 'data');
    }

    // ------------------------------------------------------------------ isolation, permissions, listing

    public function test_farm_isolation(): void
    {
        $cycle = $this->cycle();
        $project = $this->start($cycle, ['idempotency_key' => 'shared-key'])->json('data');
        $outcome = $this->outcome($project['id'])->json('data');
        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $url = '/api/v1/breeding-projects/'.$project['id'];
        $this->getJson($url)->assertNotFound();
        $this->getJson($url.'/milestones')->assertNotFound();
        $this->patchJson($url, ['notes' => 'x'])->assertNotFound();
        $this->postJson($url.'/checks', ['checked_on' => '2026-08-02', 'result' => 'positive'])->assertNotFound();
        $this->postJson($url.'/cancel', ['reason' => 'x'])->assertNotFound();
        $this->outcome($project['id'])->assertNotFound();
        $this->reverse($project['id'], $outcome['id'])->assertNotFound();
        $this->start($cycle)->assertNotFound();
        $this->getJson('/api/v1/breeding-projects')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/breeding-projects?production_cycle_id='.$cycle)->assertNotFound();
        // Idempotency keys are farm-scoped: another farm may reuse a key already used by the first farm.
        $mine = $this->cycle();
        $this->start($mine, ['idempotency_key' => 'shared-key'])->assertCreated();
        $this->assertSame(137, $this->population_as_owner($cycle)); // the foreign attempts changed nothing
    }

    private function population_as_owner(string $cycle): int
    {
        $this->signInAs($this->owner);

        return $this->population($cycle);
    }

    public function test_permissions_follow_the_role_presets(): void
    {
        $cycle = $this->cycle();
        $project = $this->start($cycle)->json('data');
        $outcome = $this->outcome($project['id'])->json('data');

        foreach ([FarmRole::FarmWorker, FarmRole::Vet] as $role) {
            $this->signInAs($this->member($role));
            $this->getJson('/api/v1/breeding-projects')->assertOk();
            $this->start($cycle)->assertCreated();
            $this->postJson('/api/v1/breeding-projects/'.$project['id'].'/checks', ['checked_on' => '2026-08-02', 'result' => 'positive'])->assertStatus(409); // completed, but permitted
            $this->reverse($project['id'], $outcome['id'])->assertForbidden();
            $this->outcome($project['id'], ['corrects_outcome_id' => $outcome['id']])->assertForbidden();
        }
        $this->signInAs($this->member(FarmRole::Finance));
        $this->getJson('/api/v1/breeding-projects')->assertForbidden();
        $this->getJson('/api/v1/breeding-projects/'.$project['id'])->assertForbidden();
        $this->start($cycle)->assertForbidden();

        $this->signInAs($this->member(FarmRole::Manager));
        $this->reverse($project['id'], $outcome['id'])->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/breeding-projects')->assertUnauthorized();
        $this->postJson('/api/v1/breeding-projects', [])->assertUnauthorized();
    }

    public function test_client_cannot_supply_ownership_status_or_population(): void
    {
        $cycle = $this->cycle();
        $this->start($cycle, ['farm_id' => (string) Str::uuid()])->assertStatus(422)->assertJsonValidationErrors('farm_id');
        $this->start($cycle, ['status' => 'completed'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->start($cycle, ['reference_snapshot' => []])->assertStatus(422);
        $project = $this->start($cycle)->json('data');
        $this->outcome($project['id'], ['population_delta' => 500])->assertStatus(422)->assertJsonValidationErrors('population_delta');
        $this->patchJson('/api/v1/breeding-projects/'.$project['id'], ['workflow' => 'pregnancy'])->assertStatus(422);
    }

    public function test_start_is_idempotent_and_listing_filters(): void
    {
        $cycle = $this->cycle();
        $payload = ['production_cycle_id' => $cycle, 'workflow' => 'incubation', 'start_date' => '2026-08-01', 'eggs_set' => 50, 'idempotency_key' => 'start-1'];
        $a = $this->postJson('/api/v1/breeding-projects', $payload)->assertCreated()->json('data');
        $this->postJson('/api/v1/breeding-projects', $payload)->assertCreated()->assertJsonPath('data.id', $a['id']);
        $this->postJson('/api/v1/breeding-projects', ['eggs_set' => 60] + $payload)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame(1, BreedingProject::count());
        $cattle = $this->cycle('cattle');
        $this->start($cattle, ['workflow' => 'pregnancy', 'eggs_set' => null])->assertCreated();
        $this->getJson('/api/v1/breeding-projects?workflow=pregnancy')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/breeding-projects?status=active&production_cycle_id='.$cycle)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/breeding-projects?status=completed')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/breeding-projects?workflow=bogus')->assertStatus(422);
        $this->getJson('/api/v1/breeding-projects?per_page=1')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
    }
}
