<?php

namespace Tests\Feature\Records;

use App\Enums\FarmRole;
use App\Events\Production\RecordCreated;
use App\Models\CropType;
use App\Models\MasterCapability;
use App\Models\OperationalRecord;
use App\Models\OperationType;
use App\Models\PopulationMovement;
use App\Models\ProductionCycle;
use App\Models\RecordAttachment;
use App\Models\Species;
use App\Models\SpeciesCapability;
use App\Services\Measurement\MeasurementConverter;
use App\Services\Records\RecordAttachmentService;
use App\Services\Records\RecordService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Team\TeamTestCase;

class OperationalRecordsTest extends TeamTestCase
{
    private string $cycle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAs($this->owner);
        $this->cycle = $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => 'Layer flock', 'operation_type_id' => OperationType::where('code', 'poultry')->firstOrFail()->id,
            'species_id' => Species::where('code', 'chicken')->firstOrFail()->id, 'initial_population' => 100, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
    }

    private function payload(string $type = 'mortality', array $details = ['quantity' => 10, 'cause' => 'Unknown'], array $extra = []): array
    {
        return array_replace(['production_cycle_id' => $this->cycle, 'type' => $type, 'details' => $details, 'recorded_at' => '2026-02-01T10:00:00+01:00', 'idempotency_key' => (string) Str::uuid()], $extra);
    }

    private function record(array $payload): array
    {
        return $this->postJson('/api/v1/records', $payload)->assertCreated()->json('data');
    }

    private function population(int $expected): void
    {
        $this->getJson('/api/v1/production-cycles/'.$this->cycle)->assertOk()->assertJsonPath('data.livestock.current_population', $expected);
        $this->assertSame($expected, (int) PopulationMovement::where('production_cycle_id', $this->cycle)->sum('quantity'));
    }

    private function reverse(string $id, array $extra = [])
    {
        return $this->postJson('/api/v1/records/'.$id.'/reverse', array_replace(['reason' => 'Incorrect capture', 'recorded_at' => '2026-03-01T10:00:00Z', 'idempotency_key' => (string) Str::uuid()], $extra));
    }

    private function crop(): string
    {
        return $this->postJson('/api/v1/production-cycles', ['kind' => 'crop', 'name' => 'Yam', 'operation_type_id' => OperationType::where('code', 'crops')->firstOrFail()->id,
            'crop_type_id' => CropType::where('code', 'yam')->firstOrFail()->id, 'initial_planting_units' => 800, 'planting_unit_type' => 'heap', 'planting_material_type' => 'tuber', 'planting_date' => '2026-01-01'])->assertCreated()->json('data.id');
    }

    public function test_mortality_extends_initial_ledger_and_preserves_event_time(): void
    {
        $record = $this->record($this->payload());
        $this->population(90);
        $this->assertSame('2026-02-01T09:00:00.000000Z', $record['recorded_at']);
        $this->assertNotSame($record['recorded_at'], $record['created_at']);
        $this->assertSame(-10, $record['population_delta']);
        $this->assertSame('head', $record['measurement']['normalized']['unit']);
        $movement = PopulationMovement::where('operational_record_id', $record['id'])->sole();
        $this->assertSame('record:'.$record['id'], $movement->source_key);
        $this->assertSame($this->owner->id, $movement->created_by);
        $this->assertDatabaseCount('population_movements', 2);
    }

    public function test_zero_is_allowed_but_negative_rolls_back_record_and_movement(): void
    {
        $this->record($this->payload(details: ['quantity' => 100, 'cause' => 'Disease']));
        $this->population(0);
        $this->postJson('/api/v1/records', $this->payload())->assertStatus(409)->assertJsonPath('code', 'insufficient_population');
        $this->assertDatabaseCount('operational_records', 1);
        $this->assertDatabaseCount('population_movements', 2);
    }

    public function test_adjustment_preserves_expected_actual_difference_and_reason(): void
    {
        $record = $this->record($this->payload('population_adjustment', ['expected_population' => 100, 'actual_population' => 120, 'reason' => 'Physical recount']));
        $this->assertSame(20, $record['population_delta']);
        $this->assertSame(['expected_population' => 100, 'actual_population' => 120, 'reason' => 'Physical recount', 'difference' => 20], $record['details']);
        $this->population(120);
        $this->record($this->payload('population_adjustment', ['expected_population' => 120, 'actual_population' => 0, 'reason' => 'Reconciliation']));
        $this->population(0);
        $this->assertSame(100, ProductionCycle::find($this->cycle)->livestock->initial_population);
    }

    public function test_stale_recount_rejected_and_zero_difference_audited(): void
    {
        $this->record($this->payload());
        $this->postJson('/api/v1/records', $this->payload('population_adjustment', ['expected_population' => 100, 'actual_population' => 80, 'reason' => 'Count']))->assertStatus(409)->assertJsonPath('code', 'population_changed');
        $this->record($this->payload('population_adjustment', ['expected_population' => 90, 'actual_population' => 90, 'reason' => 'Verified']));
        $this->assertDatabaseCount('population_movements', 3);
        $this->population(90);
    }

    public function test_reversal_and_linked_replacement_preserve_original(): void
    {
        $record = $this->record($this->payload());
        $reversal = $this->reverse($record['id'])->assertCreated()->json('data');
        $this->population(100);
        $this->assertSame(10, $reversal['population_delta']);
        $this->assertSame($record['id'], $reversal['reverses_record_id']);
        $this->getJson('/api/v1/records/'.$record['id'])->assertOk()->assertJsonPath('data.details.quantity', 10)->assertJsonPath('data.reversed_by_record_id', $reversal['id']);
        $this->record($this->payload(details: ['quantity' => 5, 'cause' => 'Corrected count'], extra: ['corrects_record_id' => $record['id']]));
        $this->population(95);
        $this->reverse($record['id'])->assertStatus(409);
        $this->reverse($reversal['id'])->assertStatus(409);
        $this->assertDatabaseCount('operational_records', 3);
    }

    public function test_reversing_consumed_increase_cannot_make_population_negative(): void
    {
        $record = $this->record($this->payload('population_adjustment', ['expected_population' => 100, 'actual_population' => 150, 'reason' => 'Recount']));
        $this->record($this->payload(details: ['quantity' => 140, 'cause' => 'Disease'], extra: ['recorded_at' => '2026-02-02T10:00:00Z']));
        $this->reverse($record['id'])->assertStatus(409)->assertJsonPath('code', 'insufficient_population');
        $this->population(10);
        $this->assertDatabaseCount('operational_records', 2);
    }

    public function test_backdated_decrease_cannot_make_historical_population_negative(): void
    {
        $this->record($this->payload('population_adjustment', ['expected_population' => 100, 'actual_population' => 200, 'reason' => 'Count'], ['recorded_at' => '2026-03-01T10:00:00Z']));
        $this->postJson('/api/v1/records', $this->payload(details: ['quantity' => 150, 'cause' => 'Loss']))->assertStatus(409)->assertJsonPath('code', 'insufficient_population');
        $this->population(200);
    }

    public function test_retry_keys_deduplicate_records_and_reversals_and_detect_conflicts(): void
    {
        $payload = $this->payload();
        $first = $this->record($payload);
        $second = $this->record($payload);
        $this->assertSame($first['id'], $second['id']);
        $this->postJson('/api/v1/records', array_replace($payload, ['notes' => 'Changed']))->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $key = (string) Str::uuid();
        $one = $this->reverse($first['id'], ['idempotency_key' => $key])->assertCreated()->json('data.id');
        $two = $this->reverse($first['id'], ['idempotency_key' => $key])->assertCreated()->json('data.id');
        $this->assertSame($one, $two);
        $this->assertDatabaseCount('population_movements', 3);
        $this->population(100);
    }

    public function test_failed_write_does_not_consume_retry_key(): void
    {
        $payload = $this->payload(details: ['quantity' => 101, 'cause' => 'Unknown']);
        $this->postJson('/api/v1/records', $payload)->assertStatus(409);
        $payload['details']['quantity'] = 1;
        $this->record($payload);
        $this->population(99);
    }

    public function test_closed_cycle_rejects_records_corrections_and_attachments_but_replays_work(): void
    {
        $payload = $this->payload();
        $record = $this->record($payload);
        $this->postJson('/api/v1/production-cycles/'.$this->cycle.'/close', ['end_date' => '2026-03-01', 'reason' => 'Finished'])->assertOk();
        $this->postJson('/api/v1/records', $this->payload())->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
        $this->reverse($record['id'])->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
        $this->assertSame($record['id'], $this->record($payload)['id']);
        Storage::fake('local');
        $this->post('/api/v1/records/'.$record['id'].'/attachments', ['file' => UploadedFile::fake()->createWithContent('note.txt', 'Evidence')], ['Accept' => 'application/json'])->assertStatus(409);
        $this->postJson('/api/v1/production-cycles/'.$this->cycle.'/reopen', ['reason' => 'Correct'])->assertOk();
        $this->reverse($record['id'])->assertCreated();
    }

    #[DataProvider('invalidEvents')]
    public function test_type_specific_validation(array $details, array $extra): void
    {
        $this->postJson('/api/v1/records', $this->payload(details: $details, extra: $extra))->assertUnprocessable();
        $this->assertDatabaseCount('operational_records', 0);
        $this->population(100);
    }

    public static function invalidEvents(): array
    {
        $good = ['quantity' => 1, 'cause' => 'Unknown'];

        return [
            'zero mortality' => [['quantity' => 0, 'cause' => 'Unknown'], []],
            'fraction' => [['quantity' => 1.5, 'cause' => 'Unknown'], []],
            'bool' => [['quantity' => true, 'cause' => 'Unknown'], []],
            'missing cause' => [['quantity' => 1], []],
            'unknown detail' => [array_merge($good, ['anything' => 'No']), []],
            'wrong type fields' => [[], []],
            'missing offset' => [$good, ['recorded_at' => '2026-02-01 10:00:00']],
            'before start' => [$good, ['recorded_at' => '2025-01-01T00:00:00Z']],
            'future' => [$good, ['recorded_at' => '2099-01-01T00:00:00Z']],
            'unknown type' => [$good, ['type' => 'sale']],
            'client ownership' => [$good, ['farm_id' => 'No']],
            'client delta' => [$good, ['population_delta' => 500]],
            'missing retry' => [$good, ['idempotency_key' => null]],
        ];
    }

    #[DataProvider('measuredTypes')]
    public function test_measurement_types_use_normalizer(string $type, array $details, string $unit, string $value): void
    {
        $record = $this->record($this->payload($type, $details));
        $this->assertSame($unit, $record['measurement']['normalized']['unit']);
        $this->assertSame($value, $record['measurement']['normalized']['quantity']);
        $this->assertArrayHasKey('snapshot', $record['measurement']);
        $this->population(100);
        $this->reverse($record['id'])->assertCreated()->assertJsonPath('data.population_delta', 0);
        $this->assertDatabaseCount('population_movements', 1);
    }

    public static function measuredTypes(): array
    {
        return [
            ['feed_use', ['feed_name' => 'Grower feed', 'components' => [['quantity' => '500', 'unit' => 'g']]], 'g', '500'],
            ['egg_collection', ['components' => [['quantity' => 12, 'unit' => 'piece']]], 'piece', '12'],
            ['weight', ['sample_size' => 10, 'components' => [['quantity' => 2500, 'unit' => 'g']]], 'g', '2500'],
            ['temperature', ['components' => [['quantity' => 32, 'unit' => 'fahrenheit']]], 'celsius', '0'],
            ['water', ['components' => [['quantity' => 500, 'unit' => 'ml']]], 'ml', '500'],
        ];
    }

    public function test_incompatible_measurement_and_wrong_species_capability_rejected(): void
    {
        $this->postJson('/api/v1/records', $this->payload('water', ['components' => [['quantity' => 1, 'unit' => 'kg']]]))->assertUnprocessable()->assertJsonPath('code', 'unit_dimension_mismatch');
        $this->postJson('/api/v1/records', $this->payload('egg_collection', ['components' => [['quantity' => 1, 'unit' => 'head']]]))->assertUnprocessable();
        $this->postJson('/api/v1/records', $this->payload('egg_collection', ['components' => [['quantity' => 0.5, 'unit' => 'piece']]]))->assertUnprocessable();
        $this->postJson('/api/v1/records', $this->payload('milk', ['components' => [['quantity' => 1, 'unit' => 'l']]]))->assertUnprocessable();
        $this->postJson('/api/v1/records', $this->payload('egg_collection', ['components' => [['quantity' => 1, 'unit' => 'crate']]]))->assertUnprocessable()->assertJsonPath('code', 'conversion_context_required');
    }

    public function test_crop_shells_do_not_change_planting_baseline_or_population(): void
    {
        $crop = $this->crop();
        foreach (['irrigation' => ['method' => 'Drip'], 'weeding' => ['method' => 'Manual'], 'fertilizer_application' => ['input_name' => 'Compost', 'method' => 'Broadcast', 'components' => [['quantity' => 5, 'unit' => 'kg']]], 'pest_observation' => ['issue' => 'Leaf damage', 'severity' => 'low']] as $type => $details) {
            $record = $this->record($this->payload($type, $details, ['production_cycle_id' => $crop]));
            $this->assertSame(0, $record['population_delta']);
        }
        $this->postJson('/api/v1/records', $this->payload(extra: ['production_cycle_id' => $crop]))->assertUnprocessable();
        $this->postJson('/api/v1/records', $this->payload('population_adjustment', ['expected_population' => 0, 'actual_population' => 10, 'reason' => 'No'], ['production_cycle_id' => $crop]))->assertUnprocessable();
        $this->postJson('/api/v1/records', $this->payload('irrigation', ['method' => 'Drip']))->assertUnprocessable();
        $this->assertSame(0, PopulationMovement::where('production_cycle_id', $crop)->count());
        $this->getJson('/api/v1/production-cycles/'.$crop)->assertOk()->assertJsonPath('data.crop.initial_planting_units', 800);
    }

    public function test_farm_scope_applies_to_all_records_paths_and_cycle_inputs(): void
    {
        $record = $this->record($this->payload());
        [$other] = $this->otherFarm();
        $this->signInAs($other);
        $this->getJson('/api/v1/records/'.$record['id'])->assertNotFound();
        $this->getJson('/api/v1/records?production_cycle_id='.$this->cycle)->assertNotFound();
        $this->postJson('/api/v1/records', $this->payload())->assertNotFound();
        $this->reverse($record['id'])->assertNotFound();
        $this->getJson('/api/v1/records')->assertOk()->assertJsonPath('meta.total', 0);
        $this->post('/api/v1/records/'.$record['id'].'/attachments', ['file' => UploadedFile::fake()->createWithContent('note.txt', 'Evidence')], ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_worker_common_record_and_manager_only_corrections_permissions(): void
    {
        $this->signInAs($this->member(FarmRole::FarmWorker));
        $record = $this->record($this->payload());
        $this->getJson('/api/v1/records')->assertOk();
        $this->reverse($record['id'])->assertForbidden();
        $this->postJson('/api/v1/records', $this->payload('population_adjustment', ['expected_population' => 90, 'actual_population' => 100, 'reason' => 'Count']))->assertForbidden();
        $this->signInAs($this->member(FarmRole::Finance));
        $this->getJson('/api/v1/records')->assertOk();
        $this->postJson('/api/v1/records', $this->payload())->assertForbidden();
        $this->signInAs($this->member(FarmRole::Manager));
        $this->reverse($record['id'])->assertCreated();
    }

    public function test_service_authorization_cannot_be_bypassed(): void
    {
        $finance = $this->member(FarmRole::Finance);
        $this->expectException(ApiHttpException::class);
        app(RecordService::class)->create(new FarmContext($this->farm, $this->membershipOf($finance)), $this->payload());
    }

    public function test_private_evidence_is_validated_deduplicated_scoped_and_downloadable(): void
    {
        Storage::fake('local');
        $record = $this->record($this->payload());
        $url = '/api/v1/records/'.$record['id'].'/attachments';
        $upload = fn () => UploadedFile::fake()->createWithContent('notes.txt', 'Farm evidence');
        $first = $this->post($url, ['file' => $upload()], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->post($url, ['file' => $upload()], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.id', $first['id']);
        $file = RecordAttachment::sole();
        Storage::disk('local')->assertExists($file->path);
        $this->assertArrayNotHasKey('path', $first);
        $this->get($url.'/'.$file->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post($url, ['file' => UploadedFile::fake()->createWithContent('script.php', '<?php evil();')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post($url, ['file' => UploadedFile::fake()->createWithContent('fake.png', '<script>alert(1)</script>')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post($url, ['file' => UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable();
        [$other] = $this->otherFarm();
        $this->signInAs($other);
        $this->getJson($url.'/'.$file->id)->assertNotFound();
    }

    public function test_schemas_listing_and_date_filters(): void
    {
        $this->getJson('/api/v1/master/record-types')->assertOk()->assertJsonCount(13, 'data');
        $this->getJson('/api/v1/record-types/mortality/schema')->assertOk()->assertJsonPath('data.population_effect', 'decrease');
        $this->getJson('/api/v1/record-types/not-a-type/schema')->assertNotFound();
        $this->record($this->payload());
        $this->record($this->payload('general_note', ['text' => 'Observed flock'], ['recorded_at' => '2026-02-02T01:00:00Z']));
        $this->getJson('/api/v1/records?recorded_from=2026-02-01&recorded_to=2026-02-01')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/records?type=general_note&per_page=1')->assertOk()->assertJsonPath('data.0.type', 'general_note');
    }

    public function test_model_history_is_immutable_and_corrupt_ledger_blocks_close(): void
    {
        $record = $this->record($this->payload());
        try {
            OperationalRecord::find($record['id'])->update(['population_delta' => 500]);
            $this->fail('Mutable history');
        } catch (\LogicException) {
        }
        try {
            OperationalRecord::find($record['id'])->delete();
            $this->fail('Deleted history');
        } catch (\LogicException) {
        }
        DB::table('population_movements')->where('operational_record_id', $record['id'])->update(['quantity' => -5]);
        $this->postJson('/api/v1/production-cycles/'.$this->cycle.'/close', ['end_date' => '2026-03-01', 'reason' => 'Finished'])->assertStatus(409)->assertJsonPath('code', 'cycle_reconciliation_failed');
    }

    public function test_compound_eggs_snapshot_survives_conversion_change_and_retry(): void
    {
        $context = $this->postJson('/api/v1/settings/measurement-contexts', ['name' => 'Eggs'])->assertCreated()->json('data.id');
        $conversion = $this->postJson('/api/v1/settings/package-conversions', ['context_type' => 'custom', 'context_id' => $context, 'package_unit' => 'crate', 'target_unit' => 'piece', 'quantity_per_package' => 30])->assertCreated()->json('data.id');
        $payload = $this->payload('egg_collection', ['components' => [['quantity' => 3, 'unit' => 'crate'], ['quantity' => 14, 'unit' => 'piece']], 'context' => ['type' => 'custom', 'id' => $context]]);
        $record = $this->record($payload);
        $this->assertSame('104', $record['measurement']['normalized']['quantity']);
        $this->patchJson('/api/v1/settings/package-conversions/'.$conversion, ['quantity_per_package' => 24])->assertOk();
        $this->assertEquals($record['measurement'], $this->record($payload)['measurement']);
        $snapshot = app(MeasurementConverter::class)->replay($record['measurement']['snapshot']);
        $this->assertSame('104', $snapshot->normalized->value);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_fish_population_and_milk_capability_work_without_species_conditionals(): void
    {
        foreach (['fish', 'cattle'] as $code) {
            $species = Species::where('code', $code)->firstOrFail();
            $id = $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => $code, 'operation_type_id' => $species->operation_type_id, 'species_id' => $species->id, 'initial_population' => 20, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
            if ($code === 'fish') {
                $this->record($this->payload(extra: ['production_cycle_id' => $id]));
                $this->getJson('/api/v1/production-cycles/'.$id)->assertOk()->assertJsonPath('data.livestock.current_population', 10);
            } else {
                SpeciesCapability::updateOrCreate(['species_id' => $species->id, 'capability_id' => MasterCapability::where('code', 'produces_milk')->firstOrFail()->id], ['enabled' => true]);
                $record = $this->record($this->payload('milk', ['components' => [['quantity' => '2.5', 'unit' => 'l']]], ['production_cycle_id' => $id]));
                $this->assertSame('2500', $record['measurement']['normalized']['quantity']);
            }
        }
    }

    public function test_adjustment_and_reversal_dates_and_correction_links_are_validated(): void
    {
        $record = $this->record($this->payload());
        $this->reverse($record['id'], ['recorded_at' => '2026-01-15T00:00:00Z'])->assertUnprocessable();
        $this->postJson('/api/v1/records', $this->payload(extra: ['corrects_record_id' => $record['id']]))->assertStatus(409)->assertJsonPath('code', 'invalid_correction');
        $this->postJson('/api/v1/records', $this->payload('population_adjustment', ['expected_population' => 90, 'actual_population' => 91, 'reason' => 'Count'], ['recorded_at' => '2026-01-15T00:00:00Z']))->assertUnprocessable();
        $this->postJson('/api/v1/production-cycles/'.$this->cycle.'/close', ['end_date' => '2026-01-15', 'reason' => 'Too early'])->assertUnprocessable();
        $this->reverse($record['id'])->assertCreated();
        $this->record($this->payload(extra: ['corrects_record_id' => $record['id']]));
        $this->postJson('/api/v1/records', $this->payload(extra: ['corrects_record_id' => $record['id']]))->assertStatus(409);
    }

    public function test_evidence_limit_and_compensation_after_database_failure(): void
    {
        Storage::fake('local');
        config(['records.attachments.max_per_record' => 1]);
        $record = $this->record($this->payload());
        $url = '/api/v1/records/'.$record['id'].'/attachments';
        $this->post($url, ['file' => UploadedFile::fake()->createWithContent('first.txt', 'First evidence')], ['Accept' => 'application/json'])->assertCreated();
        $this->post($url, ['file' => UploadedFile::fake()->createWithContent('second.txt', 'Second evidence')], ['Accept' => 'application/json'])->assertStatus(409)->assertJsonPath('code', 'attachment_limit_reached');
        $this->assertCount(1, Storage::disk('local')->allFiles());
        config(['records.attachments.max_per_record' => 2]);
        RecordAttachment::creating(fn () => throw new \RuntimeException('Simulated database failure'));
        try {
            app(RecordAttachmentService::class)->attach(new FarmContext($this->farm, $this->membershipOf($this->owner)), $record['id'], UploadedFile::fake()->createWithContent('second.txt', 'Second evidence'));
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated database failure', $e->getMessage());
        } finally {
            RecordAttachment::getEventDispatcher()->forget('eloquent.creating: '.RecordAttachment::class);
        }
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('record_attachments', 1);
    }

    public function test_record_movement_failure_is_atomic_and_no_event_escapes(): void
    {
        Event::fake([RecordCreated::class]);
        $this->postJson('/api/v1/records', $this->payload(details: ['quantity' => 101, 'cause' => 'Unknown']))->assertStatus(409);
        Event::assertNotDispatched(RecordCreated::class);
        $this->assertDatabaseCount('operational_records', 0);
        $this->assertDatabaseCount('population_movements', 1);
    }
}
