<?php

namespace Tests\Feature\Production;

use App\Enums\FarmRole;
use App\Enums\Limit;
use App\Events\Production\CycleChanged;
use App\Models\Breed;
use App\Models\CropType;
use App\Models\CropVariety;
use App\Models\Entitlement;
use App\Models\FarmMembership;
use App\Models\Location;
use App\Models\OperationType;
use App\Models\PlanEntitlement;
use App\Models\PopulationMovement;
use App\Models\ProductionArea;
use App\Models\ProductionCycle;
use App\Models\ProductionCycleEvent;
use App\Models\ReferenceValue;
use App\Models\Species;
use App\Models\User;
use App\Services\Production\CycleService;
use App\Services\Subscription\EntitlementService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use Database\Seeders\ActiveCycleLimitSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Team\TeamTestCase;

class ProductionCyclesTest extends TeamTestCase
{
    private const URL = '/api/v1/production-cycles';

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAs($this->owner);
    }

    private function livestock(array $overrides = []): array
    {
        return array_replace(['kind' => 'livestock', 'name' => 'October Broilers', 'operation_type_id' => OperationType::where('code', 'poultry')->firstOrFail()->id, 'species_id' => Species::where('code', 'chicken')->firstOrFail()->id, 'production_purpose' => 'breeding', 'initial_population' => 500, 'start_date' => '2026-01-10'], $overrides);
    }

    private function crop(array $overrides = []): array
    {
        return array_replace(['kind' => 'crop', 'name' => 'Yam Project', 'operation_type_id' => OperationType::where('code', 'crops')->firstOrFail()->id, 'crop_type_id' => CropType::where('code', 'yam')->firstOrFail()->id, 'planting_material_type' => 'tuber', 'planting_unit_type' => 'heap', 'initial_planting_units' => 800, 'planting_date' => '2026-01-10'], $overrides);
    }

    private function create(array $data): array
    {
        return $this->postJson(self::URL, $data)->assertCreated()->json('data');
    }

    private function area(array $overrides = []): ProductionArea
    {
        $area = new ProductionArea;
        $area->forceFill(array_replace(['farm_id' => $this->farm->id, 'name' => 'Pen A', 'type' => 'pen', 'is_active' => true], $overrides))->save();

        return $area;
    }

    public function test_livestock_creation_is_uuid7_and_ledger_explained_with_historical_date(): void
    {
        $cycle = $this->create($this->livestock());
        $this->assertSame('7', $cycle['id'][14]);
        $this->assertSame(500, $cycle['livestock']['initial_population']);
        $this->assertSame(500, $cycle['livestock']['current_population']);
        $this->assertSame('population_movements', $cycle['livestock']['population_basis']);
        $this->assertSame('2026-01-10', $cycle['start_date']);
        $this->assertSame('active', $cycle['status']);
        $this->assertNull($cycle['crop']);
        $this->assertNull($cycle['production_area']);
        $this->assertMatchesRegularExpression('/^BAT-\d{4}-00001$/', $cycle['reference']);
        $movement = PopulationMovement::sole();
        $this->assertSame(500, $movement->quantity);
        $this->assertSame('2026-01-09 23:00:00', $movement->recorded_at->format('Y-m-d H:i:s'));
        $this->assertNotEquals($movement->created_at, $movement->recorded_at);
        $this->assertSame('initial', $movement->source_key);
        $this->assertDatabaseCount('production_cycles', 1);
        $this->assertDatabaseCount('livestock_batch_details', 1);
        $this->assertDatabaseCount('production_cycle_events', 1);
        $this->assertDatabaseCount('production_areas', 0);
        $this->assertFalse(Schema::hasColumn('production_cycles', 'current_population'));
        $this->getJson(self::URL.'/'.$cycle['id'].'/summary')->assertOk()->assertJsonPath('data.livestock.current_population', 500);
    }

    public function test_crop_baseline_is_not_material_consumption_and_area_is_separate(): void
    {
        $cycle = $this->create($this->crop(['area' => ['quantity' => '2', 'unit' => 'hectare']]));
        $this->assertSame(800, $cycle['crop']['initial_planting_units']);
        $this->assertSame('heap', $cycle['crop']['planting_unit_type']);
        $this->assertSame('tuber', $cycle['crop']['planting_material_type']);
        $this->assertSame('20000', $cycle['crop']['area']['normalized']['quantity']);
        $this->assertSame('sq_m', $cycle['crop']['area']['normalized']['unit']);
        $this->assertSame('2026-01-10', $cycle['planting_date']);
        $this->assertNull($cycle['start_date']);
        $this->assertNull($cycle['livestock']);
        $this->assertArrayNotHasKey('material_quantity', $cycle['crop']);
        $this->assertDatabaseCount('population_movements', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('operational_records', 0);
        $detail = ProductionCycle::first()->crop;
        $this->assertSame('20000.000000', $detail->area_normalized_quantity);
        $this->assertArrayHasKey('snapshot', $detail->area_measurement);
        $this->assertSame('planting_unit', $detail->baseline_measurement['normalized']['unit']);
        $this->patchJson(self::URL.'/'.$cycle['id'], ['area' => ['quantity' => '120', 'unit' => 'sq_m']])->assertOk()->assertJsonPath('data.crop.area.normalized.quantity', '120')->assertJsonPath('data.crop.initial_planting_units', 800);
    }

    public static function invalidCounts(): array
    {
        return [['livestock', 0], ['livestock', -1], ['livestock', 500.5], ['livestock', null], ['livestock', 'kg'], ['livestock', 1000000000000], ['crop', 0], ['crop', -1], ['crop', 0.5], ['crop', null], ['livestock', true], ['crop', true]];
    }

    #[DataProvider('invalidCounts')]
    public function test_positive_whole_baselines_are_required(string $kind, mixed $count): void
    {
        $key = $kind === 'livestock' ? 'initial_population' : 'initial_planting_units';
        $data = $this->$kind([$key => $count]);
        $this->postJson(self::URL, $data)->assertUnprocessable()->assertJsonValidationErrors($key);
        $this->assertDatabaseCount('production_cycles', 0);
        $this->assertDatabaseCount('population_movements', 0);
    }

    public static function requiredFields(): array
    {
        return [['livestock', 'species_id'], ['livestock', 'operation_type_id'], ['livestock', 'start_date'], ['crop', 'crop_type_id'], ['crop', 'planting_material_type'], ['crop', 'planting_unit_type'], ['crop', 'planting_date']];
    }

    #[DataProvider('requiredFields')]
    public function test_domain_fields_are_required(string $kind, string $field): void
    {
        $data = $this->$kind();
        unset($data[$field]);
        $this->postJson(self::URL, $data)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_master_relationships_tracking_models_and_fishery(): void
    {
        $cattle = Species::where('code', 'cattle')->firstOrFail();
        $this->postJson(self::URL, $this->livestock(['species_id' => $cattle->id]))->assertUnprocessable()->assertJsonValidationErrors('species_id');
        $this->postJson(self::URL, $this->livestock(['operation_type_id' => OperationType::where('code', 'crops')->first()->id]))->assertUnprocessable()->assertJsonValidationErrors('operation_type_id');
        $this->postJson(self::URL, $this->crop(['operation_type_id' => $cattle->operation_type_id]))->assertUnprocessable();
        $fish = Species::where('code', 'fish')->firstOrFail();
        $batch = $this->create($this->livestock(['name' => 'Catfish Batch A', 'operation_type_id' => $fish->operation_type_id, 'species_id' => $fish->id, 'production_purpose' => 'table_fish', 'initial_population' => 2000]));
        $this->assertSame('population', $batch['operation']['tracking_model']);
        $this->assertSame(2000, $batch['livestock']['current_population']);
        $this->assertSame('fish', $batch['livestock']['species']['code']);
        $this->assertDatabaseCount('production_cycles', 1);
    }

    public function test_breeds_system_own_foreign_wrong_species_and_inactive(): void
    {
        $data = $this->livestock();
        $breed = Breed::create(['species_id' => $data['species_id'], 'name' => 'Custom Broiler', 'farm_id' => $this->farm->id, 'is_active' => true]);
        $cycle = $this->create($this->livestock(['breed_id' => $breed->id]));
        $this->assertSame($breed->id, $cycle['livestock']['breed']['id']);
        $breed->update(['is_active' => false]);
        $this->postJson(self::URL, $this->livestock(['name' => 'Second', 'breed_id' => $breed->id]))->assertUnprocessable()->assertJsonValidationErrors('breed_id');
        $this->patchJson(self::URL.'/'.$cycle['id'], ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.livestock.breed.is_active', false);
        [, $foreign] = $this->otherFarm();
        $other = Breed::create(['species_id' => $data['species_id'], 'name' => 'Other Breed', 'farm_id' => $foreign->id, 'is_active' => true]);
        $this->postJson(self::URL, $this->livestock(['breed_id' => $other->id]))->assertNotFound();
        $wrong = Breed::create(['species_id' => Species::where('code', 'cattle')->first()->id, 'name' => 'Cattle Breed', 'is_active' => true]);
        $this->postJson(self::URL, $this->livestock(['breed_id' => $wrong->id]))->assertUnprocessable();
        $system = Breed::create(['species_id' => $data['species_id'], 'name' => 'System Breed', 'is_active' => true]);
        $this->create($this->livestock(['name' => 'System selection', 'breed_id' => $system->id]));
    }

    public function test_varieties_system_own_foreign_wrong_crop_and_inactive(): void
    {
        $data = $this->crop();
        $variety = CropVariety::create(['crop_type_id' => $data['crop_type_id'], 'name' => 'Custom Yam', 'farm_id' => $this->farm->id, 'is_active' => true]);
        $cycle = $this->create($this->crop(['crop_variety_id' => $variety->id]));
        $this->assertSame($variety->id, $cycle['crop']['variety']['id']);
        $variety->update(['is_active' => false]);
        $this->postJson(self::URL, $this->crop(['name' => 'Second', 'crop_variety_id' => $variety->id]))->assertUnprocessable()->assertJsonValidationErrors('crop_variety_id');
        $this->getJson(self::URL.'/'.$cycle['id'])->assertOk()->assertJsonPath('data.crop.variety.is_active', false);
        [, $foreign] = $this->otherFarm();
        $other = CropVariety::create(['crop_type_id' => $data['crop_type_id'], 'name' => 'Other Yam', 'farm_id' => $foreign->id, 'is_active' => true]);
        $this->postJson(self::URL, $this->crop(['name' => 'Foreign selection', 'crop_variety_id' => $other->id]))->assertNotFound();
        $wrong = CropVariety::create(['crop_type_id' => CropType::where('code', 'maize')->first()->id, 'name' => 'Maize Variety', 'is_active' => true]);
        $this->postJson(self::URL, $this->crop(['name' => 'Wrong selection', 'crop_variety_id' => $wrong->id]))->assertUnprocessable();
        $system = CropVariety::create(['crop_type_id' => $data['crop_type_id'], 'name' => 'System Yam', 'is_active' => true]);
        $this->create($this->crop(['name' => 'System selection', 'crop_variety_id' => $system->id]));
    }

    public function test_reference_codes_are_catalogue_driven_and_inactive_rejected(): void
    {
        $this->postJson(self::URL, $this->crop(['planting_material_type' => 'kg']))->assertUnprocessable()->assertJsonValidationErrors('planting_material_type');
        $this->postJson(self::URL, $this->crop(['planting_unit_type' => 'seed']))->assertUnprocessable()->assertJsonValidationErrors('planting_unit_type');
        ReferenceValue::where('list', 'planting_unit_type')->where('code', 'heap')->update(['is_active' => false]);
        $this->postJson(self::URL, $this->crop())->assertUnprocessable();
        ReferenceValue::create(['list' => 'planting_unit_type', 'code' => 'bed', 'name' => 'Bed', 'is_active' => true]);
        $this->create($this->crop(['planting_unit_type' => 'bed']));
    }

    public static function invalidAreaUnits(): array
    {
        return [['kg'], ['l'], ['head'], ['planting_unit']];
    }

    #[DataProvider('invalidAreaUnits')]
    public function test_crop_area_requires_area_dimension(string $unit): void
    {
        $this->postJson(self::URL, $this->crop(['area' => ['quantity' => '2', 'unit' => $unit]]))->assertUnprocessable()->assertJsonPath('code', 'unit_dimension_mismatch');
        $this->assertDatabaseCount('production_cycles', 0);
        $this->assertDatabaseCount('crop_project_details', 0);
    }

    public function test_optional_places_shared_occupancy_and_assignment_history(): void
    {
        $area = $this->area();
        $one = $this->create($this->livestock(['production_area_id' => $area->id]));
        $two = $this->create($this->crop(['production_area_id' => $area->id]));
        $this->assertSame($one['production_area']['id'], $two['production_area']['id']);
        $area->update(['is_active' => false]);
        $this->postJson(self::URL, $this->crop(['name' => 'Another', 'production_area_id' => $area->id]))->assertConflict()->assertJsonPath('code', 'location_inactive');
        $this->patchJson(self::URL.'/'.$one['id'], ['notes' => 'Historical place'])->assertOk();
        $this->patchJson(self::URL.'/'.$one['id'], ['production_area_id' => null])->assertOk()->assertJsonPath('data.production_area', null);
        $this->getJson(self::URL.'/'.$one['id'].'/activity')->assertOk()->assertJsonPath('data.0.changes.production_area_id.old', $area->id)->assertJsonPath('data.0.changes.production_area_id.new', null);
        [, $foreign] = $this->otherFarm();
        $foreignArea = $this->area(['farm_id' => $foreign->id]);
        $this->postJson(self::URL, $this->crop(['production_area_id' => $foreignArea->id]))->assertNotFound();
        $this->patchJson(self::URL.'/'.$one['id'], ['production_area_id' => $foreignArea->id])->assertNotFound();
        $this->getJson(self::URL.'?production_area_id='.$foreignArea->id)->assertNotFound();
    }

    public function test_baselines_ownership_current_population_and_status_are_not_editable(): void
    {
        $cycle = $this->create($this->livestock());
        foreach (CycleService::BASELINE_FIELDS as $field) {
            $this->patchJson(self::URL.'/'.$cycle['id'], [$field => null])->assertConflict()->assertJsonPath('code', 'baseline_locked');
        }
        foreach (['farm_id' => Str::uuid7()->toString(), 'current_population' => 12, 'status' => 'closed', 'is_active' => false, 'material_quantity' => 800] as $field => $value) {
            $this->patchJson(self::URL.'/'.$cycle['id'], [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->patchJson(self::URL.'/'.$cycle['id'], ['name' => '  Broilers   renamed ', 'notes' => 'Updated'])->assertOk()->assertJsonPath('data.id', $cycle['id'])->assertJsonPath('data.name', 'Broilers renamed');
        $this->deleteJson(self::URL.'/'.$cycle['id'])->assertStatus(405);
    }

    public function test_close_reopen_preserves_population_history_and_locks_edits(): void
    {
        $cycle = $this->create($this->livestock());
        $url = self::URL.'/'.$cycle['id'];
        $this->postJson($url.'/reopen', ['reason' => 'Already active'])->assertConflict()->assertJsonPath('code', 'invalid_status_transition');
        $this->postJson($url.'/close', ['end_date' => '2025-01-01', 'reason' => 'Too early'])->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->postJson($url.'/close', ['end_date' => '2026-02-01', 'reason' => 'Season finished'])->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.livestock.current_population', 500);
        $this->patchJson($url, ['notes' => 'No'])->assertConflict()->assertJsonPath('code', 'cycle_closed');
        $this->postJson($url.'/close', ['end_date' => '2026-02-01', 'reason' => 'Again'])->assertConflict();
        $this->getJson($url)->assertOk();
        $this->postJson($url.'/reopen', ['reason' => 'Resume work'])->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.end_date', null);
        $this->assertDatabaseCount('population_movements', 1);
        $this->assertDatabaseCount('production_cycle_events', 3);
        $this->getJson($url.'/activity?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
    }

    public function test_reconciliation_failure_leaves_cycle_active(): void
    {
        $cycle = $this->create($this->livestock());
        DB::table('population_movements')->where('production_cycle_id', $cycle['id'])->update(['quantity' => 499]);
        $this->postJson(self::URL.'/'.$cycle['id'].'/close', ['end_date' => '2026-02-01', 'reason' => 'Close'])->assertConflict()->assertJsonPath('code', 'cycle_reconciliation_failed');
        $this->getJson(self::URL.'/'.$cycle['id'])->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.livestock.current_population', 499);
    }

    public static function roles(): array
    {
        return [['owner', true], ['manager', true], ['farm_worker', false], ['finance', false]];
    }

    #[DataProvider('roles')]
    public function test_permissions_for_reads_and_all_writes(string $role, bool $write): void
    {
        $cycle = $this->create($this->livestock());
        $url = self::URL.'/'.$cycle['id'];
        $user = $role === 'owner' ? $this->owner : $this->member(FarmRole::from($role));
        $this->signInAs($user);
        foreach ([self::URL, $url, $url.'/summary', $url.'/activity'] as $path) {
            $this->getJson($path)->assertOk();
        }
        $this->postJson(self::URL, $this->crop())->assertStatus($write ? 201 : 403);
        $this->patchJson($url, ['notes' => 'Edit'])->assertStatus($write ? 200 : 403);
        $this->postJson($url.'/close', ['end_date' => '2026-02-01', 'reason' => 'Finish'])->assertStatus($write ? 200 : 403);
        $this->postJson($url.'/reopen', ['reason' => 'Resume'])->assertStatus($write ? 200 : 403);
    }

    public function test_cross_farm_cycles_are_not_exposed_and_header_selection_works(): void
    {
        $cycle = $this->create($this->livestock());
        [$other, $farm] = $this->otherFarm();
        $this->signInAs($other);
        $this->getJson(self::URL)->assertOk()->assertJsonCount(0, 'data');
        foreach (['', '/summary', '/activity'] as $suffix) {
            $this->getJson(self::URL.'/'.$cycle['id'].$suffix)->assertNotFound();
        }
        $this->patchJson(self::URL.'/'.$cycle['id'], ['notes' => 'No'])->assertNotFound();
        $this->postJson(self::URL.'/'.$cycle['id'].'/close', ['end_date' => '2026-02-01', 'reason' => 'No'])->assertNotFound();
        $this->getJson(self::URL, ['X-Farm-Id' => $this->farm->id])->assertForbidden();
        $member = $this->member(FarmRole::Manager, $farm);
        FarmMembership::create(['user_id' => $member->id, 'farm_id' => $this->farm->id, 'role' => 'manager']);
        $this->signInAs($member)->getJson(self::URL, ['X-Farm-Id' => $this->farm->id])->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_filters_pagination_literal_search_and_closed_names(): void
    {
        $area = $this->area();
        $one = $this->create($this->livestock(['name' => 'Batch % A', 'production_area_id' => $area->id]));
        $two = $this->create($this->crop());
        $this->getJson(self::URL.'?kind=crop')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $two['id']);
        $this->getJson(self::URL.'?species_id='.$this->livestock()['species_id'])->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::URL.'?crop_type_id='.$this->crop()['crop_type_id'])->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::URL.'?operation_type_id='.$this->livestock()['operation_type_id'])->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::URL.'?production_area_id='.$area->id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::URL.'?search=%25')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::URL.'?per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson(self::URL.'?per_page=101')->assertUnprocessable();
        $this->getJson(self::URL.'?status=nonsense')->assertUnprocessable();
        $this->postJson(self::URL.'/'.$one['id'].'/close', ['end_date' => '2026-02-01', 'reason' => 'Finish'])->assertOk();
        $this->getJson(self::URL.'?status=closed')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson(self::URL, $this->livestock(['name' => ' batch  % A ']))->assertConflict()->assertJsonPath('code', 'duplicate_cycle_name');
    }

    public function test_active_cycle_capacity_counts_both_kinds_and_reopen_rechecks(): void
    {
        $a = $this->create($this->livestock());
        $this->create($this->crop());
        $this->create($this->livestock(['name' => 'Third']));
        $this->postJson(self::URL, $this->crop(['name' => 'Fourth']))->assertConflict()->assertJsonPath('code', 'plan_limit_reached')->assertJsonPath('details.usage', 3);
        $this->postJson(self::URL.'/'.$a['id'].'/close', ['end_date' => '2026-02-01', 'reason' => 'Finish'])->assertOk();
        $this->create($this->crop(['name' => 'Fourth']));
        $this->postJson(self::URL.'/'.$a['id'].'/reopen', ['reason' => 'Resume'])->assertConflict()->assertJsonPath('code', 'plan_limit_reached');
        $this->onPlan('farm-pro');
        $this->postJson(self::URL.'/'.$a['id'].'/reopen', ['reason' => 'Resume'])->assertOk();
        $this->assertSame(4, app(EntitlementService::class)->usage($this->farm, Limit::ActiveCycles));
        $this->onPlan('free');
        $this->getJson(self::URL)->assertOk()->assertJsonCount(4, 'data');
        $this->patchJson(self::URL.'/'.$a['id'], ['notes' => 'Still editable'])->assertOk();
    }

    public function test_inactive_species_operation_crop_and_ancestor_are_rejected(): void
    {
        $species = Species::where('code', 'chicken')->first();
        $species->update(['is_active' => false]);
        $this->postJson(self::URL, $this->livestock())->assertUnprocessable()->assertJsonValidationErrors('species_id');
        $species->update(['is_active' => true]);
        $operation = $species->operationType;
        $operation->update(['is_active' => false]);
        $this->postJson(self::URL, $this->livestock())->assertUnprocessable()->assertJsonValidationErrors('operation_type_id');
        $operation->update(['is_active' => true]);
        $crop = CropType::where('code', 'yam')->first();
        $crop->update(['is_active' => false]);
        $this->postJson(self::URL, $this->crop())->assertUnprocessable()->assertJsonValidationErrors('crop_type_id');
        $location = new Location;
        $location->forceFill(['farm_id' => $this->farm->id, 'name' => 'Inactive Site', 'type' => 'site', 'is_active' => false])->save();
        $area = $this->area(['location_id' => $location->id]);
        $this->postJson(self::URL, $this->livestock(['production_area_id' => $area->id]))->assertConflict()->assertJsonPath('code', 'location_inactive');
        $this->assertDatabaseCount('production_cycles', 0);
    }

    public function test_crop_and_livestock_fields_cannot_be_mixed_and_dates_are_validated(): void
    {
        $this->postJson(self::URL, $this->crop(['initial_population' => 20]))->assertUnprocessable();
        $this->postJson(self::URL, $this->livestock(['initial_planting_units' => 20]))->assertUnprocessable();
        $this->postJson(self::URL, $this->crop(['material_quantity' => 800]))->assertUnprocessable();
        $this->postJson(self::URL, $this->livestock(['start_date' => '2026-02-30']))->assertUnprocessable();
        $this->postJson(self::URL, $this->crop(['expected_germination_date' => '2025-12-31']))->assertUnprocessable();
        $cycle = $this->create($this->crop(['initial_planting_units' => 50]));
        $url = self::URL.'/'.$cycle['id'];
        $this->patchJson($url, ['expected_end_date' => '2025-12-31'])->assertUnprocessable();
        $this->patchJson($url, ['area' => ['quantity' => 0, 'unit' => 'hectare']])->assertUnprocessable();
        $this->patchJson($url, ['area' => ['quantity' => -2, 'unit' => 'hectare']])->assertUnprocessable();
        $this->patchJson($url, ['expected_germination_date' => '2026-01-15', 'expected_end_date' => '2026-08-10'])->assertOk();
        $this->postJson($url.'/close', ['end_date' => now('Africa/Lagos')->addDay()->toDateString(), 'reason' => 'Future'])->assertUnprocessable();
        $this->postJson($url.'/close', ['end_date' => '2026-08-10', 'reason' => 'Season ended'])->assertOk()->assertJsonPath('data.crop.initial_planting_units', 50);
        $this->getJson($url)->assertOk()->assertJsonPath('data.status', 'closed');
        $this->postJson($url.'/reopen', ['reason' => 'Resume'])->assertOk();
        $this->patchJson($url, ['area' => null, 'expected_end_date' => null, 'expected_germination_date' => null])->assertOk()->assertJsonPath('data.crop.area', null);
    }

    public function test_service_authorization_and_model_history_protection(): void
    {
        $worker = $this->member(FarmRole::FarmWorker);
        $ctx = new FarmContext($this->farm, $this->membershipOf($worker));
        try {
            app(CycleService::class)->create($ctx, $worker, $this->livestock());
            $this->fail('Worker service call should fail.');
        } catch (ApiHttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $cycle = $this->create($this->livestock());
        $model = ProductionCycle::findOrFail($cycle['id']);
        foreach ([$model, $model->livestock, PopulationMovement::sole(), ProductionCycleEvent::sole()] as $row) {
            try {
                $row->delete();
                $this->fail('Historical row deletion must fail.');
            } catch (\LogicException) {
                $this->assertTrue($row->fresh()->exists);
            }
        }
        try {
            $model->livestock->update(['initial_population' => 400]);
            $this->fail('Baseline edit must fail.');
        } catch (\LogicException) {
            $this->assertSame(500, $model->livestock->fresh()->initial_population);
        }
    }

    public function test_unauthenticated_unverified_and_onboarding_gates(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->guard('web')->logout();
        $this->getJson(self::URL)->assertUnauthorized();
        $user = User::factory()->unverified()->create();
        $this->signInAs($user)->getJson(self::URL)->assertForbidden();
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->signInAs($user)->postJson(self::URL, $this->livestock())->assertForbidden()->assertJsonPath('code', 'onboarding_required');
    }

    public function test_database_composite_fk_and_initial_movement_uniqueness(): void
    {
        $cycle = $this->create($this->livestock());
        [, $foreign] = $this->otherFarm();
        $area = $this->area(['farm_id' => $foreign->id]);
        try {
            DB::table('production_cycles')->where('id', $cycle['id'])->update(['production_area_id' => $area->id]);
            $this->fail('Foreign area must fail at database level.');
        } catch (QueryException) {
            $this->assertNull(ProductionCycle::find($cycle['id'])->production_area_id);
        }
        $movement = PopulationMovement::sole();
        try {
            $movement->replicate()->save();
            $this->fail('Duplicate initial movement must fail.');
        } catch (UniqueConstraintViolationException) {
            $this->assertDatabaseCount('population_movements', 1);
        }
    }

    public function test_list_queries_are_bounded_and_limit_seeder_preserves_configuration(): void
    {
        $this->onPlan('farm-pro');
        $this->create($this->livestock());
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson(self::URL)->assertOk();
        $oneCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 5; $i++) {
            $this->create($this->livestock(['name' => 'Batch '.$i]));
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson(self::URL)->assertOk()->assertJsonCount(6, 'data');
        $manyCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($oneCount + 2, $manyCount);
        $row = PlanEntitlement::where('plan_id', $this->farm->subscription->plan_id)->where('entitlement_id', Entitlement::where('key', 'active_cycles')->value('id'))->firstOrFail();
        $row->update(['is_unlimited' => false, 'limit_value' => 17]);
        (new ActiveCycleLimitSeeder)->run();
        $this->assertSame(17, $row->fresh()->limit_value);
    }

    public function test_transaction_rollback_and_noop_events(): void
    {
        Event::fake([CycleChanged::class]);
        $cycle = $this->create($this->livestock());
        Event::assertDispatched(CycleChanged::class, 1);
        $this->patchJson(self::URL.'/'.$cycle['id'], ['name' => $cycle['name']])->assertOk();
        Event::assertDispatched(CycleChanged::class, 1);
        $this->assertDatabaseCount('production_cycle_events', 1);
        DB::beginTransaction();
        $this->create($this->crop());
        DB::rollBack();
        Event::assertDispatched(CycleChanged::class, 1);
        $this->assertDatabaseCount('production_cycles', 1);
        $this->assertDatabaseCount('crop_project_details', 0);
        $this->postJson(self::URL, $this->crop(['area' => ['quantity' => 1, 'unit' => 'kg']]))->assertUnprocessable();
        $this->assertDatabaseCount('production_cycles', 1);
        $this->assertDatabaseCount('production_cycle_events', 1);
    }
}
