<?php

namespace Tests\Feature\Crops;

use App\Enums\FarmRole;
use App\Models\CropType;
use App\Models\InventoryMovement;
use App\Models\OperationalRecord;
use App\Models\OperationType;
use App\Models\Species;
use App\Models\Task;
use Illuminate\Support\Str;
use Tests\Feature\Team\TeamTestCase;

/** Phase 13: crop treatments are Phase 8 records (fertilizer_application, pesticide_application) that may consume Phase 9 stock. */
class CropTreatmentTest extends TeamTestCase
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

    private function crop(): string
    {
        return $this->postJson('/api/v1/production-cycles', ['kind' => 'crop', 'name' => 'Yam '.Str::random(4), 'operation_type_id' => OperationType::where('code', 'crops')->firstOrFail()->id,
            'crop_type_id' => CropType::where('code', 'yam')->firstOrFail()->id, 'initial_planting_units' => 800, 'planting_unit_type' => 'heap', 'planting_material_type' => 'tuber', 'planting_date' => '2026-01-01'])->assertCreated()->json('data.id');
    }

    private function livestock(): string
    {
        return $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => 'Layers', 'operation_type_id' => OperationType::where('code', 'poultry')->firstOrFail()->id,
            'species_id' => Species::where('code', 'chicken')->firstOrFail()->id, 'production_purpose' => 'breeding', 'initial_population' => 100, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
    }

    private function store(): string
    {
        return $this->postJson('/api/v1/storage-locations', ['name' => 'Agro store '.Str::random(4), 'type' => 'store'])->assertCreated()->json('data.id');
    }

    private function input(array $extra = []): string
    {
        return $this->postJson('/api/v1/inventory/items', array_replace(['name' => 'NPK '.Str::random(5), 'category' => 'fertilizer_agrochemical', 'stock_unit' => 'kg'], $extra))->assertCreated()->json('data.id');
    }

    private function receive(string $item, string $loc, string $qty = '100', string $unit = 'kg', array $extra = []): array
    {
        return $this->postJson('/api/v1/inventory/stock-in', array_replace(['inventory_item_id' => $item, 'storage_location_id' => $loc, 'reason' => 'purchase', 'components' => [['quantity' => $qty, 'unit' => $unit]],
            'recorded_at' => $this->at(10), 'idempotency_key' => (string) Str::uuid()], $extra))->assertCreated()->json('data');
    }

    private function stock(string $item): string
    {
        return $this->getJson('/api/v1/inventory/items/'.$item)->assertOk()->json('data.stock.quantity');
    }

    private function fertilize(string $cycle, ?string $item, ?string $loc, string $qty = '10', string $unit = 'kg', array $inventory = [], array $details = [], array $extra = [])
    {
        $d = ['method' => 'Broadcast', 'components' => [['quantity' => $qty, 'unit' => $unit]]] + $details;
        if ($item !== null) {
            $d['inventory'] = $inventory + ['item_id' => $item, 'storage_location_id' => $loc];
        } else {
            $d += ['input_name' => 'Compost'];
        }

        return $this->postJson('/api/v1/records', array_replace(['production_cycle_id' => $cycle, 'type' => 'fertilizer_application', 'recorded_at' => $this->at(2), 'idempotency_key' => (string) Str::uuid(), 'details' => $d], $extra));
    }

    private function spray(string $cycle, ?string $item, ?string $loc, string $qty = '2', string $unit = 'l', array $details = [], array $extra = [])
    {
        $d = ['product_type' => 'herbicide', 'method' => 'Knapsack sprayer', 'components' => [['quantity' => $qty, 'unit' => $unit]]] + $details;
        if ($item !== null) {
            $d['inventory'] = ['item_id' => $item, 'storage_location_id' => $loc];
        } else {
            $d += ['input_name' => 'Glyphosate'];
        }

        return $this->postJson('/api/v1/records', array_replace(['production_cycle_id' => $cycle, 'type' => 'pesticide_application', 'recorded_at' => $this->at(2), 'idempotency_key' => (string) Str::uuid(), 'details' => $d], $extra));
    }

    public function test_fertilizer_application_consumes_stock_exactly_once_and_stores_treatment_context_apart_from_quantity(): void
    {
        $crop = $this->crop();
        $item = $this->input();
        $loc = $this->store();
        $this->receive($item, $loc, '100');
        $payload = ['production_cycle_id' => $crop, 'type' => 'fertilizer_application', 'recorded_at' => $this->at(2), 'idempotency_key' => (string) Str::uuid(),
            'details' => ['method' => 'Side dressing', 'concentration' => '15-15-15', 'treated_area' => ['quantity' => '0.5', 'unit' => 'hectare'],
                'components' => [['quantity' => '25', 'unit' => 'kg']], 'inventory' => ['item_id' => $item, 'storage_location_id' => $loc]]];
        $record = $this->postJson('/api/v1/records', $payload)->assertCreated()->json('data');
        $this->assertSame('75', $this->stock($item));
        $this->assertSame('25000', $record['measurement']['normalized']['quantity']);
        $this->assertSame('5000', $record['details']['treated_area']['normalized']['quantity']);
        $this->assertSame('15-15-15', $record['details']['concentration']);
        $this->assertSame(0, $record['population_delta']);
        $movement = InventoryMovement::where('operational_record_id', $record['id'])->sole();
        $this->assertSame('use', $movement->reason);
        $this->assertSame($movement->id, $record['inventory_movement_id']);
        // The input name defaults to the stocked item's name when the farmer links stock.
        $this->assertNotEmpty($record['details']['input_name']);
        // Retry: same record, no second deduction, no second record.
        $this->postJson('/api/v1/records', $payload)->assertCreated()->assertJsonPath('data.id', $record['id']);
        $this->assertSame('75', $this->stock($item));
        $this->assertSame(1, InventoryMovement::where('type', 'stock_out')->count());
        $this->assertSame(1, OperationalRecord::where('type', 'fertilizer_application')->count());
        // Changed payload under the same key conflicts and changes nothing.
        $payload['details']['components'][0]['quantity'] = '26';
        $this->postJson('/api/v1/records', $payload)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame('75', $this->stock($item));
        // Crop land area is untouched and the treated area is not the stock quantity.
        $this->getJson('/api/v1/production-cycles/'.$crop)->assertOk()->assertJsonPath('data.crop.initial_planting_units', 800);
    }

    public function test_pesticide_and_herbicide_use_volume_stock_and_unlinked_records_have_no_stock_effect(): void
    {
        $crop = $this->crop();
        $item = $this->input(['name' => 'Glyphosate 480', 'stock_unit' => 'l']);
        $loc = $this->store();
        $this->receive($item, $loc, '20', 'l');
        $record = $this->spray($crop, $item, $loc, '500', 'ml', ['target' => 'Spear grass', 'pre_harvest_interval_days' => 14])->assertCreated()->json('data');
        $this->assertSame('19.5', $this->stock($item));
        $this->assertSame('500', $record['measurement']['normalized']['quantity']);
        $this->assertSame('herbicide', $record['details']['product_type']);
        $this->assertSame(14, $record['details']['pre_harvest_interval_days']);
        // Wrong dimension for a volume-based item is a measurement error, not stock.
        $this->spray($crop, $item, $loc, '2', 'kg')->assertStatus(422);
        $this->assertSame('19.5', $this->stock($item));
        // Hand-bought product, no stocked link: a record only. Weight or volume both validate unlinked.
        $this->spray($crop, null, null, '1', 'l')->assertCreated()->assertJsonPath('data.inventory_movement_id', null);
        $this->fertilize($crop, null, null, '5')->assertCreated()->assertJsonPath('data.inventory_movement_id', null);
        $this->assertSame(1, InventoryMovement::where('type', 'stock_out')->count());
        $this->postJson('/api/v1/records', ['production_cycle_id' => $crop, 'type' => 'pesticide_application', 'recorded_at' => $this->at(2), 'idempotency_key' => (string) Str::uuid(),
            'details' => ['product_type' => 'bleach', 'method' => 'x', 'input_name' => 'x', 'components' => [['quantity' => '1', 'unit' => 'l']]]])->assertStatus(422)->assertJsonValidationErrors('details.product_type');
    }

    public function test_crop_only_and_inventory_category_compatibility(): void
    {
        $item = $this->input();
        $loc = $this->store();
        $this->receive($item, $loc, '50');
        $flock = $this->livestock();
        $this->fertilize($flock, $item, $loc)->assertStatus(422)->assertJsonValidationErrors('type');
        $this->spray($flock, null, null)->assertStatus(422)->assertJsonValidationErrors('type');
        $crop = $this->crop();
        // Medicine, feed, seed and general stock are never crop inputs; livestock medicine semantics are not reused.
        foreach (['medicine', 'feed', 'seed_planting_material', 'general_supply'] as $category) {
            $other = $this->input(['name' => 'Other '.$category, 'category' => $category]);
            $this->receive($other, $loc, '10');
            $this->fertilize($crop, $other, $loc, '1')->assertStatus(422)->assertJsonValidationErrors('details.inventory.item_id');
            $this->assertSame('10', $this->stock($other));
        }
        // Count-based inputs cannot be applied by weight/volume.
        $sachets = $this->input(['name' => 'Sachets', 'stock_unit' => 'piece']);
        $this->receive($sachets, $loc, '10', 'piece');
        $this->spray($crop, $sachets, $loc, '1', 'piece')->assertStatus(422)->assertJsonValidationErrors('details.inventory.item_id');
        // A crop cannot consume fertilizer through a feed_use record either.
        $this->postJson('/api/v1/records', ['production_cycle_id' => $crop, 'type' => 'feed_use', 'recorded_at' => $this->at(2), 'idempotency_key' => (string) Str::uuid(),
            'details' => ['feed_name' => 'x', 'components' => [['quantity' => '1', 'unit' => 'kg']], 'inventory' => ['item_id' => $item, 'storage_location_id' => $loc]]])->assertStatus(422);
        $this->assertSame('50', $this->stock($item));
        $this->assertSame(0, OperationalRecord::count());
    }

    public function test_package_quantities_use_the_items_own_conversion_and_never_an_invented_size(): void
    {
        $crop = $this->crop();
        $item = $this->input();
        $loc = $this->store();
        $this->receive($item, $loc, '200');
        $this->fertilize($crop, $item, $loc, '2', 'bag')->assertStatus(422)->assertJsonPath('code', 'conversion_not_configured');
        $this->assertSame('200', $this->stock($item));
        $this->postJson('/api/v1/settings/package-conversions', ['context_type' => 'inventory_item', 'context_id' => $item, 'package_unit' => 'bag', 'target_unit' => 'kg', 'quantity_per_package' => '25'])->assertCreated();
        $record = $this->fertilize($crop, $item, $loc, '2', 'bag')->assertCreated()->json('data');
        $this->assertSame('50000', $record['measurement']['normalized']['quantity']);
        $this->assertSame('150', $this->stock($item));
        // A foreign context cannot be smuggled in beside a stock link.
        $this->fertilize($crop, $item, $loc, '1', extra: ['details' => ['context' => ['type' => 'crop_type', 'id' => (string) Str::uuid()]]])->assertStatus(422);
        // Unlinked: a package still needs an explicit context and never guesses a size.
        $this->fertilize($crop, null, null, '1', 'bag')->assertStatus(422)->assertJsonPath('code', 'conversion_context_required');
        // Area is validated as an area.
        $this->fertilize($crop, $item, $loc, '1', details: ['treated_area' => ['quantity' => '2', 'unit' => 'kg']])->assertStatus(422);
        $this->fertilize($crop, $item, $loc, '1', details: ['treated_area' => ['quantity' => '0', 'unit' => 'hectare']])->assertStatus(422)->assertJsonValidationErrors('details.treated_area');
        $this->assertSame('150', $this->stock($item));
    }

    public function test_lot_and_expiry_rules_apply_and_the_lot_is_traceable(): void
    {
        $crop = $this->crop();
        $item = $this->input(['tracks_lots' => true, 'tracks_expiry' => true]);
        $loc = $this->store();
        $lot = $this->receive($item, $loc, '100', extra: ['lot' => ['code' => 'L1', 'expires_on' => now('Africa/Lagos')->addDays(60)->toDateString()]])['inventory_lot_id'];
        $expired = $this->receive($item, $loc, '30', extra: ['lot' => ['code' => 'OLD', 'expires_on' => now('Africa/Lagos')->addDay()->toDateString()]])['inventory_lot_id'];
        $this->fertilize($crop, $item, $loc, '5')->assertStatus(422)->assertJsonValidationErrors('details.inventory.lot_id');
        $record = $this->fertilize($crop, $item, $loc, '5', inventory: ['lot_id' => $lot])->assertCreated()->json('data');
        $this->assertSame($lot, InventoryMovement::where('operational_record_id', $record['id'])->value('inventory_lot_id'));
        // Expiry is judged at the event time: an application recorded after the lot expired is refused.
        $future = now()->utc()->addDays(3);
        $this->travelTo($future);
        $this->fertilize($crop, $item, $loc, '1', inventory: ['lot_id' => $expired], extra: ['recorded_at' => $future->format('Y-m-d\TH:i:s\Z')])->assertStatus(409)->assertJsonPath('code', 'lot_expired');
        $this->travelBack();
        $this->assertSame('125', $this->stock($item));
    }

    public function test_insufficient_stock_is_atomic_and_stock_is_per_location(): void
    {
        $crop = $this->crop();
        $item = $this->input();
        $a = $this->store();
        $b = $this->store();
        $this->receive($item, $a, '5');
        $this->receive($item, $b, '50');
        $this->fertilize($crop, $item, $a, '6')->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->assertSame(0, OperationalRecord::count());
        $this->assertSame('55', $this->stock($item));
        $this->fertilize($crop, $item, $b, '6')->assertCreated();
        $this->assertSame('49', $this->stock($item));
    }

    public function test_reversal_restores_stock_once_and_correction_leaves_the_right_final_stock(): void
    {
        $crop = $this->crop();
        $item = $this->input();
        $loc = $this->store();
        $this->receive($item, $loc, '100');
        $record = $this->fertilize($crop, $item, $loc, '30')->assertCreated()->json('data');
        $this->assertSame('70', $this->stock($item));
        $payload = ['reason' => 'Wrong bay', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()];
        $reversal = $this->postJson('/api/v1/records/'.$record['id'].'/reverse', $payload)->assertCreated()->json('data');
        $this->assertSame('100', $this->stock($item));
        $this->postJson('/api/v1/records/'.$record['id'].'/reverse', $payload)->assertCreated()->assertJsonPath('data.id', $reversal['id']);
        $this->postJson('/api/v1/records/'.$record['id'].'/reverse', ['idempotency_key' => (string) Str::uuid()] + $payload)->assertStatus(409)->assertJsonPath('code', 'record_already_reversed');
        $this->assertSame('100', $this->stock($item));
        $this->assertSame(1, InventoryMovement::where('type', 'reversal')->count());
        $this->assertSame(2, InventoryMovement::where('type', 'stock_out')->orWhere('type', 'reversal')->count());
        // Correct: a replacement record deducts the right quantity; the original record is never patched.
        $this->fertilize($crop, $item, $loc, '20', extra: ['corrects_record_id' => $record['id']])->assertCreated();
        $this->assertSame('80', $this->stock($item));
        $this->assertSame('30', collect(OperationalRecord::find($record['id'])->measurement['entered'])->first()['quantity']);
        $this->patchJson('/api/v1/records/'.$record['id'], ['details' => []])->assertStatus(405);
        // Reversal of a pesticide record behaves the same way, and a direct stock reversal is refused.
        $liquid = $this->input(['name' => 'Insecticide', 'stock_unit' => 'l']);
        $this->receive($liquid, $loc, '10', 'l');
        $spray = $this->spray($crop, $liquid, $loc, '3')->assertCreated()->json('data');
        $this->assertSame('7', $this->stock($liquid));
        $this->postJson('/api/v1/inventory/movements/'.$spray['inventory_movement_id'].'/reverse', ['reason' => 'x', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertStatus(409)->assertJsonPath('code', 'reverse_via_record');
        $this->postJson('/api/v1/records/'.$spray['id'].'/reverse', ['reason' => 'Error', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $this->assertSame('10', $this->stock($liquid));
    }

    public function test_closed_cycles_reject_treatments_and_their_stock_effect(): void
    {
        $crop = $this->crop();
        $item = $this->input();
        $loc = $this->store();
        $this->receive($item, $loc, '10');
        $this->postJson('/api/v1/production-cycles/'.$crop.'/close', ['end_date' => now()->toDateString(), 'reason' => 'Harvested elsewhere'])->assertOk();
        $this->fertilize($crop, $item, $loc, '1')->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
        $this->spray($crop, null, null)->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
        $this->assertSame('10', $this->stock($item));
        $this->assertSame(0, OperationalRecord::count());
    }

    public function test_farm_isolation_and_permissions(): void
    {
        $crop = $this->crop();
        $item = $this->input();
        $loc = $this->store();
        $this->receive($item, $loc, '10');
        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $this->fertilize($crop, $item, $loc, '1')->assertNotFound();
        $otherCrop = $this->crop();
        $this->fertilize($otherCrop, $item, $loc, '1')->assertNotFound();
        $this->assertSame(0, InventoryMovement::where('type', 'stock_out')->count());
        // A viewer cannot record; a worker can only link stock with inventory.use (granted to workers), never to finance.
        $this->signInAs($this->member(FarmRole::Finance));
        $this->fertilize($crop, $item, $loc, '1')->assertForbidden();
        $this->signInAs($this->member(FarmRole::FarmWorker));
        $this->fertilize($crop, $item, $loc, '1')->assertCreated();
        $this->assertSame('9', $this->stock($item));
    }

    public function test_treatment_records_are_not_tasks_and_phase_8_crop_records_stay_compatible(): void
    {
        $crop = $this->crop();
        $tasks = Task::count();
        $this->fertilize($crop, null, null, '3')->assertCreated();
        $this->spray($crop, null, null)->assertCreated();
        $this->assertSame($tasks, Task::count());
        // Phase 8 shapes that predate Phase 13 still validate unchanged.
        foreach (['irrigation' => ['method' => 'Drip'], 'weeding' => ['method' => 'Manual'], 'pest_observation' => ['issue' => 'Leaf damage', 'severity' => 'low']] as $type => $details) {
            $this->postJson('/api/v1/records', ['production_cycle_id' => $crop, 'type' => $type, 'recorded_at' => $this->at(3), 'idempotency_key' => (string) Str::uuid(), 'details' => $details])->assertCreated();
        }
        $this->postJson('/api/v1/records', ['production_cycle_id' => $crop, 'type' => 'fertilizer_application', 'recorded_at' => $this->at(3), 'idempotency_key' => (string) Str::uuid(),
            'details' => ['input_name' => 'Compost', 'method' => 'Broadcast', 'components' => [['quantity' => 5, 'unit' => 'kg']]]])->assertCreated()->assertJsonPath('data.inventory_movement_id', null);
        // Unknown keys and a missing name without a stock link are still refused.
        $this->postJson('/api/v1/records', ['production_cycle_id' => $crop, 'type' => 'fertilizer_application', 'recorded_at' => $this->at(3), 'idempotency_key' => (string) Str::uuid(),
            'details' => ['method' => 'Broadcast', 'components' => [['quantity' => 5, 'unit' => 'kg']]]])->assertStatus(422)->assertJsonValidationErrors('details.input_name');
        $this->postJson('/api/v1/records', ['production_cycle_id' => $crop, 'type' => 'pesticide_application', 'recorded_at' => $this->at(3), 'idempotency_key' => (string) Str::uuid(),
            'details' => ['input_name' => 'x', 'product_type' => 'herbicide', 'method' => 'x', 'components' => [['quantity' => 1, 'unit' => 'l']], 'cost' => 5]])->assertStatus(422);
        $schema = $this->getJson('/api/v1/record-types/pesticide_application/schema')->assertOk()->json('data');
        $this->assertTrue($schema['inventory_effect_enabled']);
        $this->assertSame('fertilizer_agrochemical', $schema['inventory_category']);
        $this->assertSame('crop', $schema['cycle_kind']);
        $this->assertSame('fertilizer_agrochemical', $this->getJson('/api/v1/record-types/fertilizer_application/schema')->json('data.inventory_category'));
        $this->assertNull($this->getJson('/api/v1/record-types/irrigation/schema')->json('data.inventory_category'));
    }
}
