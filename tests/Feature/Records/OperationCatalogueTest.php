<?php

namespace Tests\Feature\Records;

use App\Enums\FarmRole;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\OperationType;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Species;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Team\TeamTestCase;

/**
 * The farm operations catalogue (GET /master/record-operations) and the Records-only "sold" OUT it advertises: one entry, one stock effect,
 * and never a sale, invoice, customer transaction or payment.
 */
class OperationCatalogueTest extends TeamTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->onPlan('farm-pro');
        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
        $this->signInAs($this->owner);
    }

    private function at(int $hoursAgo = 1): string
    {
        return now()->utc()->subHours($hoursAgo)->format('Y-m-d\TH:i:s\Z');
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    private function cycle(string $species = 'chicken'): string
    {
        $operation = Species::where('code', $species)->firstOrFail()->operationType->code;

        return $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => 'Flock '.Str::random(4), 'operation_type_id' => OperationType::where('code', $operation)->firstOrFail()->id,
            'species_id' => Species::where('code', $species)->firstOrFail()->id, 'production_purpose' => 'breeding', 'initial_population' => 100, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
    }

    private function collect(string $cycle, string $type, string $quantity, string $unit)
    {
        return $this->postJson('/api/v1/records', ['production_cycle_id' => $cycle, 'type' => $type, 'details' => ['components' => [['quantity' => $quantity, 'unit' => $unit]]], 'recorded_at' => $this->at(10), 'idempotency_key' => $this->key()]);
    }

    /** Submits an operation action exactly as the catalogue describes it (method, url, body defaults). */
    private function submit(array $action, array $body)
    {
        $this->assertSame('POST', $action['submission']['method']);

        return $this->postJson($action['submission']['url'], $action['submission']['body_defaults'] + $body + ['recorded_at' => $this->at(2), 'idempotency_key' => $this->key()]);
    }

    private function operation(string $code): array
    {
        return $this->getJson('/api/v1/master/record-operations/'.$code)->assertOk()->json('data');
    }

    private function action(array $operation, string $direction, string $code): array
    {
        foreach ($operation['actions'][$direction] as $action) {
            if ($action['code'] === $code) {
                return $action;
            }
        }
        $this->fail('Action '.$direction.'/'.$code.' not in the '.$operation['code'].' operation.');
    }

    private function available(string $kind): string
    {
        return $this->getJson('/api/v1/inventory/output-balances')->assertOk()->json('data.'.$kind.'.available.quantity');
    }

    private function noCommercialEffects(): void
    {
        $this->assertSame([0, 0, 0], [Sale::count(), Invoice::count(), Payment::count()]);
    }

    // ------------------------------------------------------------------ catalogue

    public function test_list_derives_every_display_mode_from_the_operations_actions(): void
    {
        $modes = collect($this->getJson('/api/v1/master/record-operations')->assertOk()->json('data'))->pluck('display_mode', 'code');

        $this->assertSame('in_out', $modes['eggs']);
        $this->assertSame('in_out', $modes['milk']);
        $this->assertSame('out_only', $modes['feed_use']);
        $this->assertSame('in_only', $modes['crop_harvest']);
        $this->assertSame('out_only', $modes['produce_out']);
        foreach (['water', 'weight', 'mortality', 'temperature', 'fertilizer_application', 'pesticide_application', 'planting', 'general_note'] as $normal) {
            $this->assertSame('normal', $modes[$normal], $normal);
        }
        // The record types behind eggs and milk are folded into their operation, not listed twice.
        $this->assertArrayNotHasKey('egg_collection', $modes);
        $eggs = collect($this->getJson('/api/v1/master/record-operations')->json('data'))->firstWhere('code', 'eggs');
        $this->assertSame(['livestock', 'produces_eggs', 'eggs'], [$eggs['cycle_kind'], $eggs['capability'], $eggs['inventory_kind']]);
    }

    public function test_eggs_detail_describes_in_and_out_actions_permissions_and_redirects(): void
    {
        $eggs = $this->operation('eggs');

        $this->assertSame(['in', 'out'], $eggs['directions']);
        $this->assertSame('piece', $eggs['measurement']['display_unit']);
        $this->assertSame('count', $eggs['measurement']['dimension']);

        $collected = $this->action($eggs, 'in', 'collected');
        $this->assertSame('/api/v1/records', $collected['submission']['url']);
        $this->assertSame(['type' => 'egg_collection'], $collected['submission']['body_defaults']);
        $this->assertSame('required', $collected['production_cycle']);
        $this->assertContains('details.components', $collected['fields']['required']);
        $this->assertSame(['record.create', 'inventory.use'], $collected['permissions_required']['always']);
        $this->assertSame('automatic_output', $collected['inventory_effect']['mode']);

        $sold = $this->action($eggs, 'out', 'sold');
        $this->assertSame('/api/v1/inventory/stock-out', $sold['submission']['url']);
        $this->assertSame(['output' => 'eggs', 'reason' => 'sold'], $sold['submission']['body_defaults']);
        $this->assertSame('optional', $sold['production_cycle']); // context only, never required
        $this->assertSame(['inventory.use'], $sold['permissions_required']['always']);
        $this->assertSame(['sale' => false, 'invoice' => false, 'payment' => false], $sold['commercial_effects']);
        $this->assertSame('/api/v1/inventory/movements/{id}/reverse', $sold['reversal']['url']);

        $purchased = $this->action($eggs, 'in', 'purchase');
        $this->assertSame(['inventory.manage'], $purchased['permissions_required']['always']); // stock-in is inventory.manage
        $this->assertSame('not_applicable', $purchased['production_cycle']);

        // System-owned events are pointers, never manual forms.
        $outCodes = array_column($eggs['actions']['out'], 'code');
        $this->assertNotContains('sale', $outCodes);
        $this->assertNotContains('incubation', $outCodes);
        $redirects = collect($eggs['workflow_redirects'])->keyBy('code');
        $this->assertSame('/api/v1/sales', $redirects['sale']['submission']['url']);
        $this->assertSame('/api/v1/breeding-projects', $redirects['incubation']['submission']['url']);
    }

    public function test_milk_feed_crop_and_normal_details(): void
    {
        $milk = $this->operation('milk');
        $this->assertSame('l', $milk['measurement']['display_unit']);
        $this->assertSame(['output' => 'milk', 'reason' => 'sold'], $this->action($milk, 'out', 'sold')['submission']['body_defaults']);
        $this->assertNotContains('incubation', array_column($milk['workflow_redirects'], 'code')); // no biological assumption for milk

        $feed = $this->operation('feed_use');
        $this->assertSame(['out'], $feed['directions']);
        $used = $this->action($feed, 'out', 'used_for_livestock');
        $this->assertSame('optional_link', $used['inventory_effect']['mode']);
        $this->assertSame('production_use', $used['reason']);
        $this->assertContains('inventory.use', $used['permissions_required']['when_inventory_linked']);
        $this->assertSame([], $feed['actions']['in']);

        $harvest = $this->operation('crop_harvest');
        $this->assertSame(['in'], $harvest['directions']);
        $this->assertSame('harvest', $this->action($harvest, 'in', 'harvested')['reason']);

        $produceOut = $this->operation('produce_out');
        $codes = array_column($produceOut['actions']['out'], 'code');
        $this->assertContains('sold', $codes);
        $this->assertContains('donation', $codes);
        $this->assertNotContains('sale', $codes);
        $this->assertNotContains('use', $codes);
        $this->assertSame('/api/v1/sales', collect($produceOut['workflow_redirects'])->firstWhere('code', 'sale')['submission']['url']);
        $this->assertSame(['inventory_item_id', 'storage_location_id'], array_slice($this->action($produceOut, 'out', 'sold')['fields']['required'], 0, 2));

        foreach (['water' => 'volume', 'weight' => 'weight', 'mortality' => null] as $code => $dimension) {
            $normal = $this->operation($code);
            $this->assertSame('normal', $normal['display_mode']);
            $this->assertSame([], $normal['directions']);
            $this->assertSame($dimension, $normal['measurement']['dimension'] ?? null);
            $record = $normal['actions']['record'][0];
            $this->assertNull($record['direction']);
            $this->assertSame('/api/v1/records', $record['submission']['url']);
            $this->assertSame(['record.create'], $record['permissions_required']['always']);
        }
        $this->assertContains('details.sample_size', $this->action($this->operation('weight'), 'record', 'record')['fields']['required']);

        $fertilizer = $this->action($this->operation('fertilizer_application'), 'record', 'record');
        $this->assertSame('optional_link', $fertilizer['inventory_effect']['mode']);
        $this->assertSame('out', $fertilizer['inventory_effect']['direction']);
    }

    public function test_unknown_operation_is_404_and_catalogue_needs_record_view(): void
    {
        $this->getJson('/api/v1/master/record-operations/nope')->assertNotFound();
        foreach ([FarmRole::Vet, FarmRole::Finance, FarmRole::FarmWorker] as $role) {
            $this->signInAs($this->member($role));
            $this->getJson('/api/v1/master/record-operations')->assertOk(); // every role holding record.view
        }
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/master/record-operations')->assertUnauthorized();
    }

    // ------------------------------------------------------------------ Records-only sold OUT

    public function test_eggs_sold_without_a_sale_reduces_stock_once_and_creates_no_invoice_or_payment(): void
    {
        $cycle = $this->cycle();
        $this->collect($cycle, 'egg_collection', '100', 'piece')->assertCreated();
        $sold = $this->action($this->operation('eggs'), 'out', 'sold');
        $body = ['components' => [['quantity' => '30', 'unit' => 'piece']], 'production_cycle_id' => $cycle, 'idempotency_key' => $this->key(), 'notes' => 'Roadside'];

        $movement = $this->submit($sold, $body)->assertCreated()->json('data');
        $this->assertSame('sold', $movement['reason']);
        $this->assertSame('Sold (not invoiced)', $movement['reason_label']);
        $this->assertSame([null, null, $cycle], [$movement['sale_id'], $movement['operational_record_id'], $movement['production_cycle_id']]);
        $this->assertSame('manual', $movement['source']['type']);
        $this->assertSame('70', $this->available('eggs'));

        // A retry of the same request is the same event; a changed payload under the same key is refused.
        $this->submit($sold, $body)->assertCreated();
        $this->assertSame('70', $this->available('eggs'));
        $this->submit($sold, ['components' => [['quantity' => '31', 'unit' => 'piece']]] + $body)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame(1, InventoryMovement::where('reason', 'sold')->count());
        $this->noCommercialEffects();

        // Auditable and reversible through the manual ledger reversal.
        $this->postJson('/api/v1/inventory/movements/'.$movement['id'].'/reverse', ['reason' => 'Wrong count', 'recorded_at' => $this->at(1), 'idempotency_key' => $this->key()])->assertCreated();
        $this->assertSame('100', $this->available('eggs'));
    }

    public function test_milk_sold_in_records_and_the_cycle_is_optional(): void
    {
        $cycle = $this->cycle('cattle');
        $this->collect($cycle, 'milk', '50', 'l')->assertCreated();
        $sold = $this->action($this->operation('milk'), 'out', 'sold');

        $this->submit($sold, ['components' => [['quantity' => '20', 'unit' => 'l']]])->assertCreated()->assertJsonPath('data.production_cycle_id', null);
        $this->assertSame('30', $this->available('milk'));
        $this->noCommercialEffects();
    }

    public function test_out_never_takes_stock_below_zero_and_unknown_cycles_are_not_accepted(): void
    {
        $cycle = $this->cycle();
        $this->collect($cycle, 'egg_collection', '10', 'piece')->assertCreated();
        $sold = $this->action($this->operation('eggs'), 'out', 'sold');

        $this->submit($sold, ['components' => [['quantity' => '11', 'unit' => 'piece']]])->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->assertSame('10', $this->available('eggs'));
        $this->submit($sold, ['components' => [['quantity' => '1', 'unit' => 'piece']], 'production_cycle_id' => (string) Str::uuid()])->assertNotFound();
        $this->assertSame(0, InventoryMovement::where('reason', 'sold')->count());

        [$other] = $this->otherFarm();
        $this->signInAs($other);
        $foreign = $this->cycle();
        $this->signInAs($this->owner);
        $this->submit($sold, ['components' => [['quantity' => '1', 'unit' => 'piece']], 'production_cycle_id' => $foreign])->assertNotFound(); // another farm's cycle
    }

    public function test_sale_stays_system_only_and_sold_is_refused_for_non_produce_stock(): void
    {
        $this->collect($this->cycle(), 'egg_collection', '10', 'piece')->assertCreated();
        $this->postJson('/api/v1/inventory/stock-out', ['output' => 'eggs', 'reason' => 'sale', 'components' => [['quantity' => '1', 'unit' => 'piece']], 'recorded_at' => $this->at(2), 'idempotency_key' => $this->key()])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');

        $feed = $this->postJson('/api/v1/inventory/items', ['name' => 'Starter', 'category' => 'feed', 'stock_unit' => 'kg'])->assertCreated()->json('data.id');
        $store = $this->postJson('/api/v1/storage-locations', ['name' => 'Store', 'type' => 'store'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/inventory/stock-in', ['inventory_item_id' => $feed, 'storage_location_id' => $store, 'reason' => 'purchase', 'components' => [['quantity' => '10', 'unit' => 'kg']], 'recorded_at' => $this->at(5), 'idempotency_key' => $this->key()])->assertCreated();
        $this->postJson('/api/v1/inventory/stock-out', ['inventory_item_id' => $feed, 'storage_location_id' => $store, 'reason' => 'sold', 'components' => [['quantity' => '1', 'unit' => 'kg']], 'recorded_at' => $this->at(2), 'idempotency_key' => $this->key()])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_produce_out_sold_uses_the_existing_item_endpoint(): void
    {
        $item = $this->postJson('/api/v1/inventory/items', ['name' => 'Maize grain', 'category' => 'produce', 'stock_unit' => 'kg'])->assertCreated()->json('data.id');
        $store = $this->postJson('/api/v1/storage-locations', ['name' => 'Silo', 'type' => 'store'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/inventory/stock-in', ['inventory_item_id' => $item, 'storage_location_id' => $store, 'reason' => 'opening_balance', 'components' => [['quantity' => '100', 'unit' => 'kg']], 'recorded_at' => $this->at(5), 'idempotency_key' => $this->key()])->assertCreated();
        $sold = $this->action($this->operation('produce_out'), 'out', 'sold');

        $this->submit($sold, ['inventory_item_id' => $item, 'storage_location_id' => $store, 'components' => [['quantity' => '40', 'unit' => 'kg']]])->assertCreated();
        $this->submit($sold, ['inventory_item_id' => $item, 'storage_location_id' => $store, 'components' => [['quantity' => '61', 'unit' => 'kg']]])->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->assertEqualsWithDelta(60000, (float) InventoryMovement::where('inventory_item_id', $item)->sum('quantity_delta'), 0.001); // canonical grams
        $this->noCommercialEffects();
        $this->assertSame(1, InventoryItem::where('name', 'Maize grain')->count());
    }

    public function test_out_requires_inventory_use_and_collection_record_create(): void
    {
        $cycle = $this->cycle();
        $this->collect($cycle, 'egg_collection', '10', 'piece')->assertCreated();
        $sold = $this->action($this->operation('eggs'), 'out', 'sold');

        $this->signInAs($this->member(FarmRole::FarmWorker));
        $this->submit($sold, ['components' => [['quantity' => '1', 'unit' => 'piece']]])->assertCreated();
        $this->signInAs($this->member(FarmRole::Vet));
        $this->submit($sold, ['components' => [['quantity' => '1', 'unit' => 'piece']]])->assertForbidden();
        $this->signInAs($this->member(FarmRole::Finance));
        $this->submit($sold, ['components' => [['quantity' => '1', 'unit' => 'piece']]])->assertForbidden();
        $this->assertSame(1, InventoryMovement::where('reason', 'sold')->count());
    }
}
