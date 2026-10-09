<?php

namespace Tests\Feature\Inventory;

use App\Enums\FarmRole;
use App\Events\Inventory\InventoryMovementRecorded;
use App\Models\FeedFormula;
use App\Models\InventoryItem;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\OperationalRecord;
use App\Models\OperationType;
use App\Models\PackageConversion;
use App\Models\Species;
use App\Services\Inventory\FeedFormulaService;
use App\Services\Inventory\InventoryService;
use App\Services\Measurement\MeasurementConverter;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\Team\TeamTestCase;

class InventoryTest extends TeamTestCase
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

    private function store(string $name = 'Main Store'): string
    {
        return $this->postJson('/api/v1/storage-locations', ['name' => $name, 'type' => 'store'])->assertCreated()->json('data.id');
    }

    private function item(array $extra = []): string
    {
        return $this->postJson('/api/v1/inventory/items', array_replace(['name' => 'Layer Mash '.Str::random(5), 'category' => 'fertilizer_agrochemical', 'stock_unit' => 'kg'], $extra))->assertCreated()->json('data.id');
    }

    private function inPayload(string $item, string $loc, string $qty = '100', string $unit = 'kg', array $extra = []): array
    {
        return array_replace(['inventory_item_id' => $item, 'storage_location_id' => $loc, 'reason' => 'purchase', 'components' => [['quantity' => $qty, 'unit' => $unit]],
            'recorded_at' => $this->at(10), 'idempotency_key' => (string) Str::uuid()], $extra);
    }

    private function receive(string $item, string $loc, string $qty = '100', string $unit = 'kg', array $extra = []): array
    {
        return $this->postJson('/api/v1/inventory/stock-in', $this->inPayload($item, $loc, $qty, $unit, $extra))->assertCreated()->json('data');
    }

    private function outPayload(string $item, string $loc, string $qty, string $unit = 'kg', array $extra = []): array
    {
        return array_replace(['inventory_item_id' => $item, 'storage_location_id' => $loc, 'reason' => 'use', 'components' => [['quantity' => $qty, 'unit' => $unit]],
            'recorded_at' => $this->at(5), 'idempotency_key' => (string) Str::uuid()], $extra);
    }

    private function issue(string $item, string $loc, string $qty, string $unit = 'kg', array $extra = [])
    {
        return $this->postJson('/api/v1/inventory/stock-out', $this->outPayload($item, $loc, $qty, $unit, $extra));
    }

    private function stock(string $item): string
    {
        $shown = $this->getJson('/api/v1/inventory/items/'.$item)->assertOk()->json('data.stock.quantity');
        $sum = InventoryMovement::where('inventory_item_id', $item)->sum('quantity_delta');
        $unitFactor = InventoryItem::findOrFail($item)->stockUnit->code === 'kg' ? 1000 : 1;
        $this->assertEquals((float) $shown * $unitFactor, (float) $sum, 'Displayed stock must equal the SUM of ledger movements.');

        return $shown;
    }

    private function bag(string $item, string $perBag = '50', string $target = 'kg'): void
    {
        $this->postJson('/api/v1/settings/package-conversions', ['context_type' => 'inventory_item', 'context_id' => $item, 'package_unit' => 'bag', 'target_unit' => $target, 'quantity_per_package' => $perBag])->assertCreated();
    }

    private function expiryDate(int $days): string
    {
        return now('Africa/Lagos')->addDays($days)->toDateString();
    }

    private function cycle(): string
    {
        return $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => 'Layer flock', 'operation_type_id' => OperationType::where('code', 'poultry')->firstOrFail()->id,
            'species_id' => Species::where('code', 'chicken')->firstOrFail()->id, 'production_purpose' => 'breeding', 'initial_population' => 100, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
    }

    private function feedRecord(string $cycle, string $item, string $loc, string $qty = '10', string $unit = 'kg', array $inventory = [], array $extra = [])
    {
        return $this->postJson('/api/v1/records', array_replace_recursive(['production_cycle_id' => $cycle, 'type' => 'feed_use', 'recorded_at' => $this->at(2), 'idempotency_key' => (string) Str::uuid(),
            'details' => ['feed_name' => 'Layer mash', 'components' => [['quantity' => $qty, 'unit' => $unit]], 'inventory' => $inventory + ['item_id' => $item, 'storage_location_id' => $loc]]], $extra));
    }

    // ------------------------------------------------------------------ items & ledger

    public function test_stock_is_derived_from_movements_and_has_no_writable_balance(): void
    {
        $this->assertFalse(Schema::hasColumn('inventory_items', 'quantity'));
        $this->assertFalse(Schema::hasColumn('inventory_items', 'quantity_on_hand'));
        $item = $this->item();
        $loc = $this->store();
        $this->assertSame('0', $this->stock($item));
        $this->receive($item, $loc, '100');
        $this->assertSame('100', $this->stock($item));
        $this->issue($item, $loc, '30.5')->assertCreated();
        $this->assertSame('69.5', $this->stock($item));
        $this->assertSame(2, InventoryMovement::where('inventory_item_id', $item)->count());
        foreach (['quantity', 'quantity_on_hand', 'stock', 'balance'] as $field) {
            $this->patchJson('/api/v1/inventory/items/'.$item, [$field => 500])->assertStatus(422)->assertJsonValidationErrors($field);
            $this->postJson('/api/v1/inventory/items', ['name' => 'X'.$field, 'category' => 'feed', 'stock_unit' => 'kg', $field => 5])->assertStatus(422);
        }
        $this->assertSame('69.5', $this->stock($item));
    }

    public function test_zero_balance_is_valid_and_exact_decimals_do_not_drift(): void
    {
        $item = $this->item();
        $loc = $this->store();
        $this->receive($item, $loc, '0.1');
        $this->receive($item, $loc, '0.2');
        $this->assertSame('0.3', $this->stock($item));
        $this->issue($item, $loc, '0.3')->assertCreated();
        $this->assertSame('0', $this->stock($item));
        $this->issue($item, $loc, '0.000001')->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
    }

    public function test_insufficient_stock_is_rejected_atomically(): void
    {
        $item = $this->item();
        $loc = $this->store();
        $this->receive($item, $loc, '10');
        $this->issue($item, $loc, '10.000001')->assertStatus(409)->assertJsonPath('code', 'insufficient_stock')->assertJsonPath('details.available.quantity', '10');
        $this->assertSame(1, InventoryMovement::count());
        $this->assertSame('10', $this->stock($item));
    }

    public function test_stock_is_per_storage_location_and_never_negative_in_dated_history(): void
    {
        $item = $this->item();
        $a = $this->store('Store A');
        $b = $this->store('Store B');
        $this->receive($item, $a, '10');
        $this->issue($item, $b, '1')->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        // A back-dated issue before the stock arrived would be negative at that moment, although the CURRENT balance covers it.
        $this->issue($item, $a, '4', extra: ['recorded_at' => $this->at(20)])->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->assertSame(1, InventoryMovement::count());
    }

    public function test_item_listing_filters_pagination_and_low_stock(): void
    {
        $loc = $this->store();
        $feed = $this->item(['name' => 'Maize bran', 'low_stock_threshold' => ['quantity' => '50', 'unit' => 'kg']]);
        $this->item(['name' => 'Cleaning bucket', 'category' => 'general_supply', 'stock_unit' => 'piece']);
        $this->receive($feed, $loc, '20');
        $this->getJson('/api/v1/inventory/items?category=general_supply')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/inventory/items?search=BRAN')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_low_stock', true)->assertJsonPath('data.0.low_stock_threshold.quantity', '50');
        $this->getJson('/api/v1/inventory/items?low_stock=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $feed);
        $this->getJson('/api/v1/inventory/items?low_stock=0')->assertOk()->assertJsonCount(1, 'data');
        $this->receive($feed, $loc, '40');
        $this->getJson('/api/v1/inventory/items?low_stock=1')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/inventory/items?per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/v1/inventory/items?per_page=500')->assertStatus(422);
        $this->getJson('/api/v1/inventory/items?category=nonsense')->assertStatus(422);
    }

    public function test_duplicate_item_names_conflict_and_basis_locks_after_first_movement(): void
    {
        $item = $this->item(['name' => 'Starter']);
        $this->postJson('/api/v1/inventory/items', ['name' => '  starter ', 'category' => 'feed', 'stock_unit' => 'kg'])->assertStatus(409)->assertJsonPath('code', 'inventory_item_exists');
        $this->patchJson('/api/v1/inventory/items/'.$item, ['stock_unit' => 'g', 'tracks_lots' => true, 'name' => 'Starter Plus'])->assertOk()->assertJsonPath('data.stock_unit', 'g')->assertJsonPath('data.tracks_lots', true);
        $this->receive($item, $this->store(), '5', 'g', ['lot' => ['code' => 'L1']]);
        $this->patchJson('/api/v1/inventory/items/'.$item, ['stock_unit' => 'kg'])->assertStatus(409)->assertJsonPath('code', 'item_has_movements');
        $this->patchJson('/api/v1/inventory/items/'.$item, ['category' => 'medicine'])->assertStatus(409)->assertJsonPath('code', 'item_has_movements');
        $this->patchJson('/api/v1/inventory/items/'.$item, ['name' => 'Renamed', 'stock_unit' => 'g'])->assertOk()->assertJsonPath('data.name', 'Renamed');
        $this->postJson('/api/v1/inventory/items', ['name' => 'Bad', 'category' => 'feed', 'stock_unit' => 'bag'])->assertStatus(422);
        $this->postJson('/api/v1/inventory/items', ['name' => 'Bad2', 'category' => 'feed', 'stock_unit' => 'celsius'])->assertStatus(422);
        $this->postJson('/api/v1/inventory/items', ['name' => 'Bad3', 'category' => 'feed', 'stock_unit' => 'kg', 'tracks_expiry' => true])->assertStatus(422)->assertJsonValidationErrors('tracks_expiry');
    }

    // ------------------------------------------------------------------ measurements

    public function test_package_conversion_is_contextual_to_the_item_and_normalised_exactly(): void
    {
        $item = $this->item();
        $other = $this->item(['name' => 'Other feed']);
        $loc = $this->store();
        $parts = ['components' => [['quantity' => '12', 'unit' => 'bag'], ['quantity' => '18', 'unit' => 'kg']]];
        // Missing contextual conversion: never assume bag = 50 kg.
        $this->postJson('/api/v1/inventory/stock-in', $this->inPayload($item, $loc, extra: $parts))->assertStatus(422)->assertJsonPath('code', 'conversion_not_configured');
        $this->assertSame(0, InventoryMovement::count());
        $this->bag($item, '50');
        $this->bag($other, '25');
        $movement = $this->postJson('/api/v1/inventory/stock-in', $this->inPayload($item, $loc, extra: $parts))->assertCreated()->json('data');
        $this->assertSame('618', $this->stock($item));
        $this->assertSame('618000', $movement['quantity_delta']['quantity']);
        $this->assertSame('g', $movement['quantity_delta']['unit']);
        $this->assertSame('618', $movement['quantity_delta_display']['quantity']);
        $this->assertSame(['12', '18'], [$movement['measurement']['entered'][0]['quantity'], $movement['measurement']['entered'][1]['quantity']]);
        $this->assertSame('inventory_item', $movement['measurement']['snapshot']['packages'][0]['context']['type']);
        $this->assertSame($item, $movement['measurement']['snapshot']['packages'][0]['context']['id']);
        $this->receive($other, $loc, '2', 'bag');
        $this->assertSame('50', $this->stock($other));
        // The conversion can change later; the stored snapshot still replays to the original numbers.
        $conversionId = PackageConversion::where('context_id', $item)->value('id');
        $this->patchJson('/api/v1/settings/package-conversions/'.$conversionId, ['quantity_per_package' => 40])->assertOk();
        $stored = InventoryMovement::findOrFail($movement['id']);
        $this->assertSame('618000', app(MeasurementConverter::class)->replay($stored->measurement['snapshot'])->normalized->value);
        $this->assertSame('618', $this->stock($item));
    }

    public function test_dimension_and_family_compatibility_are_enforced(): void
    {
        $item = $this->item();
        $count = $this->item(['name' => 'Eggs tray stock', 'category' => 'general_supply', 'stock_unit' => 'egg']);
        $loc = $this->store();
        $this->postJson('/api/v1/inventory/stock-in', $this->inPayload($item, $loc, '5', 'l'))->assertStatus(422)->assertJsonPath('code', 'unit_dimension_mismatch');
        $this->postJson('/api/v1/inventory/stock-in', $this->inPayload($item, $loc, '5', 'piece'))->assertStatus(422)->assertJsonPath('code', 'unit_dimension_mismatch');
        $this->postJson('/api/v1/inventory/stock-in', $this->inPayload($count, $loc, '5', 'piece'))->assertStatus(422);
        $this->postJson('/api/v1/inventory/stock-in', $this->inPayload($count, $loc, '2.5', 'egg'))->assertStatus(422);
        $this->postJson('/api/v1/inventory/stock-in', $this->inPayload($item, $loc, '0', 'kg'))->assertStatus(422)->assertJsonValidationErrors('components');
        $this->postJson('/api/v1/inventory/stock-in', $this->inPayload($item, $loc, '-3', 'kg'))->assertStatus(422);
        $this->assertSame(0, InventoryMovement::count());
        $this->receive($count, $loc, '30', 'egg');
        $this->assertSame('30', $this->getJson('/api/v1/inventory/items/'.$count)->json('data.stock.quantity'));
        $this->receive($item, $loc, '2', 'tonne');
        $this->assertSame('2000', $this->stock($item));
    }

    // ------------------------------------------------------------------ retries

    public function test_idempotent_retry_never_double_adds_or_double_deducts(): void
    {
        $item = $this->item();
        $loc = $this->store();
        $in = $this->inPayload($item, $loc, '50');
        $first = $this->postJson('/api/v1/inventory/stock-in', $in)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/inventory/stock-in', $in)->assertCreated()->assertJsonPath('data.id', $first);
        $this->assertSame('50', $this->stock($item));
        $out = $this->outPayload($item, $loc, '20');
        $this->postJson('/api/v1/inventory/stock-out', $out)->assertCreated();
        $this->postJson('/api/v1/inventory/stock-out', $out)->assertCreated();
        $this->assertSame('30', $this->stock($item));
        $this->assertSame(2, InventoryMovement::count());
        $changed = $out;
        $changed['components'][0]['quantity'] = '21';
        $this->postJson('/api/v1/inventory/stock-out', $changed)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->postJson('/api/v1/inventory/stock-in', ['idempotency_key' => $out['idempotency_key']] + $in)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame('30', $this->stock($item));
    }

    public function test_a_failed_operation_does_not_consume_its_idempotency_key(): void
    {
        $item = $this->item();
        $loc = $this->store();
        $out = $this->outPayload($item, $loc, '5');
        $this->postJson('/api/v1/inventory/stock-out', $out)->assertStatus(409);
        $this->receive($item, $loc, '10');
        $this->postJson('/api/v1/inventory/stock-out', $out)->assertCreated();
        $this->assertSame('5', $this->stock($item));
    }

    // ------------------------------------------------------------------ adjustments

    public function test_count_adjustment_is_a_signed_movement_not_an_overwrite(): void
    {
        $item = $this->item();
        $loc = $this->store();
        $this->receive($item, $loc, '98');
        $payload = ['inventory_item_id' => $item, 'storage_location_id' => $loc, 'expected' => [['quantity' => '98', 'unit' => 'kg']], 'counted' => [['quantity' => '95', 'unit' => 'kg']],
            'reason' => 'Monthly count', 'recorded_at' => $this->at(3), 'idempotency_key' => (string) Str::uuid()];
        $m = $this->postJson('/api/v1/inventory/adjustments', $payload)->assertCreated()->json('data');
        $this->assertSame('adjustment', $m['type']);
        $this->assertSame('-3000', $m['quantity_delta']['quantity']);
        $this->assertSame('-3', $m['quantity_delta_display']['quantity']);
        $this->assertSame('Monthly count', $m['justification']);
        $this->assertSame('95', $this->stock($item));
        $this->assertSame(2, InventoryMovement::count());
        $this->postJson('/api/v1/inventory/adjustments', $payload)->assertCreated()->assertJsonPath('data.id', $m['id']);
        $this->assertSame(2, InventoryMovement::count());
        // The expectation is now stale (95, not 98).
        $stale = ['idempotency_key' => (string) Str::uuid()] + $payload;
        $this->postJson('/api/v1/inventory/adjustments', $stale)->assertStatus(409)->assertJsonPath('code', 'stock_changed')->assertJsonPath('details.current.quantity', '95');
        // Counting zero and counting up are both allowed; an unchanged count is an audited verification.
        $up = ['expected' => [['quantity' => '95', 'unit' => 'kg']], 'counted' => [['quantity' => '96.5', 'unit' => 'kg']], 'idempotency_key' => (string) Str::uuid()] + $payload;
        $this->postJson('/api/v1/inventory/adjustments', $up)->assertCreated()->assertJsonPath('data.quantity_delta_display.quantity', '1.5');
        $same = ['expected' => [['quantity' => '96.5', 'unit' => 'kg']], 'counted' => [['quantity' => '96.5', 'unit' => 'kg']], 'idempotency_key' => (string) Str::uuid()] + $payload;
        $this->postJson('/api/v1/inventory/adjustments', $same)->assertCreated()->assertJsonPath('data.quantity_delta.quantity', '0');
        $zero = ['expected' => [['quantity' => '96.5', 'unit' => 'kg']], 'counted' => [['quantity' => '0', 'unit' => 'kg']], 'idempotency_key' => (string) Str::uuid()] + $payload;
        $this->postJson('/api/v1/inventory/adjustments', $zero)->assertCreated();
        $this->assertSame('0', $this->stock($item));
        $early = ['expected' => [['quantity' => '0', 'unit' => 'kg']], 'counted' => [['quantity' => '1', 'unit' => 'kg']], 'recorded_at' => $this->at(30), 'idempotency_key' => (string) Str::uuid()] + $payload;
        $this->postJson('/api/v1/inventory/adjustments', $early)->assertStatus(422)->assertJsonValidationErrors('recorded_at');
    }

    // ------------------------------------------------------------------ transfers

    public function test_transfer_is_one_linked_operation_and_a_retry_does_not_duplicate_either_side(): void
    {
        $item = $this->item();
        $a = $this->store('Store A');
        $b = $this->store('Store B');
        $this->receive($item, $a, '100');
        $payload = ['inventory_item_id' => $item, 'from_storage_location_id' => $a, 'to_storage_location_id' => $b, 'components' => [['quantity' => '40', 'unit' => 'kg']],
            'recorded_at' => $this->at(4), 'idempotency_key' => (string) Str::uuid()];
        $res = $this->postJson('/api/v1/inventory/transfers', $payload)->assertCreated();
        $group = $res->json('data.transfer_group_id');
        $this->assertSame(['transfer_out', 'transfer_in'], array_column($res->json('data.movements'), 'type'));
        $this->assertSame(['-40000', '40000'], array_map(fn ($m) => $m['quantity_delta']['quantity'], $res->json('data.movements')));
        $this->postJson('/api/v1/inventory/transfers', $payload)->assertCreated()->assertJsonPath('data.transfer_group_id', $group);
        $this->assertSame(3, InventoryMovement::count());
        $this->assertSame('100', $this->stock($item));
        $balances = collect($this->getJson('/api/v1/inventory/items/'.$item)->json('data.balances'))->pluck('quantity', 'storage_location_id')->all();
        $this->assertSame(['60', '40'], [$balances[$a], $balances[$b]]);
        $this->assertSame('40', $this->getJson('/api/v1/inventory/movements?storage_location_id='.$b)->json('data.0.quantity_delta_display.quantity'));
        // Not enough at the source: nothing is written, not even the destination leg.
        $big = ['components' => [['quantity' => '61', 'unit' => 'kg']], 'idempotency_key' => (string) Str::uuid()] + $payload;
        $this->postJson('/api/v1/inventory/transfers', $big)->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->assertSame(3, InventoryMovement::count());
        $this->postJson('/api/v1/inventory/transfers', ['to_storage_location_id' => $a, 'idempotency_key' => (string) Str::uuid()] + $payload)->assertStatus(422);
    }

    public function test_reversing_a_transfer_reverses_both_legs_together(): void
    {
        $item = $this->item();
        $a = $this->store('Store A');
        $b = $this->store('Store B');
        $this->receive($item, $a, '100');
        $out = $this->postJson('/api/v1/inventory/transfers', ['inventory_item_id' => $item, 'from_storage_location_id' => $a, 'to_storage_location_id' => $b,
            'components' => [['quantity' => '40', 'unit' => 'kg']], 'recorded_at' => $this->at(4), 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data.movements.0.id');
        $rev = ['reason' => 'Wrong store', 'recorded_at' => $this->at(2), 'idempotency_key' => (string) Str::uuid()];
        $rows = $this->postJson('/api/v1/inventory/movements/'.$out.'/reverse', $rev)->assertCreated()->json('data');
        $this->assertCount(2, $rows);
        $this->assertSame(['reversal', 'reversal'], array_column($rows, 'type'));
        $this->assertSame(5, InventoryMovement::count());
        $balances = collect($this->getJson('/api/v1/inventory/items/'.$item)->json('data.balances'))->pluck('quantity', 'storage_location_id')->all();
        $this->assertSame(['100'], array_values($balances));
        $this->postJson('/api/v1/inventory/movements/'.$out.'/reverse', $rev)->assertCreated();
        $this->assertSame(5, InventoryMovement::count());
        $this->postJson('/api/v1/inventory/movements/'.$out.'/reverse', ['idempotency_key' => (string) Str::uuid()] + $rev)->assertStatus(409)->assertJsonPath('code', 'movement_already_reversed');
    }

    public function test_reversal_rules(): void
    {
        $item = $this->item();
        $loc = $this->store();
        $in = $this->receive($item, $loc, '10');
        $rev = fn () => ['reason' => 'Mistake', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()];
        $this->issue($item, $loc, '4')->assertCreated();
        $this->postJson('/api/v1/inventory/movements/'.$in['id'].'/reverse', $rev())->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->issue($item, $loc, '6')->assertCreated();
        $outId = InventoryMovement::where('reason', 'use')->orderBy('id')->value('id');
        $reversal = $this->postJson('/api/v1/inventory/movements/'.$outId.'/reverse', $rev())->assertCreated()->json('data.0');
        $this->assertSame('4', $this->stock($item));
        $this->assertSame($outId, $reversal['reverses_movement_id']);
        $this->getJson('/api/v1/inventory/movements/'.$outId)->assertOk()->assertJsonPath('data.reversed_by_movement_id', $reversal['id']);
        $this->postJson('/api/v1/inventory/movements/'.$reversal['id'].'/reverse', $rev())->assertStatus(409)->assertJsonPath('code', 'movement_already_reversed');
        $this->postJson('/api/v1/inventory/movements/'.$outId.'/reverse', $rev())->assertStatus(409)->assertJsonPath('code', 'movement_already_reversed');
        $this->postJson('/api/v1/inventory/movements/'.$in['id'].'/reverse', ['recorded_at' => $this->at(30)] + $rev())->assertStatus(422);
        $this->assertSame(1, InventoryMovement::where('reverses_movement_id', $outId)->count());
    }

    // ------------------------------------------------------------------ lots & expiry

    public function test_lot_tracking_rules(): void
    {
        $plain = $this->item(['name' => 'Plain']);
        $lots = $this->item(['name' => 'Lotted', 'tracks_lots' => true]);
        $loc = $this->store();
        $this->postJson('/api/v1/inventory/stock-in', $this->inPayload($plain, $loc, extra: ['lot' => ['code' => 'A']]))->assertStatus(422)->assertJsonValidationErrors('lot_id');
        $this->postJson('/api/v1/inventory/stock-in', $this->inPayload($lots, $loc))->assertStatus(422)->assertJsonValidationErrors('lot_id');
        $this->postJson('/api/v1/inventory/stock-in', $this->inPayload($lots, $loc, extra: ['lot' => ['code' => 'A', 'expires_on' => $this->expiryDate(10)]]))->assertStatus(422)->assertJsonValidationErrors('lot.expires_on');
        $m = $this->receive($lots, $loc, '10', extra: ['lot' => ['code' => ' lot-A ']]);
        $lotId = $m['inventory_lot_id'];
        $this->assertSame('lot-A', $m['lot']['code']);
        // Same code (case-insensitive) re-uses the lot rather than creating a duplicate.
        $this->assertSame($lotId, $this->receive($lots, $loc, '5', extra: ['lot' => ['code' => 'LOT-a']])['inventory_lot_id']);
        $this->assertSame(1, InventoryLot::where('inventory_item_id', $lots)->count());
        $this->postJson('/api/v1/inventory/stock-in', $this->inPayload($lots, $loc, extra: ['lot_id' => $lotId, 'lot' => ['code' => 'B']]))->assertStatus(422);
        // Issue must name the lot (no automatic FIFO/FEFO) and respects per-lot balances.
        $this->issue($lots, $loc, '1')->assertStatus(422)->assertJsonValidationErrors('lot_id');
        $this->issue($lots, $loc, '16', extra: ['lot_id' => $lotId])->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->issue($lots, $loc, '15', extra: ['lot_id' => $lotId])->assertCreated();
        $this->assertSame('0', $this->stock($lots));
        $this->issue($plain, $loc, '1', extra: ['lot_id' => $lotId])->assertStatus(422);
    }

    public function test_lot_ownership_and_immutability(): void
    {
        $one = $this->item(['name' => 'One', 'tracks_lots' => true]);
        $two = $this->item(['name' => 'Two', 'tracks_lots' => true]);
        $loc = $this->store();
        $lot = $this->receive($one, $loc, '5', extra: ['lot' => ['code' => 'X']])['inventory_lot_id'];
        // A lot belongs to exactly one item.
        $this->postJson('/api/v1/inventory/stock-in', $this->inPayload($two, $loc, extra: ['lot_id' => $lot]))->assertNotFound();
        $this->issue($two, $loc, '1', extra: ['lot_id' => $lot])->assertNotFound();
        $this->expectException(LogicException::class);
        InventoryLot::findOrFail($lot)->update(['code' => 'Y']);
    }

    public function test_expiry_behaviour(): void
    {
        $item = $this->item(['tracks_lots' => true, 'tracks_expiry' => true]);
        $loc = $this->store();
        $this->postJson('/api/v1/inventory/stock-in', $this->inPayload($item, $loc, extra: ['lot' => ['code' => 'NOEXP']]))->assertStatus(422)->assertJsonValidationErrors('lot.expires_on');
        // Receiving stock that was already expired on the received date is refused.
        $this->postJson('/api/v1/inventory/stock-in', $this->inPayload($item, $loc, extra: ['lot' => ['code' => 'OLD', 'expires_on' => '2020-01-01']]))->assertStatus(409)->assertJsonPath('code', 'lot_expired');
        // Back-dated receipt whose lot expired later is fine; the lot then expires in real time.
        $soon = $this->receive($item, $loc, '10', extra: ['lot' => ['code' => 'SOON', 'expires_on' => $this->expiryDate(3)]])['inventory_lot_id'];
        $far = $this->receive($item, $loc, '10', extra: ['lot' => ['code' => 'FAR', 'expires_on' => $this->expiryDate(300)]])['inventory_lot_id'];
        $this->postJson('/api/v1/inventory/stock-in', $this->inPayload($item, $loc, extra: ['lot' => ['code' => 'soon', 'expires_on' => $this->expiryDate(4)]]))->assertStatus(422);
        $this->getJson('/api/v1/inventory/lots?expiring_within_days=7')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $soon);
        $this->getJson('/api/v1/inventory/lots?expired=1')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/inventory/lots?expired=0&inventory_item_id='.$item)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $soon);
        $this->travel(5)->days();
        $this->getJson('/api/v1/inventory/lots?expired=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_expired', true);
        // Expired stock cannot be used, but can be written off and transferred out of the way.
        $now = now()->utc()->subMinutes(5)->format('Y-m-d\TH:i:s\Z');
        $this->issue($item, $loc, '1', extra: ['lot_id' => $soon, 'recorded_at' => $now])->assertStatus(409)->assertJsonPath('code', 'lot_expired');
        $this->issue($item, $loc, '10', extra: ['lot_id' => $soon, 'reason' => 'expired', 'recorded_at' => $now])->assertCreated();
        $this->issue($item, $loc, '1', extra: ['lot_id' => $far, 'recorded_at' => $now])->assertCreated();
        $this->getJson('/api/v1/inventory/lots?inventory_item_id='.$item)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/inventory/lots?inventory_item_id='.$item.'&include_empty=1')->assertOk()->assertJsonCount(2, 'data');
    }

    // ------------------------------------------------------------------ places, activity, isolation

    public function test_storage_location_isolation_and_inactive_semantics(): void
    {
        $item = $this->item();
        $loc = $this->store();
        $this->receive($item, $loc, '10');
        [$otherOwner, $otherFarm] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $foreignLoc = $this->store('Their store');
        $foreignItem = $this->item(['name' => 'Their feed']);
        $this->signInAs($this->owner);
        $this->postJson('/api/v1/inventory/stock-in', $this->inPayload($item, $foreignLoc))->assertNotFound();
        $this->postJson('/api/v1/inventory/stock-in', $this->inPayload($foreignItem, $loc))->assertNotFound();
        $this->issue($item, $foreignLoc, '1')->assertNotFound();
        $this->postJson('/api/v1/inventory/transfers', ['inventory_item_id' => $item, 'from_storage_location_id' => $loc, 'to_storage_location_id' => $foreignLoc,
            'components' => [['quantity' => '1', 'unit' => 'kg']], 'recorded_at' => $this->at(2), 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->assertSame(1, InventoryMovement::count());
        // Inactive stores cannot receive but stock can still leave them.
        $this->patchJson('/api/v1/storage-locations/'.$loc, ['is_active' => false])->assertOk();
        $this->postJson('/api/v1/inventory/stock-in', $this->inPayload($item, $loc))->assertStatus(409)->assertJsonPath('code', 'location_inactive');
        $this->issue($item, $loc, '4')->assertCreated();
        $active = $this->store('Active');
        $this->postJson('/api/v1/inventory/transfers', ['inventory_item_id' => $item, 'from_storage_location_id' => $loc, 'to_storage_location_id' => $active,
            'components' => [['quantity' => '6', 'unit' => 'kg']], 'recorded_at' => $this->at(2), 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $this->assertSame('6', $this->stock($item));
    }

    public function test_farm_isolation_of_items_lots_and_movements(): void
    {
        $item = $this->item(['tracks_lots' => true]);
        $loc = $this->store();
        $m = $this->receive($item, $loc, '10', extra: ['lot' => ['code' => 'L']]);
        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $this->getJson('/api/v1/inventory/items')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/inventory/items/'.$item)->assertNotFound();
        $this->getJson('/api/v1/inventory/items/'.$item.'/movements')->assertNotFound();
        $this->getJson('/api/v1/inventory/movements/'.$m['id'])->assertNotFound();
        $this->getJson('/api/v1/inventory/movements')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/inventory/lots')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/v1/inventory/items/'.$item, ['name' => 'Hijack'])->assertNotFound();
        $this->postJson('/api/v1/inventory/movements/'.$m['id'].'/reverse', ['reason' => 'x', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $mine = $this->store('Mine');
        $this->postJson('/api/v1/inventory/stock-in', $this->inPayload($item, $mine, extra: ['farm_id' => $this->farm->id]))->assertStatus(422);
        $this->postJson('/api/v1/inventory/stock-in', $this->inPayload($item, $mine))->assertNotFound();
        $this->postJson('/api/v1/settings/package-conversions', ['context_type' => 'inventory_item', 'context_id' => $item, 'package_unit' => 'bag', 'target_unit' => 'kg', 'quantity_per_package' => 50])->assertStatus(422)->assertJsonValidationErrors('context_id');
        $this->assertSame(1, InventoryMovement::count());
    }

    public function test_item_deactivation_requires_zero_stock_and_blocks_new_stock_but_not_reversals(): void
    {
        $item = $this->item();
        $loc = $this->store();
        $in = $this->receive($item, $loc, '5');
        $this->patchJson('/api/v1/inventory/items/'.$item, ['is_active' => false])->assertStatus(409)->assertJsonPath('code', 'item_has_stock');
        $this->issue($item, $loc, '5')->assertCreated();
        $this->patchJson('/api/v1/inventory/items/'.$item, ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson('/api/v1/inventory/items')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/inventory/items?include_inactive=1')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/inventory/stock-in', $this->inPayload($item, $loc))->assertStatus(409)->assertJsonPath('code', 'item_inactive');
        $this->issue($item, $loc, '1')->assertStatus(409)->assertJsonPath('code', 'item_inactive');
        $this->getJson('/api/v1/inventory/items/'.$item.'/movements')->assertOk()->assertJsonCount(2, 'data');
        $outId = InventoryMovement::where('reason', 'use')->value('id');
        $this->postJson('/api/v1/inventory/movements/'.$outId.'/reverse', ['reason' => 'Undo', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $this->assertSame(1, InventoryMovement::where('inventory_item_id', $item)->where('type', 'reversal')->count());
        $this->assertNotNull($in);
    }

    // ------------------------------------------------------------------ permissions

    public function test_permissions_follow_the_matrix(): void
    {
        $item = $this->item();
        $loc = $this->store();
        $this->receive($item, $loc, '50');
        $worker = $this->member(FarmRole::FarmWorker);
        $finance = $this->member(FarmRole::Finance);
        $manager = $this->member(FarmRole::Manager);
        $writes = fn () => [
            ['post', '/api/v1/inventory/items', ['name' => 'W'.Str::random(4), 'category' => 'feed', 'stock_unit' => 'kg']],
            ['post', '/api/v1/inventory/stock-in', $this->inPayload($item, $loc)],
            ['post', '/api/v1/inventory/transfers', ['inventory_item_id' => $item, 'from_storage_location_id' => $loc, 'to_storage_location_id' => $loc]],
            ['post', '/api/v1/inventory/adjustments', []],
            ['post', '/api/v1/feed-formulas', []],
            ['patch', '/api/v1/inventory/items/'.$item, ['name' => 'Z']],
        ];
        $this->signInAs($worker);
        $this->getJson('/api/v1/inventory/items')->assertOk();
        $this->getJson('/api/v1/inventory/movements')->assertOk();
        $this->getJson('/api/v1/feed-formulas')->assertOk();
        $this->issue($item, $loc, '1')->assertCreated();
        foreach ($writes() as [$method, $url, $body]) {
            $this->{$method.'Json'}($url, $body)->assertForbidden();
        }
        $this->signInAs($finance);
        $this->getJson('/api/v1/inventory/items')->assertOk();
        $this->getJson('/api/v1/master/inventory-options')->assertOk();
        $this->issue($item, $loc, '1')->assertForbidden();
        foreach ($writes() as [$method, $url, $body]) {
            $this->{$method.'Json'}($url, $body)->assertForbidden();
        }
        $this->signInAs($manager);
        $this->receive($item, $loc, '1');
        $this->issue($item, $loc, '1')->assertCreated();
        $this->postJson('/api/v1/inventory/adjustments', ['inventory_item_id' => $item, 'storage_location_id' => $loc, 'expected' => [['quantity' => '49', 'unit' => 'kg']], 'counted' => [['quantity' => '49', 'unit' => 'kg']],
            'reason' => 'ok', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/inventory/items')->assertUnauthorized();
    }

    // ------------------------------------------------------------------ Phase 8 integration

    public function test_feed_use_record_consumes_stock_once_and_retries_do_not_double_deduct(): void
    {
        $cycle = $this->cycle();
        $item = $this->item(['category' => 'feed']);
        $loc = $this->store();
        $this->receive($item, $loc, '100');
        $payload = ['production_cycle_id' => $cycle, 'type' => 'feed_use', 'recorded_at' => $this->at(2), 'idempotency_key' => (string) Str::uuid(),
            'details' => ['feed_name' => 'Layer mash', 'components' => [['quantity' => '25', 'unit' => 'kg']], 'inventory' => ['item_id' => $item, 'storage_location_id' => $loc]]];
        $record = $this->postJson('/api/v1/records', $payload)->assertCreated()->json('data');
        $this->assertSame('75', $this->stock($item));
        $movement = InventoryMovement::where('operational_record_id', $record['id'])->sole();
        $this->assertSame($record['id'], $movement->operational_record_id);
        $this->assertSame('production_use', $movement->reason);
        $this->assertSame($movement->id, $record['inventory_movement_id']);
        $this->assertSame($record['recorded_at'], $movement->recorded_at->toISOString());
        $this->assertEquals($record['measurement'], $movement->measurement);
        $this->postJson('/api/v1/records', $payload)->assertCreated()->assertJsonPath('data.id', $record['id']);
        $this->assertSame('75', $this->stock($item));
        $this->assertSame(1, InventoryMovement::where('type', 'stock_out')->count());
        $this->getJson('/api/v1/inventory/movements?operational_record_id='.$record['id'])->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/records/'.$record['id'])->assertOk()->assertJsonPath('data.inventory_movement_id', $movement->id);
        // A direct reversal of a record-driven movement is refused; reverse the record instead.
        $this->postJson('/api/v1/inventory/movements/'.$movement->id.'/reverse', ['reason' => 'x', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertStatus(409)->assertJsonPath('code', 'reverse_via_record');
        // Feed use without a link stays a pure record: no stock effect.
        $this->postJson('/api/v1/records', ['details' => ['feed_name' => 'Hand feed', 'components' => [['quantity' => '1', 'unit' => 'kg']]], 'idempotency_key' => (string) Str::uuid()] + $payload)->assertCreated()->assertJsonPath('data.inventory_movement_id', null);
        $this->assertSame('75', $this->stock($item));
    }

    public function test_reversing_a_feed_record_compensates_stock_and_correction_deducts_again(): void
    {
        $cycle = $this->cycle();
        $item = $this->item(['category' => 'feed']);
        $loc = $this->store();
        $this->receive($item, $loc, '100');
        $record = $this->feedRecord($cycle, $item, $loc, '30')->assertCreated()->json('data');
        $this->assertSame('70', $this->stock($item));
        $reversal = $this->postJson('/api/v1/records/'.$record['id'].'/reverse', ['reason' => 'Wrong bin', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->assertSame('100', $this->stock($item));
        $comp = InventoryMovement::where('operational_record_id', $reversal['id'])->sole();
        $this->assertSame('reversal', $comp->type->value);
        $this->assertSame(InventoryMovement::where('operational_record_id', $record['id'])->value('id'), $comp->reverses_movement_id);
        $this->assertSame(3, InventoryMovement::count());
        $this->feedRecord($cycle, $item, $loc, '20', extra: ['corrects_record_id' => $record['id']])->assertCreated();
        $this->assertSame('80', $this->stock($item));
        $this->postJson('/api/v1/records/'.$record['id'].'/reverse', ['reason' => 'Again', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertStatus(409);
        $this->assertSame('80', $this->stock($item));
    }

    public function test_feed_record_failures_roll_back_the_record_and_leave_stock_untouched(): void
    {
        $cycle = $this->cycle();
        $item = $this->item(['category' => 'feed']);
        $loc = $this->store();
        $this->receive($item, $loc, '10');
        $this->feedRecord($cycle, $item, $loc, '11')->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->assertSame(0, OperationalRecord::count());
        $this->assertSame('10', $this->stock($item));
        $medicine = $this->item(['name' => 'Tonic', 'category' => 'medicine']);
        $this->feedRecord($cycle, $medicine, $loc, '1')->assertStatus(422)->assertJsonValidationErrors('details.inventory.item_id');
        $volume = $this->item(['name' => 'Liquid feed', 'category' => 'feed', 'stock_unit' => 'l']);
        $this->feedRecord($cycle, $volume, $loc, '1')->assertStatus(422)->assertJsonValidationErrors('details.inventory.item_id');
        $this->feedRecord($cycle, $item, $loc, '1', extra: ['details' => ['context' => ['type' => 'crop_type', 'id' => (string) Str::uuid()]]])->assertStatus(422);
        $this->feedRecord($cycle, (string) Str::uuid(), $loc)->assertNotFound();
        $this->feedRecord($cycle, $item, (string) Str::uuid())->assertNotFound();
        $this->postJson('/api/v1/records', ['details' => ['feed_name' => 'x', 'components' => [['quantity' => '1', 'unit' => 'kg']], 'inventory' => ['item_id' => $item]], 'production_cycle_id' => $cycle, 'type' => 'feed_use',
            'recorded_at' => $this->at(2), 'idempotency_key' => (string) Str::uuid()])->assertStatus(422);
        $this->assertSame(0, OperationalRecord::count());
        $this->assertSame(1, InventoryMovement::count());
        $this->postJson('/api/v1/production-cycles/'.$cycle.'/close', ['end_date' => now()->toDateString(), 'reason' => 'Done'])->assertOk();
        $this->feedRecord($cycle, $item, $loc, '1')->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
        $this->assertSame('10', $this->stock($item));
    }

    public function test_feed_record_uses_the_items_own_package_conversion_and_lot_rules(): void
    {
        $cycle = $this->cycle();
        $item = $this->item(['category' => 'feed', 'tracks_lots' => true, 'tracks_expiry' => true]);
        $loc = $this->store();
        $lot = $this->receive($item, $loc, '200', extra: ['lot' => ['code' => 'F1', 'expires_on' => $this->expiryDate(60)]])['inventory_lot_id'];
        $this->feedRecord($cycle, $item, $loc, '1')->assertStatus(422)->assertJsonValidationErrors('details.inventory.lot_id');
        $this->feedRecord($cycle, $item, $loc, '2', 'bag', ['lot_id' => $lot])->assertStatus(422)->assertJsonPath('code', 'conversion_not_configured');
        $this->bag($item, '25');
        $record = $this->feedRecord($cycle, $item, $loc, '2', 'bag', ['lot_id' => $lot])->assertCreated()->json('data');
        $this->assertSame('50000', $record['measurement']['normalized']['quantity']);
        $this->assertSame('150', $this->stock($item));
        $this->assertSame($lot, InventoryMovement::where('operational_record_id', $record['id'])->value('inventory_lot_id'));
        $this->assertSame('150', collect($this->getJson('/api/v1/inventory/items/'.$item)->json('data.balances'))->firstWhere('inventory_lot_id', $lot)['quantity']);
    }

    public function test_worker_can_link_feed_use_but_only_with_inventory_use(): void
    {
        $cycle = $this->cycle();
        $item = $this->item(['category' => 'feed']);
        $loc = $this->store();
        $this->receive($item, $loc, '10');
        $this->signInAs($this->member(FarmRole::FarmWorker));
        $this->feedRecord($cycle, $item, $loc, '2')->assertCreated();
        $this->assertSame('8', $this->stock($item));
    }

    // ------------------------------------------------------------------ feed formulas

    public function test_feed_formula_is_a_recipe_and_never_stock(): void
    {
        $item = $this->item(['name' => 'Maize']);
        $loc = $this->store();
        $this->receive($item, $loc, '10');
        $payload = ['name' => 'Grower 18%', 'species_id' => Species::where('code', 'chicken')->value('id'), 'items' => [
            ['ingredient_name' => 'Maize', 'inclusion_percent' => '60.5', 'inventory_item_id' => $item],
            ['ingredient_name' => 'Soya', 'inclusion_percent' => '39.5'],
        ]];
        $before = InventoryMovement::count();
        $formula = $this->postJson('/api/v1/feed-formulas', $payload)->assertCreated()->assertJsonPath('data.version', 1)->json('data');
        $this->assertSame($before, InventoryMovement::count());
        $this->assertSame('10', $this->stock($item));
        $this->assertSame('60.5', $formula['items'][0]['inclusion_percent']);
        $this->postJson('/api/v1/feed-formulas', $payload)->assertStatus(409)->assertJsonPath('code', 'feed_formula_exists');
        $this->postJson('/api/v1/feed-formulas', ['name' => 'Bad sum', 'items' => [['ingredient_name' => 'A', 'inclusion_percent' => '50']]])->assertStatus(422)->assertJsonValidationErrors('items');
        $this->postJson('/api/v1/feed-formulas', ['name' => 'Dup', 'items' => [['ingredient_name' => 'A', 'inclusion_percent' => '50'], ['ingredient_name' => 'a', 'inclusion_percent' => '50']]])->assertStatus(422);
        $this->postJson('/api/v1/feed-formulas', ['name' => 'Neg', 'items' => [['ingredient_name' => 'A', 'inclusion_percent' => '120'], ['ingredient_name' => 'B', 'inclusion_percent' => '-20']]])->assertStatus(422);
        $this->postJson('/api/v1/feed-formulas', ['name' => 'Foreign', 'items' => [['ingredient_name' => 'A', 'inclusion_percent' => '100', 'inventory_item_id' => (string) Str::uuid()]]])->assertStatus(422);
        $updated = $this->patchJson('/api/v1/feed-formulas/'.$formula['id'], ['items' => [['ingredient_name' => 'Maize', 'inclusion_percent' => '100']]])->assertOk()->assertJsonPath('data.version', 2)->json('data');
        $this->assertCount(1, $updated['items']);
        $this->patchJson('/api/v1/feed-formulas/'.$formula['id'], ['is_active' => false, 'quantity' => 4])->assertStatus(422);
        $this->patchJson('/api/v1/feed-formulas/'.$formula['id'], ['is_active' => false])->assertOk()->assertJsonPath('data.version', 2);
        $this->getJson('/api/v1/feed-formulas')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/feed-formulas?include_inactive=1&search=GROWER')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($before, InventoryMovement::count());
        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $this->getJson('/api/v1/feed-formulas/'.$formula['id'])->assertNotFound();
        $this->patchJson('/api/v1/feed-formulas/'.$formula['id'], ['name' => 'Mine'])->assertNotFound();
        $this->postJson('/api/v1/feed-formulas', ['name' => 'Their', 'items' => [['ingredient_name' => 'A', 'inclusion_percent' => '100', 'inventory_item_id' => $item]]])->assertStatus(422);
        $this->assertSame(1, FeedFormula::count());
    }

    // ------------------------------------------------------------------ history, listing, service guards

    public function test_movements_are_append_only_and_fully_traceable(): void
    {
        Event::fake([InventoryMovementRecorded::class]);
        $item = $this->item();
        $loc = $this->store();
        $m = $this->receive($item, $loc, '10', extra: ['notes' => 'Delivery note 7']);
        Event::assertDispatchedTimes(InventoryMovementRecorded::class, 1);
        $this->assertSame($this->owner->id, $m['created_by']);
        $this->assertSame('purchase', $m['reason']);
        $this->assertSame('Delivery note 7', $m['notes']);
        $this->assertNotSame($m['recorded_at'], $m['created_at']);
        $this->assertSame($loc, $m['storage_location_id']);
        $row = InventoryMovement::findOrFail($m['id']);
        try {
            $row->update(['quantity_delta' => '500']);
            $this->fail('Movements must be append-only.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
        $this->expectException(LogicException::class);
        $row->delete();
    }

    public function test_movement_filters_pagination_and_validation(): void
    {
        $item = $this->item();
        $loc = $this->store();
        $this->receive($item, $loc, '10', extra: ['recorded_at' => $this->at(48)]);
        $this->issue($item, $loc, '1', extra: ['recorded_at' => $this->at(2)])->assertCreated();
        $this->issue($item, $loc, '1', extra: ['recorded_at' => $this->at(1), 'reason' => 'wasted'])->assertCreated();
        $this->getJson('/api/v1/inventory/movements?type=stock_out')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.reason', 'wasted');
        $this->getJson('/api/v1/inventory/items/'.$item.'/movements?per_page=2&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/inventory/movements?recorded_from='.now('Africa/Lagos')->subHours(2)->toDateString())->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/inventory/movements?type=bogus')->assertStatus(422);
        $this->getJson('/api/v1/inventory/movements?inventory_item_id=not-a-uuid')->assertStatus(422);
        $this->postJson('/api/v1/inventory/stock-in', $this->inPayload($item, $loc, extra: ['recorded_at' => now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z')]))->assertStatus(422)->assertJsonValidationErrors('recorded_at');
        $this->postJson('/api/v1/inventory/stock-in', $this->inPayload($item, $loc, extra: ['recorded_at' => '2026-01-01']))->assertStatus(422);
        $this->postJson('/api/v1/inventory/stock-in', $this->inPayload($item, $loc, extra: ['reason' => 'sale']))->assertStatus(422);
        $this->getJson('/api/v1/master/inventory-options')->assertOk()->assertJsonPath('data.movement_types.0', 'stock_in');
    }

    public function test_direct_service_calls_enforce_authorization_and_validation(): void
    {
        $item = $this->item();
        $loc = $this->store();
        $worker = $this->member(FarmRole::FarmWorker);
        $ctx = new FarmContext($this->farm, $this->membershipOf($worker));
        try {
            app(InventoryService::class)->stockIn($ctx, $this->inPayload($item, $loc));
            $this->fail('Worker must not receive stock.');
        } catch (ApiHttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        try {
            app(FeedFormulaService::class)->create($ctx, ['name' => 'x', 'items' => [['ingredient_name' => 'a', 'inclusion_percent' => 100]]]);
            $this->fail('Worker must not manage formulas.');
        } catch (ApiHttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(0, InventoryMovement::count());
    }
}
