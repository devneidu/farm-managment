<?php

namespace Tests\Feature\Crops;

use App\Enums\FarmRole;
use App\Models\CropType;
use App\Models\InventoryMovement;
use App\Models\OperationalRecord;
use App\Models\OperationType;
use App\Models\PopulationMovement;
use App\Models\Species;
use Illuminate\Support\Str;
use Tests\Feature\Team\TeamTestCase;

/** Phase 13: land preparation, planting, establishment/survival, growth stage, crop loss, harvest and the crop project detail. */
class CropOperationsTest extends TeamTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAs($this->owner);
    }

    private function at(int $hoursAgo = 1): string
    {
        return now()->utc()->subHours($hoursAgo)->format('Y-m-d\TH:i:s\Z');
    }

    private function crop(int $units = 50, array $extra = []): string
    {
        return $this->postJson('/api/v1/production-cycles', array_replace(['kind' => 'crop', 'name' => 'Yam '.Str::random(4), 'operation_type_id' => OperationType::where('code', 'crops')->firstOrFail()->id,
            'crop_type_id' => CropType::where('code', 'yam')->firstOrFail()->id, 'initial_planting_units' => $units, 'planting_unit_type' => 'heap', 'planting_material_type' => 'tuber', 'planting_date' => '2026-01-01'], $extra))->assertCreated()->json('data.id');
    }

    private function store(): string
    {
        return $this->postJson('/api/v1/storage-locations', ['name' => 'Barn '.Str::random(4), 'type' => 'store'])->assertCreated()->json('data.id');
    }

    private function item(string $category = 'produce', string $unit = 'kg', array $extra = []): string
    {
        return $this->postJson('/api/v1/inventory/items', array_replace(['name' => ucfirst($category).' '.Str::random(5), 'category' => $category, 'stock_unit' => $unit], $extra))->assertCreated()->json('data.id');
    }

    private function stock(string $item): string
    {
        return $this->getJson('/api/v1/inventory/items/'.$item)->assertOk()->json('data.stock.quantity');
    }

    private function record(string $cycle, string $type, array $details, array $extra = [])
    {
        return $this->postJson('/api/v1/records', array_replace(['production_cycle_id' => $cycle, 'type' => $type, 'recorded_at' => $this->at(2), 'idempotency_key' => (string) Str::uuid(), 'details' => $details], $extra));
    }

    private function harvest(string $cycle, string $item, string $loc, string $qty = '100', string $unit = 'kg', array $inventory = [], array $details = [], array $extra = [])
    {
        return $this->record($cycle, 'crop_harvest', ['components' => [['quantity' => $qty, 'unit' => $unit]], 'inventory' => $inventory + ['item_id' => $item, 'storage_location_id' => $loc]] + $details, $extra);
    }

    private function bag(string $item, string $perBag = '50', string $target = 'kg'): void
    {
        $this->postJson('/api/v1/settings/package-conversions', ['context_type' => 'inventory_item', 'context_id' => $item, 'package_unit' => 'bag', 'target_unit' => $target, 'quantity_per_package' => $perBag])->assertCreated();
    }

    private function detail(string $cycle): array
    {
        return $this->getJson('/api/v1/production-cycles/'.$cycle.'/crop')->assertOk()->json('data');
    }

    // ---------------------------------------------------------------- establishment / survival

    public function test_50_planted_47_established_is_94_percent_derived_from_the_baseline(): void
    {
        $crop = $this->crop(50);
        $r = $this->record($crop, 'establishment_check', ['established_units' => 47])->assertCreated()->json('data');
        $this->assertSame(47, $r['details']['established_units']);
        $this->assertSame(50, $r['details']['baseline_units']);
        $this->assertSame(3, $r['details']['failed_units']);
        $this->assertSame('94', $r['details']['survival_percent']);
        $this->assertSame(0, $r['population_delta']);
        $this->assertNull($r['inventory_movement_id']);
        $d = $this->detail($crop);
        $this->assertSame('94', $d['establishment']['survival_percent']);
        $this->assertSame(3, $d['establishment']['failed_units']);
        $this->assertSame(50, $d['baseline']['initial_planting_units']);
        // Server-derived fields cannot be submitted; the baseline is the ceiling; counts are whole numbers.
        $this->record($crop, 'establishment_check', ['established_units' => 40, 'survival_percent' => '99'])->assertStatus(422);
        $this->record($crop, 'establishment_check', ['established_units' => 51])->assertStatus(422)->assertJsonValidationErrors('details.established_units');
        foreach ([-1, 1.5, '4e1', true, [47]] as $bad) {
            $this->record($crop, 'establishment_check', ['established_units' => $bad])->assertStatus(422);
        }
        // A later assessment replaces the current figure without rewriting history; fractions are exact (2 of 3).
        $this->record($crop, 'establishment_check', ['established_units' => 0])->assertCreated()->assertJsonPath('data.details.survival_percent', '0');
        $this->assertSame('0', $this->detail($crop)['establishment']['survival_percent']);
        $this->assertSame(2, $this->detail($crop)['establishment']['assessments']);
        $three = $this->crop(3);
        $this->assertSame('66.67', $this->record($three, 'establishment_check', ['established_units' => 2])->assertCreated()->json('data.details.survival_percent'));
        // Crops never get a population ledger.
        $this->assertSame(0, PopulationMovement::where('production_cycle_id', $crop)->count());
    }

    public function test_reversing_an_assessment_removes_it_from_the_project_figures(): void
    {
        $crop = $this->crop(50);
        $first = $this->record($crop, 'establishment_check', ['established_units' => 47], ['recorded_at' => $this->at(5)])->assertCreated()->json('data');
        $second = $this->record($crop, 'establishment_check', ['established_units' => 30], ['recorded_at' => $this->at(3)])->assertCreated()->json('data');
        $this->assertSame('60', $this->detail($crop)['establishment']['survival_percent']);
        $this->postJson('/api/v1/records/'.$second['id'].'/reverse', ['reason' => 'Miscounted', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $this->assertSame('94', $this->detail($crop)['establishment']['survival_percent']);
        $this->assertSame($first['id'], $this->detail($crop)['establishment']['record_id']);
    }

    // ---------------------------------------------------------------- land prep, growth stage

    public function test_land_preparation_and_growth_stage_are_activity_records_with_no_stock_or_population_effect(): void
    {
        $crop = $this->crop();
        $prep = $this->record($crop, 'land_preparation', ['method' => 'Ridging', 'treated_area' => ['quantity' => '2', 'unit' => 'acre']])->assertCreated()->json('data');
        $this->assertSame('8093.71', substr($prep['details']['treated_area']['normalized']['quantity'], 0, 7));
        $this->assertNull($prep['measurement']);
        $this->record($crop, 'land_preparation', ['method' => 'Clearing'])->assertCreated();
        $this->record($crop, 'land_preparation', [])->assertStatus(422)->assertJsonValidationErrors('details.method');
        $this->record($crop, 'land_preparation', ['method' => 'x', 'treated_area' => ['quantity' => '2', 'unit' => 'kg']])->assertStatus(422);
        $this->record($crop, 'land_preparation', ['method' => 'x', 'components' => [['quantity' => '1', 'unit' => 'kg']]])->assertStatus(422);
        $this->record($crop, 'growth_stage', ['stage' => 'vegetative'], ['recorded_at' => $this->at(5)])->assertCreated();
        $this->record($crop, 'growth_stage', ['stage' => 'flowering', 'observation' => 'First blooms'], ['recorded_at' => $this->at(3)])->assertCreated();
        $this->record($crop, 'growth_stage', ['stage' => 'overgrown'])->assertStatus(422)->assertJsonValidationErrors('details.stage');
        $d = $this->detail($crop);
        $this->assertSame('flowering', $d['growth_stage']['stage']);
        $this->assertSame(2, $d['activity']['land_preparation']);
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, PopulationMovement::where('production_cycle_id', $crop)->count());
        // Crop-only.
        $flock = $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => 'Layers', 'operation_type_id' => OperationType::where('code', 'poultry')->firstOrFail()->id,
            'species_id' => Species::where('code', 'chicken')->firstOrFail()->id, 'production_purpose' => 'breeding', 'initial_population' => 100, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
        foreach ([['land_preparation', ['method' => 'x']], ['growth_stage', ['stage' => 'vegetative']], ['establishment_check', ['established_units' => 1]], ['crop_loss', ['units_lost' => 1, 'cause' => 'x']], ['planting', ['units_planted' => 1]]] as [$type, $details]) {
            $this->record($flock, $type, $details)->assertStatus(422)->assertJsonValidationErrors('type');
        }
        $this->getJson('/api/v1/production-cycles/'.$flock.'/crop')->assertStatus(409)->assertJsonPath('code', 'not_a_crop_project');
    }

    // ---------------------------------------------------------------- planting

    public function test_planting_units_stay_the_baseline_and_are_never_the_seed_quantity(): void
    {
        $crop = $this->crop(50);
        $seed = $this->item('seed_planting_material', 'kg', ['name' => 'Yam setts']);
        $loc = $this->store();
        $this->postJson('/api/v1/inventory/stock-in', ['inventory_item_id' => $seed, 'storage_location_id' => $loc, 'reason' => 'purchase', 'components' => [['quantity' => '100', 'unit' => 'kg']], 'recorded_at' => $this->at(10), 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        // 30 heaps planted using 12.5 kg of setts: stock moves by the material, never by heaps.
        $r = $this->record($crop, 'planting', ['units_planted' => 30, 'method' => 'Heaps', 'components' => [['quantity' => '12.5', 'unit' => 'kg']], 'inventory' => ['item_id' => $seed, 'storage_location_id' => $loc]])->assertCreated()->json('data');
        $this->assertSame('87.5', $this->stock($seed));
        $this->assertSame('12500', $r['measurement']['normalized']['quantity']);
        $this->assertSame(30, $r['details']['units_planted']);
        $this->assertSame(1, InventoryMovement::where('operational_record_id', $r['id'])->where('type', 'stock_out')->count());
        // Planting without a material link records the event only.
        $this->record($crop, 'planting', ['units_planted' => 20])->assertCreated()->assertJsonPath('data.inventory_movement_id', null);
        $this->assertSame('87.5', $this->stock($seed));
        // The baseline caps cumulative planting; the project baseline itself is unchanged.
        $this->record($crop, 'planting', ['units_planted' => 1])->assertStatus(422)->assertJsonValidationErrors('details.units_planted');
        $d = $this->detail($crop);
        $this->assertSame(50, $d['planting']['units_planted']);
        $this->assertSame(0, $d['planting']['units_remaining_to_plant']);
        $this->assertSame(50, $d['baseline']['initial_planting_units']);
        // Wrong category and a quantity without a link are refused; units must be whole and positive.
        $fert = $this->item('fertilizer_agrochemical');
        $fresh = $this->crop(50);
        $this->record($fresh, 'planting', ['units_planted' => 1, 'components' => [['quantity' => '1', 'unit' => 'kg']], 'inventory' => ['item_id' => $fert, 'storage_location_id' => $loc]])->assertStatus(422)->assertJsonValidationErrors('details.inventory.item_id');
        $this->record($fresh, 'planting', ['units_planted' => 1, 'inventory' => ['item_id' => $seed, 'storage_location_id' => $loc]])->assertStatus(422)->assertJsonValidationErrors('details.components');
        foreach ([0, -3, 1.5, '2x'] as $bad) {
            $this->record($fresh, 'planting', ['units_planted' => $bad])->assertStatus(422);
        }
        // Reversing a planting returns the seed once and frees baseline capacity.
        $this->postJson('/api/v1/records/'.$r['id'].'/reverse', ['reason' => 'Replanted', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $this->assertSame('100', $this->stock($seed));
        $this->assertSame(20, $this->detail($crop)['planting']['units_planted']);
    }

    // ---------------------------------------------------------------- crop loss

    public function test_crop_loss_is_auditable_capped_by_the_baseline_and_has_no_population_behaviour(): void
    {
        $crop = $this->crop(50);
        $loss = $this->record($crop, 'crop_loss', ['units_lost' => 3, 'cause' => 'Flooding', 'affected_area' => ['quantity' => '0.1', 'unit' => 'hectare']])->assertCreated()->json('data');
        $this->assertSame(0, $loss['population_delta']);
        $this->assertSame('1000', $loss['details']['affected_area']['normalized']['quantity']);
        $this->assertSame(0, PopulationMovement::where('production_cycle_id', $crop)->count());
        $this->record($crop, 'crop_loss', ['units_lost' => 2, 'cause' => 'Pests'])->assertCreated();
        $this->record($crop, 'crop_loss', ['units_lost' => 2, 'cause' => 'Flooding'])->assertCreated();
        $d = $this->detail($crop);
        $this->assertSame(7, $d['losses']['units_lost']);
        $this->assertSame(3, $d['losses']['events']);
        $this->assertSame(5, $d['losses']['by_cause']['Flooding']);
        $this->assertSame(2, $d['losses']['by_cause']['Pests']);
        $this->assertSame(50, $d['baseline']['initial_planting_units']);
        $this->record($crop, 'crop_loss', ['units_lost' => 44, 'cause' => 'x'])->assertStatus(422)->assertJsonValidationErrors('details.units_lost');
        $this->record($crop, 'crop_loss', ['units_lost' => 1])->assertStatus(422)->assertJsonValidationErrors('details.cause');
        $this->record($crop, 'crop_loss', ['units_lost' => 0, 'cause' => 'x'])->assertStatus(422);
        // The livestock mortality record is not a crop concept.
        $this->record($crop, 'mortality', ['quantity' => 1, 'cause' => 'x'])->assertStatus(422);
        // Append-only: reverse, then correct with the right number.
        $this->postJson('/api/v1/records/'.$loss['id'].'/reverse', ['reason' => 'Wrong plot', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $this->assertSame(4, $this->detail($crop)['losses']['units_lost']);
        $this->record($crop, 'crop_loss', ['units_lost' => 1, 'cause' => 'Flooding'], ['corrects_record_id' => $loss['id']])->assertCreated();
        $this->assertSame(5, $this->detail($crop)['losses']['units_lost']);
        $this->assertSame(3, OperationalRecord::find($loss['id'])->details['units_lost']);
        $this->patchJson('/api/v1/records/'.$loss['id'], ['details' => []])->assertStatus(405);
    }

    // ---------------------------------------------------------------- harvest

    public function test_harvest_increases_produce_stock_exactly_once_and_is_traceable(): void
    {
        $crop = $this->crop();
        $produce = $this->item();
        $loc = $this->store();
        $payload = ['production_cycle_id' => $crop, 'type' => 'crop_harvest', 'recorded_at' => $this->at(2), 'idempotency_key' => (string) Str::uuid(),
            'details' => ['quality' => 'Grade A', 'components' => [['quantity' => '120.5', 'unit' => 'kg']], 'inventory' => ['item_id' => $produce, 'storage_location_id' => $loc]]];
        $r = $this->postJson('/api/v1/records', $payload)->assertCreated()->json('data');
        $this->assertSame('120.5', $this->stock($produce));
        $this->assertSame('120500', $r['measurement']['normalized']['quantity']);
        $this->assertSame('120.5', $r['measurement']['entered'][0]['quantity']);
        $movement = InventoryMovement::where('operational_record_id', $r['id'])->sole();
        $this->assertSame('stock_in', $movement->type->value);
        $this->assertSame('harvest', $movement->reason);
        $this->assertSame($movement->id, $r['inventory_movement_id']);
        $this->assertSame($r['recorded_at'], $movement->recorded_at->toISOString());
        $this->assertSame(0, $r['population_delta']);
        // Retry: no second record and no second stock-in. Changed payload conflicts.
        $this->postJson('/api/v1/records', $payload)->assertCreated()->assertJsonPath('data.id', $r['id']);
        $this->assertSame('120.5', $this->stock($produce));
        $this->assertSame(1, OperationalRecord::where('type', 'crop_harvest')->count());
        $payload['details']['components'][0]['quantity'] = '130';
        $this->postJson('/api/v1/records', $payload)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame('120.5', $this->stock($produce));
        // The stock cannot be reversed or edited directly; the record owns it.
        $this->postJson('/api/v1/inventory/movements/'.$movement->id.'/reverse', ['reason' => 'x', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertStatus(409)->assertJsonPath('code', 'reverse_via_record');
        // Project yield.
        $this->harvest($crop, $produce, $loc, '9.5')->assertCreated();
        $d = $this->detail($crop);
        $this->assertSame(2, $d['harvest']['events']);
        $this->assertSame([['unit' => 'g', 'quantity' => '130000']], $d['harvest']['totals']);
        $this->assertSame('130', $this->stock($produce));
        // The record can be found by the movement ledger.
        $this->getJson('/api/v1/inventory/movements?operational_record_id='.$r['id'])->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_harvest_requires_a_produce_link_and_a_valid_unit_and_never_assumes_package_sizes(): void
    {
        $crop = $this->crop();
        $loc = $this->store();
        $produce = $this->item();
        // The link is optional, but a half-specified link is not.
        $this->record($crop, 'crop_harvest', ['components' => [['quantity' => '1', 'unit' => 'kg']], 'inventory' => ['item_id' => $produce]])->assertStatus(422);
        foreach (['feed', 'medicine', 'seed_planting_material', 'fertilizer_agrochemical', 'general_supply'] as $category) {
            $this->harvest($crop, $this->item($category), $loc, '1')->assertStatus(422)->assertJsonValidationErrors('details.inventory.item_id');
        }
        // Dimension must match the item: a kg item is not harvested in litres or by count; a litre item takes volume.
        $this->harvest($crop, $produce, $loc, '1', 'l')->assertStatus(422);
        $this->harvest($crop, $produce, $loc, '1', 'piece')->assertStatus(422);
        $this->harvest($crop, $produce, $loc, '0')->assertStatus(422);
        $this->harvest($crop, $produce, $loc, '-3')->assertStatus(422);
        $oil = $this->item('produce', 'l', ['name' => 'Palm oil']);
        $this->harvest($crop, $oil, $loc, '750', 'ml')->assertCreated();
        $this->assertSame('0.75', $this->stock($oil));
        // Count-based produce (heads of cabbage) is counted, not weighed.
        $heads = $this->item('produce', 'piece', ['name' => 'Cabbage heads']);
        $this->harvest($crop, $heads, $loc, '40', 'piece')->assertCreated();
        $this->harvest($crop, $heads, $loc, '1', 'kg')->assertStatus(422);
        $this->assertSame('40', $this->stock($heads));
        // Compound/package quantities need the item's own conversion; none is invented.
        $this->harvest($crop, $produce, $loc, '12', 'bag')->assertStatus(422)->assertJsonPath('code', 'conversion_not_configured');
        $this->assertSame('0', $this->stock($produce));
        $this->bag($produce, '50');
        $r = $this->record($crop, 'crop_harvest', ['components' => [['quantity' => '12', 'unit' => 'bag'], ['quantity' => '18', 'unit' => 'kg']], 'inventory' => ['item_id' => $produce, 'storage_location_id' => $loc]])->assertCreated()->json('data');
        $this->assertSame('618000', $r['measurement']['normalized']['quantity']);
        $this->assertSame([['quantity' => '12', 'unit' => 'bag'], ['quantity' => '18', 'unit' => 'kg']], collect($r['measurement']['entered'])->map(fn ($e) => ['quantity' => $e['quantity'], 'unit' => $e['unit']])->all());
        $this->assertSame('618', $this->stock($produce));
        // A package of another context cannot be smuggled beside the stock link.
        $this->harvest($crop, $produce, $loc, '1', extra: ['details' => ['context' => ['type' => 'crop_type', 'id' => (string) Str::uuid()]]])->assertStatus(422);
        // Harvest belongs to crops; livestock exits are never "harvest".
        $flock = $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => 'Layers', 'operation_type_id' => OperationType::where('code', 'poultry')->firstOrFail()->id,
            'species_id' => Species::where('code', 'chicken')->firstOrFail()->id, 'production_purpose' => 'breeding', 'initial_population' => 100, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
        $this->harvest($flock, $produce, $loc, '1')->assertStatus(422)->assertJsonValidationErrors('type');
        $this->assertSame('618', $this->stock($produce));
    }

    public function test_harvest_without_inventory_is_recorded_counted_in_yield_and_creates_no_stock(): void
    {
        $crop = $this->crop();
        $payload = ['production_cycle_id' => $crop, 'type' => 'crop_harvest', 'recorded_at' => $this->at(2), 'idempotency_key' => (string) Str::uuid(),
            'details' => ['quality' => 'Mixed', 'components' => [['quantity' => '75.5', 'unit' => 'kg']]]];
        $r = $this->postJson('/api/v1/records', $payload)->assertCreated()->json('data');
        $this->assertSame('75500', $r['measurement']['normalized']['quantity']);
        $this->assertSame('75.5', $r['measurement']['entered'][0]['quantity']);
        $this->assertNull($r['inventory_movement_id']);
        $this->assertSame(0, $r['population_delta']);
        $this->assertSame(0, InventoryMovement::count());
        // Retry replays the same record; a changed payload conflicts; nothing touches stock either way.
        $this->postJson('/api/v1/records', $payload)->assertCreated()->assertJsonPath('data.id', $r['id']);
        $this->assertSame(1, OperationalRecord::where('type', 'crop_harvest')->count());
        $payload['details']['components'][0]['quantity'] = '80';
        $this->postJson('/api/v1/records', $payload)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        // Phase 5 rules still apply: units must be a weight/volume/count, packages need a context, no stock context is invented.
        $this->record($crop, 'crop_harvest', ['components' => [['quantity' => '1', 'unit' => 'hectare']]])->assertStatus(422);
        $this->record($crop, 'crop_harvest', ['components' => [['quantity' => '2', 'unit' => 'bag']]])->assertStatus(422)->assertJsonPath('code', 'conversion_context_required');
        $this->record($crop, 'crop_harvest', ['components' => [['quantity' => '0', 'unit' => 'kg']]])->assertStatus(422);
        $this->record($crop, 'crop_harvest', [])->assertStatus(422)->assertJsonValidationErrors('details.components');
        // A volume and a count harvest are valid without stock too.
        $this->record($crop, 'crop_harvest', ['components' => [['quantity' => '2', 'unit' => 'l']]])->assertCreated();
        $this->record($crop, 'crop_harvest', ['components' => [['quantity' => '40', 'unit' => 'piece']]])->assertCreated();
        $d = $this->detail($crop);
        $this->assertSame(3, $d['harvest']['events']);
        $totals = collect($d['harvest']['totals'])->pluck('quantity', 'unit')->all();
        $this->assertSame(['g' => '75500', 'ml' => '2000', 'piece' => '40'], $totals);
        // Reversal of an unlinked harvest reverses the record only.
        $this->postJson('/api/v1/records/'.$r['id'].'/reverse', ['reason' => 'Duplicate entry', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(2, $this->detail($crop)['harvest']['events']);
        $this->assertArrayNotHasKey('g', collect($this->detail($crop)['harvest']['totals'])->pluck('quantity', 'unit')->all());
        // Linked and unlinked harvests both count toward the same project totals.
        $produce = $this->item();
        $loc = $this->store();
        $this->harvest($crop, $produce, $loc, '10')->assertCreated();
        $this->record($crop, 'crop_harvest', ['components' => [['quantity' => '5', 'unit' => 'kg']]])->assertCreated();
        $this->assertSame('10', $this->stock($produce));
        $this->assertSame(1, InventoryMovement::count());
        $this->assertSame('15000', collect($this->detail($crop)['harvest']['totals'])->pluck('quantity', 'unit')->all()['g']);
        // The schema says the link is optional.
        $this->assertFalse($this->getJson('/api/v1/record-types/crop_harvest/schema')->json('data.inventory_required'));
        $this->assertTrue($this->getJson('/api/v1/record-types/crop_harvest/schema')->json('data.inventory_effect_enabled'));
        // Closed projects still refuse unlinked harvests.
        $this->postJson('/api/v1/production-cycles/'.$crop.'/close', ['end_date' => now()->toDateString(), 'reason' => 'Done'])->assertOk();
        $this->record($crop, 'crop_harvest', ['components' => [['quantity' => '1', 'unit' => 'kg']]])->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
    }

    public function test_harvest_lots_and_receiving_location_rules(): void
    {
        $crop = $this->crop();
        $loc = $this->store();
        $item = $this->item('produce', 'kg', ['tracks_lots' => true, 'tracks_expiry' => true]);
        $this->harvest($crop, $item, $loc, '10')->assertStatus(422)->assertJsonValidationErrors('details.inventory.lot_id');
        $expires = now('Africa/Lagos')->addDays(30)->toDateString();
        $r = $this->harvest($crop, $item, $loc, '10', inventory: ['lot' => ['code' => 'H-OCT', 'expires_on' => $expires]])->assertCreated()->json('data');
        $lot = InventoryMovement::where('operational_record_id', $r['id'])->value('inventory_lot_id');
        $this->assertNotNull($lot);
        // A second harvest adds to the same lot by id or by the same code.
        $this->harvest($crop, $item, $loc, '5', inventory: ['lot_id' => $lot])->assertCreated();
        $this->harvest($crop, $item, $loc, '5', inventory: ['lot' => ['code' => 'h-oct', 'expires_on' => $expires]])->assertCreated();
        $balances = collect($this->getJson('/api/v1/inventory/items/'.$item)->json('data.balances'));
        $this->assertSame('20', $balances->firstWhere('inventory_lot_id', $lot)['quantity']);
        // Lot id and new lot together are ambiguous; lots on a non-lot item are refused; expired intake refused.
        $this->harvest($crop, $item, $loc, '1', inventory: ['lot_id' => $lot, 'lot' => ['code' => 'X']])->assertStatus(422);
        $plain = $this->item();
        $this->harvest($crop, $plain, $loc, '1', inventory: ['lot' => ['code' => 'X']])->assertStatus(422);
        $this->harvest($crop, $item, $loc, '1', inventory: ['lot' => ['code' => 'OLD', 'expires_on' => now('Africa/Lagos')->subHours(2)->subDay()->toDateString()]])->assertStatus(409)->assertJsonPath('code', 'lot_expired');
        // Receiving location must belong to the farm and be active.
        $this->harvest($crop, $plain, (string) Str::uuid(), '1')->assertNotFound();
        $this->assertSame('20', $this->stock($item));
    }

    public function test_reversing_a_harvest_removes_the_stock_once_and_correction_leaves_the_right_total(): void
    {
        $crop = $this->crop();
        $produce = $this->item();
        $loc = $this->store();
        $r = $this->harvest($crop, $produce, $loc, '100')->assertCreated()->json('data');
        $this->assertSame('100', $this->stock($produce));
        $payload = ['reason' => 'Weighed twice', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()];
        $reversal = $this->postJson('/api/v1/records/'.$r['id'].'/reverse', $payload)->assertCreated()->json('data');
        $this->assertSame('0', $this->stock($produce));
        $this->postJson('/api/v1/records/'.$r['id'].'/reverse', $payload)->assertCreated()->assertJsonPath('data.id', $reversal['id']);
        $this->postJson('/api/v1/records/'.$r['id'].'/reverse', ['idempotency_key' => (string) Str::uuid()] + $payload)->assertStatus(409)->assertJsonPath('code', 'record_already_reversed');
        $this->assertSame('0', $this->stock($produce));
        $this->assertSame(1, InventoryMovement::where('type', 'reversal')->count());
        $this->assertSame([], $this->detail($crop)['harvest']['totals']);
        // Correction: a replacement harvest of the true quantity.
        $this->harvest($crop, $produce, $loc, '80', extra: ['corrects_record_id' => $r['id']])->assertCreated();
        $this->assertSame('80', $this->stock($produce));
        $this->assertSame('100', collect(OperationalRecord::find($r['id'])->measurement['entered'])->first()['quantity']);
        $this->assertSame([['unit' => 'g', 'quantity' => '80000']], $this->detail($crop)['harvest']['totals']);
        // Once the produce has been used, the harvest cannot be silently undone (stock never goes negative).
        $other = $this->harvest($crop, $produce, $loc, '10')->assertCreated()->json('data');
        $this->postJson('/api/v1/inventory/stock-out', ['inventory_item_id' => $produce, 'storage_location_id' => $loc, 'reason' => 'use', 'components' => [['quantity' => '85', 'unit' => 'kg']], 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson('/api/v1/records/'.$other['id'].'/reverse', ['reason' => 'x', 'recorded_at' => $this->at(0), 'idempotency_key' => (string) Str::uuid()])->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->assertSame('5', $this->stock($produce));
        $this->assertNull(OperationalRecord::find($other['id'])->reversal);
    }

    public function test_closed_projects_reject_every_crop_write_and_stock_effect(): void
    {
        $crop = $this->crop();
        $produce = $this->item();
        $loc = $this->store();
        $this->postJson('/api/v1/production-cycles/'.$crop.'/close', ['end_date' => now()->toDateString(), 'reason' => 'Season over'])->assertOk();
        $this->harvest($crop, $produce, $loc, '1')->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
        foreach ([['land_preparation', ['method' => 'x']], ['planting', ['units_planted' => 1]], ['establishment_check', ['established_units' => 1]], ['growth_stage', ['stage' => 'seedling']], ['crop_loss', ['units_lost' => 1, 'cause' => 'x']]] as [$type, $details]) {
            $this->record($crop, $type, $details)->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
        }
        $this->assertSame('0', $this->stock($produce));
        $this->assertSame(0, OperationalRecord::count());
    }

    // ---------------------------------------------------------------- scope, isolation, permissions

    public function test_project_and_plot_scope_farm_isolation_and_permissions(): void
    {
        $area = $this->postJson('/api/v1/production-areas', ['name' => 'North plot', 'type' => 'pen'])->assertCreated()->json('data.id');
        $one = $this->crop(50, ['production_area_id' => $area]);
        $two = $this->crop(50, ['production_area_id' => $area]);
        $elsewhere = $this->crop(50);
        $this->record($one, 'crop_loss', ['units_lost' => 1, 'cause' => 'a'])->assertCreated();
        $this->record($two, 'crop_loss', ['units_lost' => 1, 'cause' => 'b'])->assertCreated();
        $this->record($elsewhere, 'crop_loss', ['units_lost' => 1, 'cause' => 'c'])->assertCreated();
        $this->assertSame($area, $this->detail($one)['production_area']['id']);
        $this->assertNull($this->detail($elsewhere)['production_area']);
        // Records are listed per project or per plot, never across them.
        $this->getJson('/api/v1/records?production_cycle_id='.$one)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/records?production_area_id='.$area)->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(1, $this->detail($one)['losses']['units_lost']);
        // Another farm sees nothing of it.
        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $this->getJson('/api/v1/production-cycles/'.$one.'/crop')->assertNotFound();
        $this->getJson('/api/v1/records?production_area_id='.$area)->assertNotFound();
        $this->record($one, 'crop_loss', ['units_lost' => 1, 'cause' => 'x'])->assertNotFound();
        $produce = $this->item();
        $loc = $this->store();
        $mine = $this->crop(50);
        $this->signInAs($this->owner);
        $theirProduce = $this->item();
        $theirLoc = $this->store();
        // A farm can never harvest into another farm's produce item or store.
        $this->signInAs($otherOwner);
        $this->harvest($mine, $theirProduce, $loc, '1')->assertNotFound();
        $this->harvest($mine, $produce, $theirLoc, '1')->assertNotFound();
        $this->signInAs($this->owner);
        $this->assertSame(0, InventoryMovement::where('type', 'stock_in')->count());
        // Workers harvest into produce stock; a role without record.create cannot write.
        $this->signInAs($this->member(FarmRole::FarmWorker));
        $this->harvest($one, $theirProduce, $theirLoc, '5')->assertCreated();
        $this->signInAs($this->member(FarmRole::Finance));
        $this->harvest($one, $theirProduce, $theirLoc, '5')->assertForbidden();
        $this->getJson('/api/v1/production-cycles/'.$one.'/crop')->assertOk();
    }
}
