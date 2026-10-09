<?php

namespace Tests\Feature\Inventory;

use App\Enums\FarmRole;
use App\Models\BreedingProject;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\OperationalRecord;
use App\Models\OperationType;
use App\Models\Sale;
use App\Models\Species;
use App\Models\SpeciesCapability;
use App\Models\StorageLocation;
use App\Services\Inventory\OutputStockService;
use App\Services\Inventory\StockReasonCatalogue;
use App\Support\Access\FarmContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Team\TeamTestCase;

/**
 * Feed, eggs and milk: one real-world event -> one entry -> every required effect (record, ledger, sale, breeding project).
 * Balances always come from the movement ledger; production totals are a separate, equally true figure.
 */
class OutputStockTest extends TeamTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->onPlan('farm-pro');
        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
        $this->signInAs($this->owner);
    }

    // ------------------------------------------------------------------ helpers

    private function at(int $hoursAgo = 1): string
    {
        return now()->utc()->subHours($hoursAgo)->format('Y-m-d\TH:i:s\Z');
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    private function today(): string
    {
        return now('Africa/Lagos')->toDateString();
    }

    private function cycle(string $species = 'chicken', string $name = 'Flock'): string
    {
        $operation = Species::where('code', $species)->firstOrFail()->operationType->code;

        return $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => $name.' '.Str::random(4), 'operation_type_id' => OperationType::where('code', $operation)->firstOrFail()->id,
            'species_id' => Species::where('code', $species)->firstOrFail()->id, 'production_purpose' => ($species === 'honeybee' ? 'colony_breeding' : 'breeding'), 'initial_population' => 100, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
    }

    private function crates(int $perCrate = 30): string
    {
        $context = $this->postJson('/api/v1/settings/measurement-contexts', ['name' => 'Eggs'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/settings/package-conversions', ['context_type' => 'custom', 'context_id' => $context, 'package_unit' => 'crate', 'target_unit' => 'piece', 'quantity_per_package' => $perCrate])->assertCreated();

        return $context;
    }

    private function produce(string $cycle, string $type, array $components, array $details = [], int $hoursAgo = 10)
    {
        return $this->postJson('/api/v1/records', ['production_cycle_id' => $cycle, 'type' => $type, 'details' => ['components' => $components] + $details, 'recorded_at' => $this->at($hoursAgo), 'idempotency_key' => $this->key()]);
    }

    private function eggs(string $cycle, array $components, array $details = [], int $hoursAgo = 10)
    {
        return $this->produce($cycle, 'egg_collection', $components, $details, $hoursAgo);
    }

    private function milk(string $cycle, string $litres, int $hoursAgo = 10)
    {
        return $this->produce($cycle, 'milk', [['quantity' => $litres, 'unit' => 'l']], [], $hoursAgo);
    }

    private function item(string $kind): InventoryItem
    {
        return InventoryItem::where('system_key', 'output:'.$kind)->firstOrFail();
    }

    private function ledger(InventoryItem $item)
    {
        return InventoryMovement::where('inventory_item_id', $item->id)->orderBy('recorded_at')->orderBy('id')->get();
    }

    /** Canonical ledger total (pieces / ml / g) derived from movements. */
    private function ledgerSum(InventoryItem $item): string
    {
        return rtrim(rtrim((string) InventoryMovement::where('inventory_item_id', $item->id)->sum('quantity_delta'), '0'), '.');
    }

    private function available(string $kind): array
    {
        return $this->getJson('/api/v1/inventory/output-balances')->assertOk()->json('data.'.$kind.'.available');
    }

    private function store(string $name = 'Egg room'): string
    {
        return $this->postJson('/api/v1/storage-locations', ['name' => $name, 'type' => 'store'])->assertCreated()->json('data.id');
    }

    private function stockIn(array $target, string $reason, string $qty, string $unit, array $extra = [])
    {
        return $this->postJson('/api/v1/inventory/stock-in', array_replace($target + ['reason' => $reason, 'components' => [['quantity' => $qty, 'unit' => $unit]],
            'recorded_at' => $this->at(11), 'idempotency_key' => $this->key()], $extra));
    }

    private function stockOut(array $target, string $reason, string $qty, string $unit, array $extra = [])
    {
        return $this->postJson('/api/v1/inventory/stock-out', array_replace($target + ['reason' => $reason, 'components' => [['quantity' => $qty, 'unit' => $unit]],
            'recorded_at' => $this->at(2), 'idempotency_key' => $this->key()], $extra));
    }

    private function sell(string $item, string $loc, string $qty, string $unit, string $amount)
    {
        return $this->postJson('/api/v1/sales', ['recorded_at' => $this->at(3), 'idempotency_key' => $this->key(),
            'items' => [['kind' => 'stock', 'inventory_item_id' => $item, 'storage_location_id' => $loc, 'components' => [['quantity' => $qty, 'unit' => $unit]], 'amount' => $amount]]]);
    }

    private function incubate(string $cycle, int $eggs, array $extra = [])
    {
        return $this->postJson('/api/v1/breeding-projects', array_replace(['production_cycle_id' => $cycle, 'workflow' => 'incubation', 'start_date' => $this->today(), 'eggs_set' => $eggs,
            'consume_egg_stock' => true, 'idempotency_key' => $this->key()], $extra));
    }

    private function feedItem(string $name = 'Starter feed'): string
    {
        return $this->postJson('/api/v1/inventory/items', ['name' => $name, 'category' => 'feed', 'stock_unit' => 'kg'])->assertCreated()->json('data.id');
    }

    // ------------------------------------------------------------------ eggs

    public function test_eggs_collected_sold_incubated_and_damaged_leave_the_ledger_balance_and_every_movement_explains_itself(): void
    {
        $cycle = $this->cycle();
        $context = $this->crates();

        // 3 crates + 14 eggs, ONE entry.
        $record = $this->eggs($cycle, [['quantity' => 3, 'unit' => 'crate'], ['quantity' => 14, 'unit' => 'piece']], ['context' => ['type' => 'custom', 'id' => $context]])->assertCreated()->json('data');
        $this->assertSame('104', $record['measurement']['normalized']['quantity']);

        $item = $this->item('eggs');
        $store = StorageLocation::where('farm_id', $this->farm->id)->sole();
        $this->assertSame('Main Store', $store->name); // the farmer never set up inventory
        $this->assertSame('produce', $item->category->value);
        $this->assertSame('piece', $item->stockUnit->code);
        $in = InventoryMovement::where('operational_record_id', $record['id'])->sole();
        $this->assertSame($in->id, $record['inventory_movement_id']);
        $this->assertSame(['stock_in', 'production', $cycle, $store->id], [$in->type->value, $in->reason, $in->production_cycle_id, $in->storage_location_id]);
        $this->assertSame('104', $this->available('eggs')['quantity']);
        $this->assertSame($store->id, $record['details']['inventory']['storage_location_id']);

        // Sold 30 through the Sale workflow (the one authoritative sale effect).
        $sale = $this->sell($item->id, $store->id, '30', 'piece', '900.00')->assertCreated()->json('data');
        // 40 into the incubator through the breeding project (the one authoritative incubation effect).
        $project = $this->incubate($cycle, 40)->assertCreated()->json('data');
        $this->assertSame(['consumed' => 40, 'returned' => 0, 'net_out' => 40], array_intersect_key($project['egg_stock'], array_flip(['consumed', 'returned', 'net_out'])));
        // 4 broken: inventory only.
        $this->stockOut(['output' => 'eggs'], 'damaged', '4', 'piece')->assertCreated();

        $this->assertSame('30', $this->available('eggs')['quantity']);
        $this->assertSame('30', $this->ledgerSum($item));
        $this->assertSame('30', $this->getJson('/api/v1/inventory/items/'.$item->id)->json('data.stock.quantity'));

        // Production and availability are independent truths: 104 produced, 30 available.
        $this->assertSame(104, (int) OperationalRecord::where('type', 'egg_collection')->get()->sum(fn ($r) => $r->measurement['normalized']['quantity']));
        $this->assertSame(1, OperationalRecord::where('type', 'egg_collection')->count());

        // Every movement links to the domain event that explains it.
        $history = collect($this->getJson('/api/v1/inventory/movements?inventory_item_id='.$item->id)->assertOk()->json('data'))->keyBy('reason');
        $this->assertSame(['operational_record', $record['id']], [$history['production']['source']['type'], $history['production']['source']['id']]);
        $this->assertSame(['sale', $sale['id']], [$history['sale']['source']['type'], $history['sale']['source']['id']]);
        $this->assertSame(['breeding_project', $project['id']], [$history['incubation']['source']['type'], $history['incubation']['source']['id']]);
        $this->assertSame('manual', $history['damaged']['source']['type']);
        $this->assertSame('Put into incubation', $history['incubation']['reason_label']);
        $this->assertSame($cycle, $history['incubation']['production_cycle_id']);
        $this->assertSame($project['id'], $history['incubation']['breeding_project_id']);
        // No duplicate side effects: exactly one movement per event.
        $this->assertSame(4, InventoryMovement::where('inventory_item_id', $item->id)->count());
        $this->assertSame(1, Sale::count());
        $this->assertSame(1, BreedingProject::count());
        // The breeding project is filterable history too.
        $this->assertCount(1, $this->getJson('/api/v1/inventory/movements?breeding_project_id='.$project['id'])->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/inventory/movements?reason=damaged')->json('data'));
    }

    public function test_donated_purchased_and_received_eggs_are_stock_only_and_never_a_production_record(): void
    {
        $this->stockIn(['output' => 'eggs'], 'donation', '20', 'piece')->assertCreated();
        $this->stockIn(['output' => 'eggs'], 'purchase', '10', 'piece')->assertCreated();
        $this->stockIn(['output' => 'eggs'], 'received', '5', 'piece')->assertCreated();
        $this->assertSame(0, OperationalRecord::count());
        $this->assertSame('35', $this->available('eggs')['quantity']);
        $this->assertSame(['donation', 'purchase', 'received'], InventoryMovement::orderBy('id')->pluck('reason')->sort()->values()->all());

        // "Produced on farm" is only ever a production record: the manual path refuses it, so stock and production cannot disagree.
        $this->stockIn(['output' => 'eggs'], 'production', '5', 'piece')->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame(3, InventoryMovement::count());

        // Own production adds on top; production metrics count only the record.
        $cycle = $this->cycle();
        $this->eggs($cycle, [['quantity' => 104, 'unit' => 'piece']])->assertCreated();
        $this->assertSame('139', $this->available('eggs')['quantity']);
        $this->assertSame(1, OperationalRecord::where('type', 'egg_collection')->count());
        $this->assertSame(1, StorageLocation::count());
    }

    public function test_eggs_given_away_used_at_home_or_lost_are_inventory_only(): void
    {
        $cycle = $this->cycle();
        $this->eggs($cycle, [['quantity' => 120, 'unit' => 'piece']])->assertCreated();
        foreach ([['donation', '30'], ['internal_use', '6'], ['spoiled', '4'], ['lost', '2']] as [$reason, $qty]) {
            $this->stockOut(['output' => 'eggs'], $reason, $qty, 'piece')->assertCreated()->assertJsonPath('data.reason', $reason);
        }
        $this->assertSame('78', $this->available('eggs')['quantity']);
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, BreedingProject::count());
        $this->assertSame(1, OperationalRecord::where('type', 'egg_collection')->count());
        // Not more than is there, now or in dated history.
        $this->stockOut(['output' => 'eggs'], 'donation', '79', 'piece')->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
    }

    public function test_system_owned_reasons_are_refused_on_the_manual_endpoints_so_an_event_is_entered_once(): void
    {
        $cycle = $this->cycle();
        $this->eggs($cycle, [['quantity' => 50, 'unit' => 'piece']])->assertCreated();
        $feed = $this->feedItem();
        $store = StorageLocation::firstOrFail()->id;
        $this->stockIn(['inventory_item_id' => $feed, 'storage_location_id' => $store], 'purchase', '100', 'kg')->assertCreated();

        foreach (['sale' => 'POST /sales', 'incubation' => 'POST /breeding-projects', 'production_use' => 'POST /records'] as $reason => $route) {
            $response = $this->stockOut(['output' => 'eggs'], $reason, '1', 'piece')->assertUnprocessable()->assertJsonValidationErrors('reason');
            $this->assertStringContainsString($route, json_encode($response->json(), JSON_UNESCAPED_SLASHES));
        }
        $this->stockOut(['inventory_item_id' => $feed, 'storage_location_id' => $store], 'production_use', '1', 'kg')->assertUnprocessable();
        $this->stockIn(['output' => 'eggs'], 'returned', '1', 'piece')->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame('50', $this->available('eggs')['quantity']);
        $this->assertSame('100', $this->getJson('/api/v1/inventory/items/'.$feed)->json('data.stock.quantity'));
    }

    // ------------------------------------------------------------------ incubation

    public function test_incubation_consumes_eggs_atomically_with_the_project_and_refuses_when_stock_is_short(): void
    {
        $cycle = $this->cycle();
        $this->incubate($cycle, 40)->assertStatus(409)->assertJsonPath('code', 'insufficient_stock'); // never had eggs
        $this->assertSame(0, BreedingProject::count());
        $this->assertSame(0, InventoryItem::where('system_key', 'output:eggs')->count()); // and the failed attempt created nothing

        $this->eggs($cycle, [['quantity' => 30, 'unit' => 'piece']])->assertCreated();
        $this->incubate($cycle, 40)->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->assertSame(0, BreedingProject::count());
        $this->assertSame('30', $this->available('eggs')['quantity']);

        $payload = ['production_cycle_id' => $cycle, 'workflow' => 'incubation', 'start_date' => $this->today(), 'eggs_set' => 25, 'consume_egg_stock' => true, 'idempotency_key' => $this->key()];
        $first = $this->postJson('/api/v1/breeding-projects', $payload)->assertCreated()->json('data');
        $retry = $this->postJson('/api/v1/breeding-projects', $payload)->assertCreated()->json('data');
        $this->assertSame($first['id'], $retry['id']);
        $this->assertSame(1, InventoryMovement::where('breeding_project_id', $first['id'])->count()); // the retry took nothing
        $this->assertSame('5', $this->available('eggs')['quantity']);

        // Without the flag nothing touches stock (compatibility with existing clients), and hatching changes nothing either.
        $legacy = $this->postJson('/api/v1/breeding-projects', ['production_cycle_id' => $cycle, 'workflow' => 'incubation', 'start_date' => $this->today(), 'eggs_set' => 50, 'idempotency_key' => $this->key()])->assertCreated()->json('data');
        $this->assertNull($legacy['egg_stock']);
        $this->postJson('/api/v1/breeding-projects/'.$first['id'].'/outcomes', ['live_count' => 20, 'loss_count' => 5, 'recorded_at' => $this->at(1), 'idempotency_key' => $this->key()])->assertCreated();
        $this->assertSame('5', $this->available('eggs')['quantity']);
    }

    public function test_incubation_rejects_pregnancy_workflows_and_a_missing_store_choice(): void
    {
        $cycle = $this->cycle('cattle', 'Cows');
        $this->postJson('/api/v1/breeding-projects', ['production_cycle_id' => $cycle, 'workflow' => 'pregnancy', 'start_date' => $this->today(), 'females_bred' => 2, 'consume_egg_stock' => true, 'idempotency_key' => $this->key()])
            ->assertUnprocessable()->assertJsonValidationErrors('consume_egg_stock');

        $hens = $this->cycle();
        $a = $this->store('Store A');
        $b = $this->store('Store B');
        $this->stockIn(['output' => 'eggs', 'storage_location_id' => $a], 'donation', '60', 'piece')->assertCreated();
        $this->incubate($hens, 10)->assertUnprocessable()->assertJsonValidationErrors('egg_storage_location_id'); // two stores: choose
        $this->incubate($hens, 10, ['egg_storage_location_id' => $b])->assertStatus(409)->assertJsonPath('code', 'insufficient_stock'); // nothing in B
        $this->incubate($hens, 10, ['egg_storage_location_id' => $a])->assertCreated();
        $this->assertSame('50', $this->available('eggs')['quantity']);
    }

    public function test_cancelling_an_incubation_returns_eggs_only_when_the_farmer_says_so(): void
    {
        $cycle = $this->cycle();
        $this->eggs($cycle, [['quantity' => 100, 'unit' => 'piece']])->assertCreated();

        $silent = $this->incubate($cycle, 40)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/breeding-projects/'.$silent.'/cancel', ['reason' => 'Power cut'])->assertOk();
        $this->assertSame('60', $this->available('eggs')['quantity']); // no instruction = no inventory increase

        $partial = $this->incubate($cycle, 40)->assertCreated()->json('data.id');
        $this->assertSame('20', $this->available('eggs')['quantity']);
        $this->postJson('/api/v1/breeding-projects/'.$partial.'/cancel', ['reason' => 'Cracked', 'eggs_returned_to_stock' => 41])->assertUnprocessable()->assertJsonValidationErrors('eggs_returned_to_stock');
        $this->assertSame('active', BreedingProject::findOrFail($partial)->status->value); // the refused cancel changed nothing
        $project = $this->postJson('/api/v1/breeding-projects/'.$partial.'/cancel', ['reason' => 'Cracked', 'eggs_returned_to_stock' => 15])->assertOk()->json('data');
        $this->assertSame(['consumed' => 40, 'returned' => 15, 'net_out' => 25], array_intersect_key($project['egg_stock'], array_flip(['consumed', 'returned', 'net_out'])));
        $this->assertSame('35', $this->available('eggs')['quantity']);
        $returned = InventoryMovement::where('breeding_project_id', $partial)->where('reason', 'returned')->sole();
        $this->assertSame('stock_in', $returned->type->value);

        // A project that never took eggs cannot "return" any.
        $legacy = $this->postJson('/api/v1/breeding-projects', ['production_cycle_id' => $cycle, 'workflow' => 'incubation', 'start_date' => $this->today(), 'eggs_set' => 5, 'idempotency_key' => $this->key()])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/breeding-projects/'.$legacy.'/cancel', ['reason' => 'x', 'eggs_returned_to_stock' => 1])->assertUnprocessable();
        $this->assertSame('35', $this->available('eggs')['quantity']);
    }

    public function test_editing_eggs_set_keeps_the_ledger_explained(): void
    {
        $cycle = $this->cycle();
        $this->eggs($cycle, [['quantity' => 100, 'unit' => 'piece']])->assertCreated();
        $project = $this->incubate($cycle, 40)->assertCreated()->json('data.id');

        $this->patchJson('/api/v1/breeding-projects/'.$project, ['eggs_set' => 45])->assertOk(); // 5 more eggs go in
        $this->assertSame('55', $this->available('eggs')['quantity']);
        $this->patchJson('/api/v1/breeding-projects/'.$project, ['eggs_set' => 42])->assertOk(); // fewer set: nothing assumed usable
        $this->assertSame('55', $this->available('eggs')['quantity']);
        $this->patchJson('/api/v1/breeding-projects/'.$project, ['eggs_set' => 40, 'eggs_returned_to_stock' => 3])->assertUnprocessable(); // only 2 were removed
        $this->patchJson('/api/v1/breeding-projects/'.$project, ['eggs_set' => 40, 'eggs_returned_to_stock' => 2])->assertOk();
        $this->assertSame('57', $this->available('eggs')['quantity']);
        $this->patchJson('/api/v1/breeding-projects/'.$project, ['eggs_set' => 200])->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->assertSame(40, BreedingProject::findOrFail($project)->eggs_set); // the failed edit changed nothing
    }

    // ------------------------------------------------------------------ milk

    public function test_milk_produced_sold_and_spoiled_leaves_the_derived_balance(): void
    {
        $cycle = $this->cycle('cattle', 'Dairy herd');
        $record = $this->milk($cycle, '50')->assertCreated()->json('data');
        $this->assertSame('50000', $record['measurement']['normalized']['quantity']); // ml
        $item = $this->item('milk');
        $store = StorageLocation::sole();
        $this->assertSame('l', $item->stockUnit->code);
        $this->assertSame(['production', $cycle], [InventoryMovement::where('operational_record_id', $record['id'])->sole()->reason, InventoryMovement::where('operational_record_id', $record['id'])->sole()->production_cycle_id]);

        $sale = $this->sell($item->id, $store->id, '25', 'l', '12500.00')->assertCreated()->json('data');
        $this->stockOut(['output' => 'milk'], 'spoiled', '3', 'l')->assertCreated();

        $this->assertSame(['quantity' => '22', 'unit' => 'l'], $this->available('milk'));
        $this->assertSame('22000', $this->ledgerSum($item)); // 50 - 25 - 3 derived from movements, in ml
        $this->assertSame(1, OperationalRecord::where('type', 'milk')->count()); // production stays 50 L
        $this->assertSame(['production', 'sale', 'spoiled'], $this->ledger($item)->pluck('reason')->all());
        $this->assertSame($sale['id'], $this->ledger($item)->firstWhere('reason', 'sale')->sale_id);

        // Donated / purchased milk is stock only, never a false milk-production record.
        $this->stockIn(['output' => 'milk'], 'donation', '5', 'l')->assertCreated();
        $this->stockIn(['output' => 'milk'], 'purchase', '5', 'l')->assertCreated();
        $this->stockOut(['output' => 'milk'], 'internal_use', '2', 'l')->assertCreated();
        $this->assertSame('30', $this->available('milk')['quantity']);
        $this->assertSame(1, OperationalRecord::where('type', 'milk')->count());
    }

    public function test_milk_is_capability_driven_and_seeded_for_the_dairy_species(): void
    {
        foreach (['cattle', 'goat', 'sheep', 'camel', 'water_buffalo'] as $code) {
            $this->assertTrue(SpeciesCapability::where('species_id', Species::where('code', $code)->value('id'))->where('enabled', true)->whereHas('capability', fn ($q) => $q->where('code', 'produces_milk'))->exists(), $code.' should be milked');
        }
        foreach (['chicken', 'pig', 'rabbit', 'horse', 'donkey'] as $code) {
            $this->assertFalse(SpeciesCapability::where('species_id', Species::where('code', $code)->value('id'))->whereHas('capability', fn ($q) => $q->where('code', 'produces_milk'))->exists(), $code.' is not seeded as milked');
        }
        $this->milk($this->cycle('chicken'), '5')->assertUnprocessable(); // behaviour follows the capability, not a species name

        $goats = $this->cycle('goat', 'Goats');
        $this->milk($goats, '3')->assertCreated();
        // The platform can disable it later: behaviour follows.
        SpeciesCapability::where('species_id', Species::where('code', 'goat')->value('id'))->whereHas('capability', fn ($q) => $q->where('code', 'produces_milk'))->update(['enabled' => false]);
        $this->milk($goats, '3')->assertUnprocessable();
        $this->assertSame('3', $this->available('milk')['quantity']);
    }

    // ------------------------------------------------------------------ feed

    public function test_feed_received_used_donated_and_spoiled_leaves_460_kg_with_one_entry_each(): void
    {
        $batch = $this->cycle('chicken', 'Broiler Batch A');
        $feed = $this->feedItem();
        $store = $this->store('Feed store');
        $this->stockIn(['inventory_item_id' => $feed, 'storage_location_id' => $store], 'purchase', '500', 'kg')->assertCreated();

        // "Used 25 kg for Batch A": ONE record -> feed_use record + the stock-out + the batch history.
        $payload = ['production_cycle_id' => $batch, 'type' => 'feed_use', 'recorded_at' => $this->at(5), 'idempotency_key' => $this->key(),
            'details' => ['feed_name' => 'Starter', 'components' => [['quantity' => '25', 'unit' => 'kg']], 'inventory' => ['item_id' => $feed, 'storage_location_id' => $store]]];
        $record = $this->postJson('/api/v1/records', $payload)->assertCreated()->json('data');
        $this->postJson('/api/v1/records', $payload)->assertCreated(); // retry: no second decrement
        $this->stockOut(['inventory_item_id' => $feed, 'storage_location_id' => $store], 'donation', '10', 'kg')->assertCreated();
        $this->stockOut(['inventory_item_id' => $feed, 'storage_location_id' => $store], 'spoiled', '5', 'kg')->assertCreated();

        $this->assertSame('460', $this->getJson('/api/v1/inventory/items/'.$feed)->json('data.stock.quantity'));
        $movements = InventoryMovement::where('inventory_item_id', $feed)->orderBy('recorded_at')->get();
        $this->assertSame(['purchase', 'production_use', 'donation', 'spoiled'], $movements->pluck('reason')->all());
        $use = $movements->firstWhere('reason', 'production_use');
        $this->assertSame([$record['id'], $batch], [$use->operational_record_id, $use->production_cycle_id]);
        $this->assertSame(1, OperationalRecord::where('type', 'feed_use')->count());
        $this->assertSame(1, InventoryMovement::where('reason', 'production_use')->count());
        $history = collect($this->getJson('/api/v1/inventory/items/'.$feed.'/movements')->json('data'))->keyBy('reason');
        $this->assertSame('Used for livestock', $history['production_use']['reason_label']);
        $this->assertSame('operational_record', $history['production_use']['source']['type']);

        // The record is reversible; the movement stays visible and the ledger compensates.
        $this->postJson('/api/v1/records/'.$record['id'].'/reverse', ['reason' => 'Wrong batch', 'recorded_at' => $this->at(1), 'idempotency_key' => $this->key()])->assertCreated();
        $this->assertSame('485', $this->getJson('/api/v1/inventory/items/'.$feed)->json('data.stock.quantity'));
        $this->stockOut(['inventory_item_id' => $feed, 'storage_location_id' => $store], 'spoiled', '486', 'kg')->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
    }

    public function test_surplus_feed_is_sold_through_the_sale_workflow_and_cancelling_restores_it(): void
    {
        $feed = $this->feedItem('Grower mash');
        $store = $this->store('Feed store');
        $this->stockIn(['inventory_item_id' => $feed, 'storage_location_id' => $store], 'opening_balance', '200', 'kg')->assertCreated();
        $medicine = $this->postJson('/api/v1/inventory/items', ['name' => 'Antibiotic', 'category' => 'medicine', 'stock_unit' => 'kg'])->assertCreated()->json('data.id');
        $this->stockIn(['inventory_item_id' => $medicine, 'storage_location_id' => $store], 'opening_balance', '10', 'kg')->assertCreated();

        $sale = $this->sell($feed, $store, '50', 'kg', '25000.00')->assertCreated()->json('data');
        $this->assertSame('150', $this->getJson('/api/v1/inventory/items/'.$feed)->json('data.stock.quantity'));
        $movement = InventoryMovement::where('sale_id', $sale['id'])->sole();
        $this->assertSame('sale', $movement->reason);
        $this->assertSame('other_income', Sale::findOrFail($sale['id'])->category->code); // feed is not "crop & produce"
        $this->assertSame(1, InventoryMovement::where('reason', 'sale')->count()); // one sale, one decrement

        $this->stockOut(['inventory_item_id' => $feed, 'storage_location_id' => $store], 'sale', '1', 'kg')->assertUnprocessable(); // the manual path is never a second way to sell
        $this->sell($medicine, $store, '1', 'kg', '100.00')->assertUnprocessable()->assertJsonValidationErrors('items.0.inventory_item_id'); // not every category is sellable
        $this->assertSame('150', $this->getJson('/api/v1/inventory/items/'.$feed)->json('data.stock.quantity'));

        $this->postJson('/api/v1/sales/'.$sale['id'].'/cancel', ['reason' => 'Buyer cancelled', 'recorded_at' => $this->at(0), 'idempotency_key' => $this->key()])->assertOk();
        $this->assertSame('200', $this->getJson('/api/v1/inventory/items/'.$feed)->json('data.stock.quantity'));
        $this->assertSame(2, InventoryMovement::where('inventory_item_id', $feed)->where('sale_id', $sale['id'])->count()); // sale + reversal, history kept
        $this->assertContains('feed', $this->getJson('/api/v1/master/inventory-options')->json('data.sellable_categories'));
    }

    public function test_every_feed_in_and_out_reason_is_accepted_for_stock_nothing_else_explains(): void
    {
        $feed = $this->feedItem();
        $store = $this->store('Feed store');
        $target = ['inventory_item_id' => $feed, 'storage_location_id' => $store];
        foreach (['opening_balance', 'purchase', 'donation', 'aid', 'received', 'production', 'other'] as $reason) {
            $this->stockIn($target, $reason, '10', 'kg')->assertCreated()->assertJsonPath('data.reason', $reason); // "produced on farm" is a plain stock-in for home-mixed feed
        }
        foreach (['donation', 'spoiled', 'lost', 'disposal', 'other', 'damaged'] as $reason) {
            $this->stockOut($target, $reason, '5', 'kg')->assertCreated()->assertJsonPath('data.reason', $reason)->assertJsonPath('data.reason_label', StockReasonCatalogue::label($reason, 'out'));
        }
        $this->assertSame('40', $this->getJson('/api/v1/inventory/items/'.$feed)->json('data.stock.quantity'));
        $this->assertSame(0, OperationalRecord::count()); // none of these is a livestock event
        $this->stockIn($target, 'bogus', '1', 'kg')->assertUnprocessable();
        // Stock counts and transfers remain their own movement types.
        $this->postJson('/api/v1/inventory/adjustments', $target + ['expected' => [['quantity' => '40', 'unit' => 'kg']], 'counted' => [['quantity' => '38', 'unit' => 'kg']], 'reason' => 'Count', 'recorded_at' => $this->at(1), 'idempotency_key' => $this->key()])->assertCreated();
        $this->assertSame('38', $this->getJson('/api/v1/inventory/items/'.$feed)->json('data.stock.quantity'));
    }

    // ------------------------------------------------------------------ reasons metadata

    public function test_reason_metadata_tells_the_frontend_which_endpoint_records_each_action(): void
    {
        $data = $this->getJson('/api/v1/master/inventory-options')->assertOk()->json('data');
        $this->assertSame(['produce', 'feed'], $data['sellable_categories']);
        $kinds = $data['reasons']['by_item_kind'];
        $this->assertSame(['feed', 'eggs', 'milk', 'general'], array_keys($kinds));

        $feedOut = collect($kinds['feed']['out'])->keyBy('code');
        $this->assertSame(['production_use', 'Used for livestock', false, true], [$feedOut['production_use']['code'], $feedOut['production_use']['label'], $feedOut['production_use']['manual'], $feedOut['production_use']['creates_operational_record']]);
        $this->assertSame(['record', 'feed_use'], [$feedOut['production_use']['route']['kind'], $feedOut['production_use']['route']['record_type']]);
        $this->assertSame('/sales', $feedOut['sale']['route']['path']);
        $this->assertSame(['Gift / Donation', 'Spoiled / Contaminated', 'Lost / Stolen', 'Disposal / Compost'], [$feedOut['donation']['label'], $feedOut['spoiled']['label'], $feedOut['lost']['label'], $feedOut['disposal']['label']]);
        $this->assertTrue($feedOut['spoiled']['manual']);

        $eggsOut = collect($kinds['eggs']['out'])->keyBy('code');
        $this->assertSame('Put into incubation', $eggsOut['incubation']['label']);
        $this->assertSame('/breeding-projects', $eggsOut['incubation']['route']['path']);
        $this->assertFalse($eggsOut['incubation']['manual']);
        $this->assertSame(['sale', 'incubation', 'donation', 'internal_use', 'damaged', 'spoiled', 'lost', 'other'], array_slice(array_column($kinds['eggs']['out'], 'code'), 0, 8));
        $this->assertNotContains('incubation', array_column($kinds['milk']['out'], 'code')); // no biological assumption for milk

        $eggsIn = collect($kinds['eggs']['in'])->keyBy('code');
        $this->assertTrue($eggsIn['production']['creates_operational_record']);
        $this->assertSame('egg_collection', $eggsIn['production']['route']['record_type']);
        $this->assertFalse($eggsIn['donation']['creates_operational_record']);
        $this->assertSame('milk', collect($kinds['milk']['in'])->keyBy('code')['production']['route']['record_type']);
        $this->assertSame('production', collect($kinds['feed']['in'])->firstWhere('code', 'production')['code']);

        // Flat lists keep every stored reason, with the legacy ones marked.
        $this->assertTrue(collect($data['reasons']['out'])->keyBy('code')['use']['legacy']);
        $this->assertContains('incubation', $data['stock_out_reasons']);
        $this->assertContains('use', $data['stock_out_reasons']); // existing clients keep working

        $types = collect($this->getJson('/api/v1/master/record-types')->assertOk()->json('data'))->keyBy('type');
        $this->assertTrue($types['egg_collection']['inventory_effect_enabled']);
        $this->assertSame(['produce', 'in', true, 'eggs'], [$types['egg_collection']['inventory_category'], $types['egg_collection']['inventory_direction'], $types['egg_collection']['inventory_automatic'], $types['egg_collection']['inventory_output']]);
        $this->assertSame('milk', $types['milk']['inventory_output']);
    }

    // ------------------------------------------------------------------ output item resolution

    public function test_the_output_item_is_created_once_per_farm_and_reused(): void
    {
        $cycle = $this->cycle();
        $this->eggs($cycle, [['quantity' => 10, 'unit' => 'piece']])->assertCreated();
        $this->eggs($cycle, [['quantity' => 10, 'unit' => 'piece']])->assertCreated();
        $this->stockIn(['output' => 'eggs'], 'donation', '5', 'piece')->assertCreated();
        $this->assertSame(1, InventoryItem::where('system_key', 'output:eggs')->count());
        $this->assertSame(1, StorageLocation::count());
        $this->assertSame('25', $this->available('eggs')['quantity']);

        // The unique (farm, system_key) index is the backstop against a concurrent first use.
        $item = $this->item('eggs');
        $this->expectException(QueryException::class);
        DB::table('inventory_items')->insert(['id' => (string) Str::uuid7(), 'farm_id' => $this->farm->id, 'name' => 'Dup', 'normalized_name' => 'dup', 'system_key' => 'output:eggs', 'category' => 'produce',
            'stock_unit_id' => $item->stock_unit_id, 'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_an_existing_compatible_manual_item_is_adopted_and_nothing_else_is_changed(): void
    {
        $manual = $this->postJson('/api/v1/inventory/items', ['name' => 'Eggs', 'category' => 'produce', 'stock_unit' => 'piece', 'description' => 'My egg stock', 'low_stock_threshold' => ['quantity' => '30', 'unit' => 'piece']])->assertCreated()->json('data.id');
        $this->eggs($this->cycle(), [['quantity' => 12, 'unit' => 'piece']])->assertCreated();
        $adopted = InventoryItem::findOrFail($manual);
        $this->assertSame('output:eggs', $adopted->system_key);
        $this->assertSame(['Eggs', 'My egg stock'], [$adopted->name, $adopted->description]);
        $this->assertSame(1, InventoryItem::count());
        $this->assertSame('12', $this->getJson('/api/v1/inventory/items/'.$manual)->json('data.stock.quantity'));
    }

    public function test_an_incompatible_manual_item_is_left_alone_and_a_separate_output_item_is_created(): void
    {
        $feedMilk = $this->postJson('/api/v1/inventory/items', ['name' => 'Milk', 'category' => 'feed', 'stock_unit' => 'kg'])->assertCreated()->json('data.id'); // wrong category and dimension
        $lots = $this->postJson('/api/v1/inventory/items', ['name' => 'Eggs', 'category' => 'produce', 'stock_unit' => 'piece', 'tracks_lots' => true])->assertCreated()->json('data.id'); // needs a lot per movement
        $this->milk($this->cycle('cattle', 'Dairy'), '4')->assertCreated();
        $this->eggs($this->cycle(), [['quantity' => 12, 'unit' => 'piece']])->assertCreated();

        $this->assertNull(InventoryItem::findOrFail($feedMilk)->system_key);
        $this->assertNull(InventoryItem::findOrFail($lots)->system_key);
        $this->assertSame('Milk (farm output)', $this->item('milk')->name);
        $this->assertSame('Eggs (farm output)', $this->item('eggs')->name);
        $this->assertSame(0, InventoryMovement::whereIn('inventory_item_id', [$feedMilk, $lots])->count());
    }

    public function test_output_balances_is_read_only_and_never_creates_the_items(): void
    {
        $data = $this->getJson('/api/v1/inventory/output-balances')->assertOk()->json('data');
        $this->assertFalse($data['eggs']['exists']);
        $this->assertNull($data['eggs']['inventory_item_id']);
        $this->assertSame(['quantity' => '0', 'unit' => 'piece'], $data['eggs']['available']);
        $this->assertSame(['quantity' => '0', 'unit' => 'l'], $data['milk']['available']);
        $this->assertSame(0, InventoryItem::count());
        $this->assertSame(0, StorageLocation::count()); // a dashboard load creates no Main Store either

        $this->stockOut(['output' => 'eggs'], 'donation', '1', 'piece')->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->assertSame(0, InventoryItem::count());
    }

    // ------------------------------------------------------------------ storage location rules

    public function test_main_store_is_created_once_when_the_farm_has_no_store(): void
    {
        $cycle = $this->cycle();
        $this->eggs($cycle, [['quantity' => 10, 'unit' => 'piece']])->assertCreated();
        $this->eggs($cycle, [['quantity' => 10, 'unit' => 'piece']])->assertCreated();
        $this->milk($this->cycle('cattle', 'Dairy'), '2')->assertCreated();
        $this->assertSame(['Main Store'], StorageLocation::pluck('name')->all());
        $this->assertSame(['store', null], [StorageLocation::first()->type, StorageLocation::first()->location_id]);
    }

    public function test_main_store_name_clash_gets_a_numbered_name_and_an_inactive_store_is_not_reused(): void
    {
        $old = $this->store('Main Store');
        $this->patchJson('/api/v1/storage-locations/'.$old, ['is_active' => false])->assertOk();
        $this->eggs($this->cycle(), [['quantity' => 10, 'unit' => 'piece']])->assertCreated();
        $this->assertSame(['Main Store', 'Main Store 2'], StorageLocation::orderBy('name')->pluck('name')->all());
        $this->assertSame('Main Store 2', StorageLocation::findOrFail(InventoryMovement::sole()->storage_location_id)->name);
    }

    public function test_the_single_active_store_is_used_and_several_require_a_choice(): void
    {
        $only = $this->store('Egg room');
        $cycle = $this->cycle();
        $this->eggs($cycle, [['quantity' => 10, 'unit' => 'piece']])->assertCreated();
        $this->assertSame([$only], InventoryMovement::pluck('storage_location_id')->unique()->values()->all());
        $this->assertSame(1, StorageLocation::count()); // no Main Store when a store exists

        $second = $this->store('Cold room');
        $this->eggs($cycle, [['quantity' => 10, 'unit' => 'piece']])->assertUnprocessable()->assertJsonValidationErrors('details.inventory.storage_location_id');
        $this->assertSame(1, OperationalRecord::where('type', 'egg_collection')->count()); // refused: no half-written record
        $this->eggs($cycle, [['quantity' => 10, 'unit' => 'piece']], ['inventory' => ['storage_location_id' => $second]])->assertCreated();
        $this->stockIn(['output' => 'eggs'], 'donation', '1', 'piece')->assertUnprocessable()->assertJsonValidationErrors('storage_location_id');
        $this->assertSame(['10', '10'], collect([$only, $second])->map(fn ($id) => (string) (InventoryMovement::where('storage_location_id', $id)->sum('quantity_delta') + 0))->all());

        $balances = $this->getJson('/api/v1/inventory/output-balances')->json('data.eggs.by_storage_location');
        $this->assertEqualsCanonicalizing([$only, $second], array_column($balances, 'storage_location_id'));
        // Taking out also needs the choice once there are several stores.
        $this->stockOut(['output' => 'eggs'], 'donation', '1', 'piece')->assertUnprocessable();
        $this->stockOut(['output' => 'eggs', 'storage_location_id' => $second], 'donation', '1', 'piece')->assertCreated();
    }

    public function test_a_zero_collection_is_a_production_record_that_moves_no_stock(): void
    {
        $this->eggs($this->cycle(), [['quantity' => 0, 'unit' => 'piece']])->assertCreated();
        $this->assertSame(1, OperationalRecord::where('type', 'egg_collection')->count());
        $this->assertSame(0, InventoryMovement::count());
    }

    // ------------------------------------------------------------------ packages

    public function test_crates_resolve_through_the_output_item_only_when_a_conversion_is_configured(): void
    {
        $cycle = $this->cycle();
        $this->eggs($cycle, [['quantity' => 1, 'unit' => 'crate']])->assertUnprocessable()->assertJsonPath('code', 'conversion_not_configured');
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, InventoryItem::count()); // the refused attempt left nothing behind

        $this->eggs($cycle, [['quantity' => 6, 'unit' => 'piece']])->assertCreated();
        $item = $this->item('eggs');
        $this->postJson('/api/v1/settings/package-conversions', ['context_type' => 'inventory_item', 'context_id' => $item->id, 'package_unit' => 'crate', 'target_unit' => 'piece', 'quantity_per_package' => 30])->assertCreated();
        $record = $this->eggs($cycle, [['quantity' => 2, 'unit' => 'crate'], ['quantity' => 10, 'unit' => 'piece']])->assertCreated()->json('data'); // the entered parts are preserved, 70 is derived
        $this->assertSame('70', $record['measurement']['normalized']['quantity']);
        $this->assertSame([['quantity' => '2', 'unit' => 'crate'], ['quantity' => '10', 'unit' => 'piece']], array_map(fn ($c) => ['quantity' => (string) $c['quantity'], 'unit' => $c['unit']], $record['measurement']['entered']));
        $this->assertSame('76', $this->available('eggs')['quantity']);
        // A sale reads the same conversion.
        $this->sell($item->id, StorageLocation::sole()->id, '2', 'crate', '1800.00')->assertCreated();
        $this->assertSame('16', $this->available('eggs')['quantity']);
    }

    public function test_crate_entry_needs_a_configured_conversion_and_never_assumes_thirty(): void
    {
        $cycle = $this->cycle();

        // Pieces only: no conversion needed.
        $this->eggs($cycle, [['quantity' => 12, 'unit' => 'piece']])->assertCreated();
        // Crates, or crates mixed with pieces, are refused until a crate -> piece conversion exists; nothing is written.
        $this->eggs($cycle, [['quantity' => 2, 'unit' => 'crate']])->assertUnprocessable()->assertJsonPath('code', 'conversion_not_configured');
        $this->eggs($cycle, [['quantity' => 2, 'unit' => 'crate'], ['quantity' => 3, 'unit' => 'piece']])->assertUnprocessable()->assertJsonPath('code', 'conversion_not_configured');
        $this->assertSame(1, InventoryMovement::count());
        $this->assertSame(1, OperationalRecord::where('type', 'egg_collection')->count());

        // A farm whose crate holds 12 gets 12, not 30.
        $context = $this->crates(12);
        $record = $this->eggs($cycle, [['quantity' => 2, 'unit' => 'crate'], ['quantity' => 3, 'unit' => 'piece']], ['context' => ['type' => 'custom', 'id' => $context]])->assertCreated()->json('data');
        $this->assertSame('27', $record['measurement']['normalized']['quantity']);
        $this->assertSame('39', $this->available('eggs')['quantity']);
    }

    public function test_generic_use_is_refused_for_feed_so_feed_cannot_be_decremented_twice(): void
    {
        $batch = $this->cycle('chicken', 'Broiler Batch A');
        $feed = $this->feedItem();
        $store = $this->store('Feed store');
        $target = ['inventory_item_id' => $feed, 'storage_location_id' => $store];
        $this->stockIn($target, 'purchase', '100', 'kg')->assertCreated();
        $this->postJson('/api/v1/records', ['production_cycle_id' => $batch, 'type' => 'feed_use', 'recorded_at' => $this->at(5), 'idempotency_key' => $this->key(),
            'details' => ['feed_name' => 'Starter', 'components' => [['quantity' => '25', 'unit' => 'kg']], 'inventory' => ['item_id' => $feed, 'storage_location_id' => $store]]])->assertCreated();

        $this->stockOut($target, 'use', '25', 'kg')->assertUnprocessable()->assertJsonValidationErrors('reason')
            ->assertSee('feed_use');
        $this->assertSame('75', $this->getJson('/api/v1/inventory/items/'.$feed)->json('data.stock.quantity'));
        $this->assertSame(0, InventoryMovement::where('inventory_item_id', $feed)->where('reason', 'use')->count());

        // Other manual feed reasons stay available; the metadata offers feed_use for "Used for livestock" and not generic use.
        $this->stockOut($target, 'spoiled', '5', 'kg')->assertCreated();
        $codes = collect($this->getJson('/api/v1/master/inventory-options')->json('data.reasons.by_item_kind.feed.out'))->keyBy('code');
        $this->assertArrayNotHasKey('use', $codes->all());
        $this->assertSame('feed_use', $codes['production_use']['route']['record_type']);
        $this->assertSame('Used for livestock', $codes['production_use']['label']);

        // Generic use remains legitimate for categories that have no dedicated workflow.
        $medicine = $this->postJson('/api/v1/inventory/items', ['name' => 'Dewormer', 'category' => 'medicine', 'stock_unit' => 'kg'])->assertCreated()->json('data.id');
        $this->stockIn(['inventory_item_id' => $medicine, 'storage_location_id' => $store], 'purchase', '5', 'kg')->assertCreated();
        $this->stockOut(['inventory_item_id' => $medicine, 'storage_location_id' => $store], 'use', '1', 'kg')->assertCreated();
    }

    public function test_only_roles_with_record_create_and_inventory_use_can_record_eggs_and_milk(): void
    {
        $poultry = $this->cycle();
        $dairy = $this->cycle('cattle', 'Dairy herd');
        $allowed = [FarmRole::Manager, FarmRole::FarmWorker];
        $refused = [FarmRole::Vet, FarmRole::Finance];

        $this->signInAs($this->owner);
        $this->eggs($poultry, [['quantity' => 1, 'unit' => 'piece']])->assertCreated();
        $this->milk($dairy, '2')->assertCreated();
        foreach ($allowed as $role) {
            $this->signInAs($this->member($role));
            $this->eggs($poultry, [['quantity' => 1, 'unit' => 'piece']])->assertCreated();
            $this->milk($dairy, '2')->assertCreated();
        }
        foreach ($refused as $role) {
            $this->signInAs($this->member($role));
            $this->eggs($poultry, [['quantity' => 1, 'unit' => 'piece']])->assertForbidden();
            $this->milk($dairy, '2')->assertForbidden();
        }
        $this->assertSame('3', $this->signInAs($this->owner)->available('eggs')['quantity']);
        $this->assertSame('6', $this->available('milk')['quantity']);
    }
    // ------------------------------------------------------------------ reversals

    public function test_reversing_an_egg_record_compensates_stock_and_is_blocked_while_the_eggs_are_gone(): void
    {
        $cycle = $this->cycle();
        $record = $this->eggs($cycle, [['quantity' => 104, 'unit' => 'piece']])->assertCreated()->json('data');
        $item = $this->item('eggs');
        $store = StorageLocation::sole();

        // The stock effect belongs to the record: the ledger endpoint will not reverse it separately.
        $this->postJson('/api/v1/inventory/movements/'.$record['inventory_movement_id'].'/reverse', ['reason' => 'x', 'recorded_at' => $this->at(1), 'idempotency_key' => $this->key()])->assertStatus(409)->assertJsonPath('code', 'reverse_via_record');

        $sale = $this->sell($item->id, $store->id, '30', 'piece', '900.00')->assertCreated()->json('data');
        $reverse = fn () => $this->postJson('/api/v1/records/'.$record['id'].'/reverse', ['reason' => 'Counted twice', 'recorded_at' => $this->at(1), 'idempotency_key' => $this->key()]);
        $reverse()->assertStatus(409)->assertJsonPath('code', 'insufficient_stock'); // 74 left cannot cover -104
        $this->assertSame('74', $this->available('eggs')['quantity']);
        $this->assertNull(OperationalRecord::findOrFail($record['id'])->reversal);

        $this->postJson('/api/v1/sales/'.$sale['id'].'/cancel', ['reason' => 'Buyer left', 'recorded_at' => $this->at(2), 'idempotency_key' => $this->key()])->assertOk();
        $this->assertSame('104', $this->available('eggs')['quantity']);
        $reversal = $reverse()->assertCreated()->json('data');
        $this->assertSame('0', $this->available('eggs')['quantity']);
        $compensation = InventoryMovement::where('operational_record_id', $reversal['id'])->sole();
        $this->assertSame([$record['inventory_movement_id'], $cycle], [$compensation->reverses_movement_id, $compensation->production_cycle_id]);
        $this->assertSame('0', $this->ledgerSum($item));
    }

    // ------------------------------------------------------------------ permissions and tenancy

    public function test_permissions_follow_inventory_record_and_breeding_roles_with_no_bypass(): void
    {
        $cycle = $this->cycle();
        $this->eggs($cycle, [['quantity' => 50, 'unit' => 'piece']])->assertCreated();
        $vet = $this->member(FarmRole::Vet);
        $worker = $this->member(FarmRole::FarmWorker);
        $finance = $this->member(FarmRole::Finance);

        $this->signInAs($vet); // breeding.create but no inventory.use / record.create
        $this->eggs($cycle, [['quantity' => 1, 'unit' => 'piece']])->assertForbidden();
        $this->incubate($cycle, 10)->assertForbidden(); // cannot reach stock through the breeding endpoint
        $this->postJson('/api/v1/breeding-projects', ['production_cycle_id' => $cycle, 'workflow' => 'incubation', 'start_date' => $this->today(), 'eggs_set' => 10, 'idempotency_key' => $this->key()])->assertCreated(); // plain project still works
        $this->assertSame('50', $this->available('eggs')['quantity']);

        $this->signInAs($worker);
        $this->eggs($cycle, [['quantity' => 5, 'unit' => 'piece']])->assertCreated();
        $this->stockOut(['output' => 'eggs'], 'damaged', '1', 'piece')->assertCreated();
        $this->stockIn(['output' => 'eggs'], 'donation', '1', 'piece')->assertForbidden(); // stock-in is inventory.manage
        $this->incubate($cycle, 10)->assertCreated();

        $this->signInAs($finance);
        $this->getJson('/api/v1/inventory/output-balances')->assertOk();
        $this->stockOut(['output' => 'eggs'], 'damaged', '1', 'piece')->assertForbidden();
        $this->eggs($cycle, [['quantity' => 1, 'unit' => 'piece']])->assertForbidden();
        $this->sell($this->item('eggs')->id, StorageLocation::sole()->id, '4', 'piece', '200.00')->assertCreated(); // the sale stays its own permission
        $this->assertSame('40', $this->available('eggs')['quantity']); // 50 + 5 - 1 - 10 (incubation) - 4 (sale)
    }

    public function test_other_farms_cannot_see_or_use_this_farms_output_stock_stores_or_cycles(): void
    {
        $cycle = $this->cycle();
        $this->eggs($cycle, [['quantity' => 50, 'unit' => 'piece']])->assertCreated();
        $mine = StorageLocation::sole()->id;

        [$other, $otherFarm] = $this->otherFarm();
        $this->signInAs($other);
        $balances = $this->getJson('/api/v1/inventory/output-balances')->assertOk()->json('data');
        $this->assertFalse($balances['eggs']['exists']);
        $this->assertSame('0', $balances['eggs']['available']['quantity']);
        $this->assertSame(0, InventoryItem::where('farm_id', $otherFarm->id)->count());
        $this->stockIn(['output' => 'eggs', 'storage_location_id' => $mine], 'donation', '5', 'piece')->assertNotFound(); // my store is invisible to them
        $this->stockOut(['output' => 'eggs'], 'damaged', '1', 'piece')->assertStatus(409); // they have none: mine is not theirs
        $this->eggs($cycle, [['quantity' => 5, 'unit' => 'piece']])->assertNotFound(); // my cycle is invisible too
        $this->postJson('/api/v1/breeding-projects', ['production_cycle_id' => $cycle, 'workflow' => 'incubation', 'start_date' => $this->today(), 'eggs_set' => 5, 'consume_egg_stock' => true, 'idempotency_key' => $this->key()])->assertNotFound();

        // Their own first collection builds their own item and store, never touching mine.
        $theirCycle = $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => 'Theirs', 'operation_type_id' => OperationType::where('code', 'poultry')->firstOrFail()->id,
            'species_id' => Species::where('code', 'chicken')->firstOrFail()->id, 'production_purpose' => 'breeding', 'initial_population' => 10, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
        $this->eggs($theirCycle, [['quantity' => 7, 'unit' => 'piece']])->assertCreated();
        $this->assertSame(2, InventoryItem::where('system_key', 'output:eggs')->count());
        $this->assertSame('7', $this->available('eggs')['quantity']);
        $this->signInAs($this->owner);
        $this->assertSame('50', $this->available('eggs')['quantity']);
    }

    public function test_the_output_resolver_is_idempotent_inside_a_transaction(): void
    {
        $service = app(OutputStockService::class);
        $context = new FarmContext($this->farm, $this->membershipOf($this->owner));
        $ids = DB::transaction(fn () => [$service->resolve($context, 'eggs')->id, $service->resolve($context, 'eggs')->id]);
        $this->assertSame($ids[0], $ids[1]);
        $this->assertSame(1, InventoryItem::where('system_key', 'output:eggs')->count());
    }
}
