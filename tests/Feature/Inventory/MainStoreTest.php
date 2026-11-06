<?php

namespace Tests\Feature\Inventory;

use App\Models\Farm;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\OperationalRecord;
use App\Models\OperationType;
use App\Models\Sale;
use App\Models\Species;
use App\Models\StorageLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Team\TeamTestCase;

/** The farm's main store, store-less stock movements, and adding stock for a brand-new item in one step. */
class MainStoreTest extends TeamTestCase
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

    private function store(string $name): string
    {
        return $this->postJson('/api/v1/storage-locations', ['name' => $name, 'type' => 'store'])->assertCreated()->json('data.id');
    }

    private function item(string $name, string $category = 'feed'): string
    {
        return $this->postJson('/api/v1/inventory/items', ['name' => $name, 'category' => $category, 'stock_unit' => 'kg'])->assertCreated()->json('data.id');
    }

    private function stockIn(array $target, string $qty = '100', array $extra = [])
    {
        return $this->postJson('/api/v1/inventory/stock-in', array_replace($target + ['reason' => 'opening_balance', 'components' => [['quantity' => $qty, 'unit' => 'kg']],
            'recorded_at' => $this->at(10), 'idempotency_key' => (string) Str::uuid()], $extra));
    }

    private function stockOut(string $item, string $qty = '5', array $extra = [])
    {
        return $this->postJson('/api/v1/inventory/stock-out', array_replace(['inventory_item_id' => $item, 'reason' => 'donation', 'components' => [['quantity' => $qty, 'unit' => 'kg']],
            'recorded_at' => $this->at(5), 'idempotency_key' => (string) Str::uuid()], $extra));
    }

    private function stockedIn(string $item, string $store, string $qty = '100'): void
    {
        $this->stockIn(['inventory_item_id' => $item, 'storage_location_id' => $store], $qty)->assertCreated();
    }

    private function cycle(): string
    {
        return $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => 'Layer flock', 'operation_type_id' => OperationType::where('code', 'poultry')->firstOrFail()->id,
            'species_id' => Species::where('code', 'chicken')->firstOrFail()->id, 'production_purpose' => 'breeding', 'initial_population' => 100, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
    }

    private function feedUse(string $cycle, string $item, array $inventory = [])
    {
        return $this->postJson('/api/v1/records', ['production_cycle_id' => $cycle, 'type' => 'feed_use', 'recorded_at' => $this->at(2), 'idempotency_key' => (string) Str::uuid(),
            'details' => ['feed_name' => 'Mash', 'components' => [['quantity' => '2', 'unit' => 'kg']], 'inventory' => ['item_id' => $item] + $inventory]]);
    }

    private function sale(string $item, array $extra = [])
    {
        return $this->postJson('/api/v1/sales', ['recorded_at' => $this->at(2), 'idempotency_key' => (string) Str::uuid(),
            'items' => [['kind' => 'stock', 'inventory_item_id' => $item, 'components' => [['quantity' => '3', 'unit' => 'kg']], 'amount' => '900'] + $extra]]);
    }

    private function healthRecord(string $cycle, string $medicine, array $line = [])
    {
        return $this->postJson('/api/v1/health-records', ['production_cycle_id' => $cycle, 'type' => 'vaccination', 'recorded_at' => $this->at(2), 'idempotency_key' => (string) Str::uuid(),
            'details' => ['target_disease' => 'Newcastle disease'], 'medicines' => [['inventory_item_id' => $medicine, 'components' => [['quantity' => '10', 'unit' => 'ml']]] + $line]]);
    }

    private function mainId(): ?string
    {
        return StorageLocation::where('is_default', true)->value('id');
    }

    // ------------------------------------------------------------------ stock in

    public function test_stock_in_without_a_store_creates_the_main_store_once_then_reuses_it(): void
    {
        $item = $this->item('Layer mash');
        $this->assertNull($this->mainId());
        $first = $this->stockIn(['inventory_item_id' => $item])->assertCreated()->json('data.storage_location_id');
        $this->stockIn(['inventory_item_id' => $item], '20')->assertCreated()->assertJsonPath('data.storage_location_id', $first);
        $this->assertSame(['Main Store'], StorageLocation::pluck('name')->all());
        $this->assertSame($first, $this->mainId());
        $this->assertTrue($this->getJson('/api/v1/storage-locations/'.$first)->assertOk()->json('data.is_main'));
    }

    public function test_stock_in_uses_the_main_store_even_when_there_are_several_and_an_explicit_store_wins(): void
    {
        $a = $this->store('Feed room'); // the first store is main
        $b = $this->store('Cold room');
        $item = $this->item('Layer mash');
        $this->assertSame($a, $this->mainId());
        $this->assertFalse($this->getJson('/api/v1/storage-locations/'.$b)->json('data.is_main'));
        $this->stockIn(['inventory_item_id' => $item])->assertCreated()->assertJsonPath('data.storage_location_id', $a);
        $this->stockIn(['inventory_item_id' => $item, 'storage_location_id' => $b])->assertCreated()->assertJsonPath('data.storage_location_id', $b);
        $this->assertSame(2, StorageLocation::count());
    }

    public function test_purchase_and_harvest_style_receipts_go_to_the_main_store(): void
    {
        $item = $this->item('Layer mash');
        $purchase = $this->postJson('/api/v1/purchases', ['recorded_at' => $this->at(2), 'idempotency_key' => (string) Str::uuid(), 'items' => [
            ['kind' => 'stock', 'inventory_item_id' => $item, 'components' => [['quantity' => '10', 'unit' => 'kg']], 'amount' => '5000'],
        ]]);
        $purchase->assertCreated();
        $this->assertSame([$this->mainId()], InventoryMovement::pluck('storage_location_id')->unique()->values()->all());
        $this->assertNotNull($this->mainId());
    }

    // ------------------------------------------------------------------ stock out

    public function test_stock_out_uses_the_one_store_that_holds_the_item_asks_when_several_and_reports_none(): void
    {
        $a = $this->store('Feed room');
        $b = $this->store('Cold room');
        $item = $this->item('Layer mash');
        $this->stockOut($item)->assertStatus(409)->assertJsonPath('code', 'insufficient_stock'); // in none
        $this->stockedIn($item, $b); // only the NON-main store holds it
        $this->stockOut($item, '5')->assertCreated()->assertJsonPath('data.storage_location_id', $b);
        $this->stockedIn($item, $a, '10');
        $this->stockOut($item)->assertUnprocessable()->assertJsonValidationErrors('storage_location_id');
        $this->assertSame('Choose which store this comes from.', $this->stockOut($item)->json('errors.storage_location_id.0'));
        $this->stockOut($item, '1', ['storage_location_id' => $a])->assertCreated()->assertJsonPath('data.storage_location_id', $a); // explicit wins
        $this->assertSame(4, InventoryMovement::count());
    }

    public function test_a_sale_stock_line_resolves_its_store_the_same_way(): void
    {
        $a = $this->store('Feed room');
        $b = $this->store('Cold room');
        $item = $this->item('Layer mash');
        $this->sale($item)->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->stockedIn($item, $b);
        $sale = $this->sale($item)->assertCreated()->json('data');
        $this->assertSame($b, InventoryMovement::where('sale_id', $sale['id'])->sole()->storage_location_id);
        $this->stockedIn($item, $a, '10');
        $this->sale($item)->assertUnprocessable()->assertJsonValidationErrors('items.0.storage_location_id');
        $this->assertSame(1, Sale::count());
        $this->sale($item, ['storage_location_id' => $a])->assertCreated();
    }

    public function test_a_feed_use_link_resolves_its_store_the_same_way(): void
    {
        $a = $this->store('Feed room');
        $b = $this->store('Cold room');
        $cycle = $this->cycle();
        $item = $this->item('Layer mash');
        $this->feedUse($cycle, $item)->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->stockedIn($item, $b);
        $record = $this->feedUse($cycle, $item)->assertCreated()->json('data');
        $this->assertSame($b, $record['details']['inventory']['storage_location_id']); // the record keeps where the stock came from
        $this->assertSame($b, InventoryMovement::where('operational_record_id', $record['id'])->sole()->storage_location_id);
        $this->stockedIn($item, $a, '10');
        $this->feedUse($cycle, $item)->assertUnprocessable()->assertJsonValidationErrors('details.inventory.storage_location_id');
        $this->assertSame(1, OperationalRecord::where('type', 'feed_use')->count());
        $this->feedUse($cycle, $item, ['storage_location_id' => $a])->assertCreated();
    }

    public function test_a_health_medicine_line_resolves_its_store_the_same_way(): void
    {
        $a = $this->store('Feed room');
        $b = $this->store('Cold room');
        $cycle = $this->cycle();
        $medicine = $this->postJson('/api/v1/inventory/items', ['name' => 'Newcastle vaccine', 'category' => 'medicine', 'stock_unit' => 'ml'])->assertCreated()->json('data.id');
        $this->healthRecord($cycle, $medicine)->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->stockIn(['inventory_item_id' => $medicine, 'storage_location_id' => $b], '500', ['components' => [['quantity' => '500', 'unit' => 'ml']]])->assertCreated();
        $record = $this->healthRecord($cycle, $medicine)->assertCreated()->json('data');
        $this->assertSame($b, InventoryMovement::where('health_record_id', $record['id'])->sole()->storage_location_id);
        $this->stockIn(['inventory_item_id' => $medicine, 'storage_location_id' => $a], '500', ['components' => [['quantity' => '500', 'unit' => 'ml']]])->assertCreated();
        $this->healthRecord($cycle, $medicine)->assertUnprocessable()->assertJsonValidationErrors('medicines.0.storage_location_id');
        $this->healthRecord($cycle, $medicine, ['storage_location_id' => $a])->assertCreated();
    }

    public function test_a_medicine_can_be_created_with_opening_stock_and_no_store(): void
    {
        $this->postJson('/api/v1/health/medicines', ['name' => 'Tylosin', 'medicine_type' => 'antibiotic', 'stock_unit' => 'ml',
            'opening_stock' => ['components' => [['quantity' => '250', 'unit' => 'ml']], 'recorded_at' => $this->at(3), 'idempotency_key' => (string) Str::uuid()]])->assertCreated();
        $this->assertSame($this->mainId(), InventoryMovement::sole()->storage_location_id);
    }

    // ------------------------------------------------------------------ eggs and milk

    public function test_eggs_and_milk_keep_working_without_a_store_and_use_the_main_store(): void
    {
        $cycle = $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => 'Hens', 'operation_type_id' => OperationType::where('code', 'poultry')->firstOrFail()->id,
            'species_id' => Species::where('code', 'chicken')->firstOrFail()->id, 'production_purpose' => 'eggs', 'initial_population' => 50, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/records', ['production_cycle_id' => $cycle, 'type' => 'egg_collection', 'recorded_at' => $this->at(2), 'idempotency_key' => (string) Str::uuid(),
            'details' => ['components' => [['quantity' => 30, 'unit' => 'piece']]]])->assertCreated();
        $main = $this->mainId();
        $this->assertNotNull($main);
        $this->postJson('/api/v1/inventory/stock-out', ['output' => 'eggs', 'reason' => 'donation', 'components' => [['quantity' => '5', 'unit' => 'piece']],
            'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.storage_location_id', $main);
        $this->postJson('/api/v1/inventory/stock-in', ['output' => 'milk', 'reason' => 'donation', 'components' => [['quantity' => '4', 'unit' => 'l']],
            'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.storage_location_id', $main);
        $this->assertSame(1, StorageLocation::count());
    }

    // ------------------------------------------------------------------ add stock in one step

    private function newItem(array $extra = []): array
    {
        return array_replace(['name' => 'Starter feed', 'category' => 'feed', 'unit' => 'kg'], $extra);
    }

    public function test_stock_in_with_a_new_item_creates_the_item_and_the_stock_together_and_is_idempotent(): void
    {
        $key = (string) Str::uuid();
        $payload = ['new_item' => $this->newItem(), 'reason' => 'opening_balance', 'components' => [['quantity' => '40', 'unit' => 'kg']], 'recorded_at' => $this->at(2), 'idempotency_key' => $key];
        $res = $this->postJson('/api/v1/inventory/stock-in', $payload)->assertCreated()->assertJsonPath('data.item_reused', false);
        $this->assertSame(1, InventoryItem::count());
        $item = InventoryItem::sole();
        $this->assertSame(['Starter feed', 'feed', 'kg'], [$item->name, $item->category->value, $item->stockUnit->code]);
        $this->assertSame($item->id, $res->json('data.inventory_item_id'));
        $this->assertSame($this->mainId(), $res->json('data.storage_location_id'));
        // Retry: same movement, no second item or movement.
        $this->postJson('/api/v1/inventory/stock-in', $payload)->assertCreated()->assertJsonPath('data.id', $res->json('data.id'))->assertJsonPath('data.item_reused', false);
        $this->assertSame([1, 1], [InventoryItem::count(), InventoryMovement::count()]);
        $payload['components'][0]['quantity'] = '41';
        $this->postJson('/api/v1/inventory/stock-in', $payload)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
    }

    public function test_stock_in_with_a_new_item_reuses_an_existing_item_of_the_same_name_and_category(): void
    {
        $existing = $this->item('Starter Feed'); // different case, same normalised name
        $res = $this->stockIn(['new_item' => $this->newItem(['name' => '  starter   feed '])], '10')->assertCreated()->assertJsonPath('data.item_reused', true);
        $this->assertSame($existing, $res->json('data.inventory_item_id'));
        $this->assertSame(1, InventoryItem::count()); // never a duplicate
        $this->assertSame(1, InventoryMovement::where('inventory_item_id', $existing)->count()); // the stock went into the existing item
        // Same name in another category is the usual name clash, not a second item.
        $this->stockIn(['new_item' => $this->newItem(['category' => 'medicine'])], '10')->assertStatus(409)->assertJsonPath('code', 'inventory_item_exists');
        $this->assertSame(1, InventoryItem::count());
        // An inactive item is not silently reused.
        $old = $this->item('Old feed');
        $this->patchJson('/api/v1/inventory/items/'.$old, ['is_active' => false])->assertOk();
        $this->stockIn(['new_item' => $this->newItem(['name' => 'Old feed'])], '10')->assertStatus(409)->assertJsonPath('code', 'inventory_item_exists');
        $this->assertSame(2, InventoryItem::count());
    }

    public function test_new_item_uses_the_item_validation_and_excludes_the_other_ways_to_name_stock(): void
    {
        $this->stockIn(['new_item' => $this->newItem(['unit' => 'bag'])])->assertUnprocessable()->assertJsonValidationErrors('new_item.unit');
        $this->stockIn(['new_item' => ['name' => 'X']])->assertUnprocessable()->assertJsonValidationErrors(['new_item.category', 'new_item.unit']);
        $this->stockIn(['new_item' => $this->newItem(), 'inventory_item_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->stockIn([])->assertUnprocessable();
        $this->assertSame([0, 0], [InventoryItem::count(), InventoryMovement::count()]);
        // A failure after the item would have been created leaves no orphan item.
        $this->stockIn(['new_item' => $this->newItem()], '10', ['recorded_at' => now()->utc()->addDay()->format('Y-m-d\TH:i:s\Z')])->assertUnprocessable();
        $this->assertSame(0, InventoryItem::count());
    }

    public function test_item_search_returns_the_current_quantity_for_suggestions(): void
    {
        $a = $this->item('Feed A');
        $this->item('Feed B');
        $this->item('Medicine C', 'medicine');
        $this->stockedIn($a, $this->store('Feed room'), '10');
        $rows = $this->getJson('/api/v1/inventory/items?search=feed')->assertOk()->json('data');
        $this->assertSame(['Feed A', 'Feed B'], array_column($rows, 'name'));
        $this->assertSame(['10', 'kg'], [$rows[0]['stock']['quantity'], $rows[0]['stock']['unit']]);
        $this->assertSame('0', $rows[1]['stock']['quantity']);
        $this->getJson('/api/v1/inventory/items?search=nothing')->assertOk()->assertJsonCount(0, 'data');
    }

    // ------------------------------------------------------------------ managing stores

    public function test_a_farms_first_store_is_main_and_make_main_moves_the_flag(): void
    {
        $a = $this->store('Feed room');
        $b = $this->store('Cold room');
        $this->assertSame([true, false], [$this->getJson('/api/v1/storage-locations/'.$a)->json('data.is_main'), $this->getJson('/api/v1/storage-locations/'.$b)->json('data.is_main')]);
        $this->postJson('/api/v1/storage-locations/'.$b.'/make-main')->assertOk()->assertJsonPath('data.is_main', true);
        $this->postJson('/api/v1/storage-locations/'.$b.'/make-main')->assertOk(); // idempotent
        $this->assertSame([$b], StorageLocation::where('is_default', true)->pluck('id')->all());
        $this->assertFalse($this->getJson('/api/v1/storage-locations/'.$a)->json('data.is_main'));
        $this->stockIn(['new_item' => $this->newItem()])->assertCreated()->assertJsonPath('data.storage_location_id', $b);
        $this->assertContains(true, array_column($this->getJson('/api/v1/storage-locations')->json('data'), 'is_main'));
    }

    public function test_the_main_store_cannot_be_deactivated_until_another_is_made_main(): void
    {
        $a = $this->store('Feed room');
        $b = $this->store('Cold room');
        $this->patchJson('/api/v1/storage-locations/'.$a, ['is_active' => false])->assertStatus(409)->assertJsonPath('code', 'main_store_required');
        $this->assertTrue((bool) StorageLocation::findOrFail($a)->is_active);
        $this->patchJson('/api/v1/storage-locations/'.$b, ['is_active' => false])->assertOk(); // a non-main store is free to deactivate
        $this->postJson('/api/v1/storage-locations/'.$b.'/make-main')->assertStatus(409)->assertJsonPath('code', 'location_inactive'); // inactive cannot be main
        $c = $this->store('Dry store');
        $this->postJson('/api/v1/storage-locations/'.$c.'/make-main')->assertOk();
        $this->patchJson('/api/v1/storage-locations/'.$a, ['is_active' => false])->assertOk();
    }

    public function test_make_main_is_permissioned_and_farm_scoped(): void
    {
        $a = $this->store('Feed room');
        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $this->postJson('/api/v1/storage-locations/'.$a.'/make-main')->assertNotFound();
        $this->signInAs($this->member(\App\Enums\FarmRole::FarmWorker));
        $this->postJson('/api/v1/storage-locations/'.$a.'/make-main')->assertForbidden();
    }

    public function test_the_database_allows_only_one_main_store_per_farm(): void
    {
        $a = $this->store('Feed room');
        $b = $this->store('Cold room');
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DB::table('storage_locations')->where('id', $b)->update(['is_default' => true]);
    }

    public function test_the_migration_marks_a_main_store_for_existing_farms_by_the_agreed_rules(): void
    {
        $named = $this->farmWithStores(['Cold room', 'main store', 'Other']); // an active store named Main Store
        $single = $this->farmWithStores(['Only store']); // the only active store
        $several = $this->farmWithStores(['Store 1', 'Store 2']); // ambiguous: left unset
        $onlyInactiveName = $this->farmWithStores(['Main Store', 'Store 2'], inactive: ['Main Store']); // an inactive "Main Store" does not count; two stores remain, one active -> that one
        $none = $this->farmWithStores([]);
        DB::table('storage_locations')->update(['is_default' => false]);

        (require database_path('migrations/2026_11_07_100000_add_main_store_to_storage_locations.php'))->runBackfill();

        $main = fn (Farm $farm) => StorageLocation::where('farm_id', $farm->id)->where('is_default', true)->pluck('name')->all();
        $this->assertSame(['main store'], $main($named));
        $this->assertSame(['Only store'], $main($single));
        $this->assertSame([], $main($several));
        $this->assertSame(['Store 2'], $main($onlyInactiveName));
        $this->assertSame([], $main($none));
    }

    /** @param list<string> $names @param list<string> $inactive */
    private function farmWithStores(array $names, array $inactive = []): Farm
    {
        [$owner, $farm] = $this->otherFarm();
        $this->signInAs($owner);
        $ids = [];
        foreach ($names as $name) {
            $ids[$name] = $this->store($name);
        }
        foreach ($inactive as $name) {
            DB::table('storage_locations')->where('id', $ids[$name])->update(['is_active' => false, 'is_default' => false]);
        }
        $this->signInAs($this->owner);

        return $farm;
    }
}
