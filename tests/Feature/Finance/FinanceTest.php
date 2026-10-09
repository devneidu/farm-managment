<?php

namespace Tests\Feature\Finance;

use App\Enums\FarmRole;
use App\Enums\Permission;
use App\Events\Finance\FinanceTransactionRecorded;
use App\Models\Contact;
use App\Models\FinanceTransaction;
use App\Models\InventoryMovement;
use App\Models\OperationType;
use App\Models\Purchase;
use App\Models\Species;
use App\Support\Finance\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\Team\TeamTestCase;

class FinanceTest extends TeamTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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

    private function cycle(): string
    {
        return $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => 'Layer flock '.Str::random(4), 'operation_type_id' => OperationType::where('code', 'poultry')->firstOrFail()->id,
            'species_id' => Species::where('code', 'chicken')->firstOrFail()->id, 'production_purpose' => 'breeding', 'initial_population' => 100, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
    }

    private function store(): string
    {
        return $this->postJson('/api/v1/storage-locations', ['name' => 'Store '.Str::random(5), 'type' => 'store'])->assertCreated()->json('data.id');
    }

    private function item(string $category = 'feed', string $unit = 'kg', array $extra = []): string
    {
        return $this->postJson('/api/v1/inventory/items', array_replace(['name' => ucfirst($category).' '.Str::random(6), 'category' => $category, 'stock_unit' => $unit], $extra))->assertCreated()->json('data.id');
    }

    private function stock(string $item): string
    {
        return $this->getJson('/api/v1/inventory/items/'.$item)->assertOk()->json('data.stock.quantity');
    }

    private function supplier(string $name = 'Agro Supplies Ltd', array $roles = ['supplier']): string
    {
        return $this->postJson('/api/v1/contacts', ['name' => $name, 'kind' => 'business', 'roles' => $roles])->assertCreated()->json('data.id');
    }

    private array $categories = [];

    private function category(string $code, string $direction = 'expense'): string
    {
        if (isset($this->categories[$direction.$code])) {
            return $this->categories[$direction.$code];
        }
        foreach ($this->getJson('/api/v1/finance/categories?direction='.$direction)->assertOk()->json('data') as $row) {
            if ($row['code'] === $code) {
                return $this->categories[$direction.$code] = $row['id'];
            }
        }
        $this->fail('Category '.$code.' missing');
    }

    private function stockLine(string $item, string $loc, string $qty, string $unit, string $amount, array $extra = []): array
    {
        return array_replace(['kind' => 'stock', 'inventory_item_id' => $item, 'storage_location_id' => $loc, 'components' => [['quantity' => $qty, 'unit' => $unit]], 'amount' => $amount], $extra);
    }

    private function purchase(array $items, array $extra = [])
    {
        return $this->postJson('/api/v1/purchases', array_replace(['recorded_at' => $this->at(2), 'idempotency_key' => $this->key(), 'items' => $items], $extra));
    }

    private function expense(array $extra = [])
    {
        return $this->postJson('/api/v1/expenses', array_replace(['finance_category_id' => $this->category('transport'), 'amount' => '2500.00', 'occurred_on' => now('Africa/Lagos')->toDateString(),
            'idempotency_key' => $this->key()], $extra));
    }

    private function income(array $extra = [])
    {
        return $this->postJson('/api/v1/income', array_replace(['finance_category_id' => $this->category('egg_sales', 'income'), 'amount' => '9000', 'occurred_on' => now('Africa/Lagos')->toDateString(),
            'idempotency_key' => $this->key()], $extra));
    }

    private function mortality(string $cycle): string
    {
        return $this->postJson('/api/v1/records', ['production_cycle_id' => $cycle, 'type' => 'mortality', 'recorded_at' => $this->at(3), 'idempotency_key' => $this->key(),
            'details' => ['quantity' => 2, 'cause' => 'Disease']])->assertCreated()->json('data.id');
    }

    private function summary(string $query = ''): array
    {
        return $this->getJson('/api/v1/finance/summary'.$query)->assertOk()->json('data');
    }

    // ------------------------------------------------------------------ contacts

    public function test_one_contact_can_be_supplier_and_customer_without_duplicates(): void
    {
        $id = $this->supplier('Mama Ngozi Farms', ['supplier', 'customer']);
        $this->assertSame(['supplier', 'customer'], $this->getJson('/api/v1/contacts/'.$id)->assertOk()->json('data.roles'));
        $this->postJson('/api/v1/contacts', ['name' => '  mama   NGOZI farms ', 'roles' => ['customer']])->assertStatus(409)->assertJsonPath('code', 'contact_exists');

        $other = $this->postJson('/api/v1/contacts', ['name' => 'Only A Buyer', 'roles' => ['customer']])->assertCreated()->json('data.id');
        $this->assertSame([$id], array_column($this->getJson('/api/v1/contacts?role=supplier')->json('data'), 'id'));
        $this->assertEqualsCanonicalizing([$id, $other], array_column($this->getJson('/api/v1/contacts?role=customer')->json('data'), 'id'));

        // Adding the missing role to the existing contact is the way to avoid a duplicate.
        $this->patchJson('/api/v1/contacts/'.$other, ['roles' => ['customer', 'supplier']])->assertOk()->assertJsonPath('data.roles', ['supplier', 'customer']);
        $this->postJson('/api/v1/contacts', ['name' => 'No roles', 'roles' => []])->assertStatus(422);
        $this->postJson('/api/v1/contacts', ['name' => 'Bad role', 'roles' => ['alien']])->assertStatus(422);
        $this->postJson('/api/v1/contacts', ['name' => 'Forged', 'roles' => ['supplier'], 'farm_id' => (string) Str::uuid()])->assertStatus(422);
    }

    public function test_contacts_are_isolated_between_farms(): void
    {
        $id = $this->supplier();
        [$otherOwner, $otherFarm] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $this->getJson('/api/v1/contacts/'.$id)->assertNotFound();
        $this->patchJson('/api/v1/contacts/'.$id, ['name' => 'Hijack'])->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/contacts')->json('data'));
        // The same name is free on another farm.
        $this->postJson('/api/v1/contacts', ['name' => 'Agro Supplies Ltd', 'roles' => ['supplier']])->assertCreated();
        $this->assertSame(1, Contact::where('farm_id', $otherFarm->id)->count());

        // A foreign contact cannot be put on a purchase or a transaction.
        $this->purchase([['kind' => 'non_stock', 'description' => 'Haulage', 'amount' => '500']], ['contact_id' => $id])->assertNotFound();
        $this->expense(['contact_id' => $id])->assertNotFound();
    }

    public function test_inactive_and_non_supplier_contacts_are_refused_and_used_suppliers_keep_their_role(): void
    {
        $buyer = $this->postJson('/api/v1/contacts', ['name' => 'Buyer Bola', 'roles' => ['customer']])->assertCreated()->json('data.id');
        $this->purchase([['kind' => 'non_stock', 'description' => 'Haulage', 'amount' => '500']], ['contact_id' => $buyer])->assertStatus(422)->assertJsonValidationErrors('contact_id');

        $supplier = $this->supplier('Feed Mill');
        $this->purchase([['kind' => 'non_stock', 'description' => 'Haulage', 'amount' => '500']], ['contact_id' => $supplier])->assertCreated();
        $this->patchJson('/api/v1/contacts/'.$supplier, ['roles' => ['customer']])->assertStatus(409)->assertJsonPath('code', 'contact_in_use');

        $this->patchJson('/api/v1/contacts/'.$supplier, ['is_active' => false])->assertOk();
        $this->purchase([['kind' => 'non_stock', 'description' => 'Haulage', 'amount' => '500']], ['contact_id' => $supplier])->assertStatus(409)->assertJsonPath('code', 'contact_inactive');
        $this->assertSame([], array_column(array_filter($this->getJson('/api/v1/contacts')->json('data'), fn ($c) => $c['id'] === $supplier), 'id'));
        $this->assertCount(2, $this->getJson('/api/v1/contacts?include_inactive=1')->json('data'));
    }

    // ------------------------------------------------------------------ purchasing

    public function test_stocked_purchase_books_stock_ins_and_one_expense_with_exact_money(): void
    {
        Event::fake([FinanceTransactionRecorded::class]);
        $maize = $this->item('feed', 'kg');
        $drug = $this->item('medicine', 'ml');
        $loc = $this->store();
        $supplier = $this->supplier();
        $at = $this->at(5);

        $res = $this->purchase([
            $this->stockLine($maize, $loc, '250', 'kg', '0.10'),
            $this->stockLine($drug, $loc, '500', 'ml', '0.20'),
            ['kind' => 'non_stock', 'description' => 'Delivery truck', 'amount' => '1250.45'],
        ], ['contact_id' => $supplier, 'recorded_at' => $at, 'supplier_reference' => 'INV-7781', 'notes' => 'Market day'])->assertCreated();

        $p = $res->json('data');
        $this->assertSame('1250.75', $p['total_amount']); // 0.10 + 0.20 + 1250.45 with no float drift
        $this->assertSame('NGN', $p['currency']);
        $this->assertMatchesRegularExpression('/^PUR-\d{4}-00001$/', $p['reference']);
        $this->assertSame('active', $p['status']);
        $this->assertSame('250', $this->stock($maize));
        $this->assertSame('500', $this->stock($drug));
        $this->assertSame(2, InventoryMovement::where('purchase_id', $p['id'])->count()); // the non-stock line created none

        $this->assertSame('stock', $p['items'][0]['kind']);
        $this->assertNotNull($p['items'][0]['inventory_movement_id']);
        $this->assertSame('250', $p['items'][0]['quantity']['quantity']);
        $this->assertNull($p['items'][2]['inventory_movement_id']);
        $this->assertNull($p['items'][2]['quantity']);

        $movement = $this->getJson('/api/v1/inventory/movements/'.$p['items'][0]['inventory_movement_id'])->assertOk()->json('data');
        $this->assertSame($p['id'], $movement['purchase_id']);
        $this->assertSame($p['items'][0]['id'], $movement['purchase_item_id']);
        $this->assertSame('purchase', $movement['reason']);
        $this->assertSame(CarbonImmutable::parse($at)->toISOString(), $movement['recorded_at']); // event time, not created_at
        $this->assertSame(2, count($this->getJson('/api/v1/inventory/movements?purchase_id='.$p['id'])->json('data')));

        // Exactly one expense for the whole purchase, source-linked, default category differs per line -> general supplies.
        $this->assertSame(1, FinanceTransaction::where('source_type', 'purchase')->where('source_id', $p['id'])->count());
        $tx = $this->getJson('/api/v1/finance/transactions/'.$p['finance_transaction_id'])->assertOk()->json('data');
        $this->assertSame('expense', $tx['direction']);
        $this->assertSame('1250.75', $tx['amount']);
        $this->assertSame(['type' => 'purchase', 'id' => $p['id']], $tx['source']);
        $this->assertSame('general_supplies', $tx['category']['code']);
        $this->assertSame($supplier, $tx['contact_id']);
        $this->assertSame($p['id'], $this->getJson('/api/v1/purchases/'.$p['id'])->json('data.id'));
        Event::assertDispatchedTimes(FinanceTransactionRecorded::class, 1);
    }

    public function test_single_category_purchases_default_to_the_matching_expense_category_and_can_be_overridden(): void
    {
        $maize = $this->item('feed', 'kg');
        $loc = $this->store();
        $p = $this->purchase([$this->stockLine($maize, $loc, '10', 'kg', '4000')])->assertCreated()->json('data');
        $this->assertSame('feed', $p['finance_category']['code']);
        $this->assertSame('feed', $this->getJson('/api/v1/finance/transactions/'.$p['finance_transaction_id'])->json('data.category.code'));

        $custom = $this->purchase([['kind' => 'non_stock', 'description' => 'Fuel', 'amount' => '900']], ['finance_category_id' => $this->category('utilities')])->assertCreated()->json('data');
        $this->assertSame('utilities', $custom['finance_category']['code']);
        $this->purchase([['kind' => 'non_stock', 'description' => 'Fuel', 'amount' => '900']], ['finance_category_id' => $this->category('egg_sales', 'income')])->assertStatus(422)->assertJsonValidationErrors('finance_category_id');
    }

    public function test_package_conversion_lots_and_expiry_flow_through_the_purchase(): void
    {
        $feed = $this->item('feed', 'kg');
        $loc = $this->store();
        // Without the item's own conversion a bag is not a weight.
        $this->purchase([$this->stockLine($feed, $loc, '2', 'bag', '9000')])->assertStatus(422);
        $this->postJson('/api/v1/settings/package-conversions', ['context_type' => 'inventory_item', 'context_id' => $feed, 'package_unit' => 'bag', 'target_unit' => 'kg', 'quantity_per_package' => '25'])->assertCreated();
        $res = $this->purchase([$this->stockLine($feed, $loc, '2', 'bag', '9000', ['components' => [['quantity' => '2', 'unit' => 'bag'], ['quantity' => '5', 'unit' => 'kg']]])])->assertCreated();
        $this->assertSame('55', $this->stock($feed)); // 2 x 25 kg + 5 kg
        $this->assertSame('55000', $res->json('data.items.0.quantity.normalized.quantity')); // canonical weight unit is grams
        $this->assertNotEmpty($res->json('data.items.0.measurement'));

        $vac = $this->item('medicine', 'ml', ['tracks_lots' => true, 'tracks_expiry' => true]);
        $this->purchase([$this->stockLine($vac, $loc, '100', 'ml', '3000')])->assertStatus(422)->assertJsonValidationErrors('items.0.lot_id');
        $this->purchase([$this->stockLine($vac, $loc, '100', 'ml', '3000', ['lot' => ['code' => 'L-1']])])->assertStatus(422);
        $future = now()->addYear()->toDateString();
        $ok = $this->purchase([$this->stockLine($vac, $loc, '100', 'ml', '3000', ['lot' => ['code' => 'L-1', 'expires_on' => $future]])])->assertCreated()->json('data');
        $this->assertSame('L-1', $ok['items'][0]['lot']['code']);
        $this->assertSame($future, $ok['items'][0]['lot']['expires_on']);
        $this->purchase([$this->stockLine($vac, $loc, '100', 'ml', '3000', ['lot' => ['code' => 'L-OLD', 'expires_on' => now('Africa/Lagos')->subHours(2)->subDay()->toDateString()]])])->assertStatus(409)->assertJsonPath('code', 'lot_expired');
        // Reusing the lot code adds to the same lot; a different expiry for it is refused.
        $this->purchase([$this->stockLine($vac, $loc, '50', 'ml', '1500', ['lot' => ['code' => 'L-1', 'expires_on' => $future]])])->assertCreated();
        $this->assertSame('150', $this->stock($vac));
        $this->purchase([$this->stockLine($vac, $loc, '50', 'ml', '1500', ['lot' => ['code' => 'L-1', 'expires_on' => now()->addYears(2)->toDateString()]])])->assertStatus(422);
    }

    public function test_line_shape_storage_location_and_foreign_ids_are_validated_and_roll_everything_back(): void
    {
        $feed = $this->item('feed', 'kg');
        $loc = $this->store();
        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $foreignLoc = $this->postJson('/api/v1/storage-locations', ['name' => 'Their store', 'type' => 'store'])->assertCreated()->json('data.id');
        $foreignItem = $this->postJson('/api/v1/inventory/items', ['name' => 'Their feed', 'category' => 'feed', 'stock_unit' => 'kg'])->assertCreated()->json('data.id');
        $this->signInAs($this->owner);

        $good = $this->stockLine($feed, $loc, '10', 'kg', '1000');
        foreach ([
            [$good, $this->stockLine($feed, $foreignLoc, '5', 'kg', '500')],
            [$good, $this->stockLine($foreignItem, $loc, '5', 'kg', '500')],
        ] as $items) {
            $this->purchase($items)->assertNotFound();
        }
        // All-or-nothing: the valid first line left no purchase, stock, movement or expense behind.
        $this->assertSame(0, Purchase::count());
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, FinanceTransaction::count());
        $this->assertSame('0', $this->stock($feed));

        $this->purchase([['kind' => 'non_stock', 'description' => 'Haulage', 'amount' => '500', 'inventory_item_id' => $feed]])->assertStatus(422);
        $this->purchase([['kind' => 'non_stock', 'amount' => '500']])->assertStatus(422);
        $this->purchase([['kind' => 'stock', 'amount' => '500']])->assertStatus(422);
        $this->purchase([$this->stockLine($feed, $loc, '0', 'kg', '500')])->assertStatus(422);
        $this->purchase([])->assertStatus(422);

        // A deactivated item cannot be received into.
        $this->patchJson('/api/v1/inventory/items/'.$feed, ['is_active' => false])->assertOk();
        $this->purchase([$good])->assertStatus(409)->assertJsonPath('code', 'item_inactive');
    }

    public function test_money_is_decimal_safe_and_strictly_validated(): void
    {
        foreach (['0', '0.00', '-5', '10.005', '1e3', 'abc', '', '12345678901234567', true] as $bad) {
            $this->purchase([['kind' => 'non_stock', 'description' => 'x', 'amount' => $bad]])->assertStatus(422);
            $this->expense(['amount' => $bad])->assertStatus(422);
        }
        $p = $this->purchase([
            ['kind' => 'non_stock', 'description' => 'a', 'amount' => 0.1], // JSON number accepted via its exact text
            ['kind' => 'non_stock', 'description' => 'b', 'amount' => '0.2'],
            ['kind' => 'non_stock', 'description' => 'c', 'amount' => 3],
        ])->assertCreated()->json('data');
        $this->assertSame('3.30', $p['total_amount']);
        $this->assertSame(['0.10', '0.20', '3.00'], array_column($p['items'], 'amount'));
        $this->assertSame('3.30', Money::sum(['0.10', '0.20', '3.00']));
        $this->assertSame('9999999999999999.99', Money::parse('9999999999999999.99'));
        $this->assertNull(Money::parse(0.125));
        // Currency and totals are never client-controlled.
        $this->purchase([['kind' => 'non_stock', 'description' => 'x', 'amount' => '5']], ['total_amount' => '1', 'currency' => 'USD', 'status' => 'cancelled'])->assertStatus(422);
        $this->expense(['currency' => 'USD'])->assertStatus(422);
    }

    public function test_purchase_without_expense_can_be_booked_once_for_exactly_its_total(): void
    {
        $p = $this->purchase([['kind' => 'non_stock', 'description' => 'Fencing', 'amount' => '7000.50']], ['record_expense' => false])->assertCreated()->json('data');
        $this->assertNull($p['finance_transaction_id']);
        $this->assertSame(0, FinanceTransaction::count());

        $source = ['type' => 'purchase', 'id' => $p['id']];
        $this->expense(['source' => $source, 'amount' => '7000.00'])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->income(['source' => $source])->assertStatus(422);
        $first = $this->expense(['source' => $source, 'amount' => '7000.50', 'finance_category_id' => null])->assertCreated()->json('data');
        $this->assertSame('other_expense', $first['category']['code']); // category came from the purchase
        $this->assertSame($first['id'], $this->getJson('/api/v1/purchases/'.$p['id'])->json('data.finance_transaction_id'));

        // No duplicate: a second attempt (new key) names the existing transaction.
        $this->expense(['source' => $source, 'amount' => '7000.50'])->assertStatus(409)->assertJsonPath('code', 'finance_already_recorded')->assertJsonPath('details.transaction_id', $first['id']);
        $this->assertSame(1, FinanceTransaction::count());

        // A purchase that already booked its own expense cannot be booked again by hand.
        $self = $this->purchase([['kind' => 'non_stock', 'description' => 'Rent', 'amount' => '100']])->assertCreated()->json('data');
        $this->expense(['source' => ['type' => 'purchase', 'id' => $self['id']], 'amount' => '100'])->assertStatus(409)->assertJsonPath('code', 'finance_already_recorded');
    }

    public function test_purchase_retry_is_idempotent_and_a_changed_payload_conflicts(): void
    {
        $feed = $this->item('feed', 'kg');
        $loc = $this->store();
        $key = $this->key();
        $items = [$this->stockLine($feed, $loc, '40', 'kg', '2000')];
        $payload = ['recorded_at' => $this->at(2), 'idempotency_key' => $key, 'items' => $items];

        $first = $this->postJson('/api/v1/purchases', $payload)->assertCreated()->json('data');
        $again = $this->postJson('/api/v1/purchases', $payload)->assertCreated()->json('data');
        $this->assertSame($first['id'], $again['id']);
        $this->assertSame(1, Purchase::count());
        $this->assertSame(1, InventoryMovement::count());
        $this->assertSame(1, FinanceTransaction::count());
        $this->assertSame('40', $this->stock($feed));

        $changed = $payload;
        $changed['items'][0]['amount'] = '2500';
        $this->postJson('/api/v1/purchases', $changed)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame(1, Purchase::count());
        $this->postJson('/api/v1/purchases', array_diff_key($payload, ['idempotency_key' => 1]))->assertStatus(422);
        // A failed attempt does not burn its key.
        $bad = ['recorded_at' => $this->at(2), 'idempotency_key' => $this->key(), 'items' => [$this->stockLine($feed, (string) Str::uuid(), '1', 'kg', '10')]];
        $this->postJson('/api/v1/purchases', $bad)->assertNotFound();
        $bad['items'][0]['storage_location_id'] = $loc;
        $this->postJson('/api/v1/purchases', $bad)->assertCreated();
    }

    public function test_database_blocks_a_second_stock_in_per_line_and_a_second_expense_per_purchase(): void
    {
        $feed = $this->item('feed', 'kg');
        $loc = $this->store();
        $p = $this->purchase([$this->stockLine($feed, $loc, '10', 'kg', '1000')])->assertCreated()->json('data');

        $movement = InventoryMovement::where('purchase_item_id', $p['items'][0]['id'])->firstOrFail();
        try {
            InventoryMovement::create($movement->only(['farm_id', 'inventory_item_id', 'storage_location_id', 'type', 'reason', 'quantity_delta', 'measurement', 'recorded_at', 'created_by', 'purchase_id', 'purchase_item_id']) + ['id' => (string) Str::uuid7()]);
            $this->fail('A second movement for the same purchase line was accepted.');
        } catch (QueryException) {
            $this->assertSame(1, InventoryMovement::where('purchase_item_id', $p['items'][0]['id'])->count());
        }
        $tx = FinanceTransaction::where('source_id', $p['id'])->firstOrFail();
        try {
            FinanceTransaction::create($tx->only(['farm_id', 'entry_type', 'direction', 'finance_category_id', 'amount', 'currency', 'occurred_on', 'recorded_at', 'source_type', 'source_id', 'source_key', 'created_by']) + ['reference' => 'FIN-X-1']);
            $this->fail('A second expense for the same purchase was accepted.');
        } catch (QueryException) {
            $this->assertSame(1, FinanceTransaction::where('source_id', $p['id'])->count());
        }
    }

    public function test_cancelling_a_purchase_reverses_stock_and_money_without_deleting_history(): void
    {
        $feed = $this->item('feed', 'kg');
        $loc = $this->store();
        $p = $this->purchase([$this->stockLine($feed, $loc, '100', 'kg', '8000'), ['kind' => 'non_stock', 'description' => 'Haulage', 'amount' => '500']])->assertCreated()->json('data');
        $this->assertSame('-8500.00', $this->summary()['totals']['net']);

        $payload = ['reason' => 'Supplier returned the goods', 'recorded_at' => $this->at(1), 'idempotency_key' => $this->key()];
        $res = $this->postJson('/api/v1/purchases/'.$p['id'].'/cancel', $payload)->assertOk();
        $this->assertSame('cancelled', $res->json('data.status'));
        $this->assertNotNull($res->json('data.finance_reversal_transaction_id'));
        $this->assertSame('0', $this->stock($feed));
        $this->assertSame(2, InventoryMovement::where('purchase_id', $p['id'])->count()); // original + compensating, nothing deleted
        $this->assertSame(1, InventoryMovement::where('purchase_id', $p['id'])->where('type', 'reversal')->count());
        $this->assertSame(2, FinanceTransaction::where('source_id', $p['id'])->count());
        $summary = $this->summary();
        $this->assertSame('0.00', $summary['totals']['expense']);
        $this->assertSame('0.00', $summary['totals']['net']);

        // Replay is safe; another key is already-cancelled; a changed reason conflicts.
        $this->postJson('/api/v1/purchases/'.$p['id'].'/cancel', $payload)->assertOk();
        $this->assertSame(2, InventoryMovement::where('purchase_id', $p['id'])->count());
        $this->postJson('/api/v1/purchases/'.$p['id'].'/cancel', ['reason' => 'other'] + $payload)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->postJson('/api/v1/purchases/'.$p['id'].'/cancel', ['idempotency_key' => $this->key()] + $payload)->assertStatus(409)->assertJsonPath('code', 'purchase_already_cancelled');

        // Replace the cancelled purchase once.
        $fix = ['corrects_purchase_id' => $p['id']];
        $new = $this->purchase([$this->stockLine($feed, $loc, '100', 'kg', '7800')], $fix)->assertCreated()->json('data');
        $this->assertSame($p['id'], $new['corrects_purchase_id']);
        $this->purchase([$this->stockLine($feed, $loc, '100', 'kg', '7800')], $fix)->assertStatus(409)->assertJsonPath('code', 'invalid_correction');
        $this->purchase([$this->stockLine($feed, $loc, '1', 'kg', '78')], ['corrects_purchase_id' => $new['id']])->assertStatus(409)->assertJsonPath('code', 'invalid_correction');
        $this->assertSame('-7800.00', $this->summary()['totals']['net']);
    }

    public function test_cancel_is_refused_atomically_when_the_received_stock_was_used_and_goes_through_the_purchase_only(): void
    {
        $feed = $this->item('feed', 'kg');
        $loc = $this->store();
        $p = $this->purchase([$this->stockLine($feed, $loc, '100', 'kg', '8000')])->assertCreated()->json('data');
        $this->postJson('/api/v1/inventory/stock-out', ['inventory_item_id' => $feed, 'storage_location_id' => $loc, 'reason' => 'spoiled', 'components' => [['quantity' => '60', 'unit' => 'kg']],
            'recorded_at' => $this->at(1), 'idempotency_key' => $this->key()])->assertCreated();

        $this->postJson('/api/v1/purchases/'.$p['id'].'/cancel', ['reason' => 'x', 'recorded_at' => $this->at(0), 'idempotency_key' => $this->key()])->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->assertSame('active', $this->getJson('/api/v1/purchases/'.$p['id'])->json('data.status'));
        $this->assertSame('40', $this->stock($feed));
        $this->assertSame(1, FinanceTransaction::count()); // the failed cancel left no reversal behind

        // The purchase's movements and expense can only be undone through the purchase.
        $this->postJson('/api/v1/inventory/movements/'.$p['items'][0]['inventory_movement_id'].'/reverse', ['reason' => 'x', 'recorded_at' => $this->at(0), 'idempotency_key' => $this->key()])
            ->assertStatus(409)->assertJsonPath('code', 'reverse_via_purchase');
        $this->postJson('/api/v1/finance/transactions/'.$p['finance_transaction_id'].'/reverse', ['reason' => 'x', 'idempotency_key' => $this->key()])
            ->assertStatus(409)->assertJsonPath('code', 'reverse_via_purchase');
        $this->assertSame(1, FinanceTransaction::count());
    }

    // ------------------------------------------------------------------ finance

    public function test_finance_categories_are_platform_rows_by_direction(): void
    {
        $all = $this->getJson('/api/v1/finance/categories')->assertOk()->json('data');
        $codes = array_column($all, 'code');
        foreach (['feed', 'medicine_veterinary', 'seed_planting_material', 'fertilizer_agrochemical', 'labour', 'other_expense', 'egg_sales', 'crop_sales', 'other_income'] as $code) {
            $this->assertContains($code, $codes);
        }
        $this->assertTrue(collect($all)->every(fn ($c) => $c['is_system']));
        $this->assertSame(['expense'], array_values(array_unique(array_column($this->getJson('/api/v1/finance/categories?direction=expense')->json('data'), 'direction'))));
        $this->getJson('/api/v1/finance/categories?direction=other')->assertStatus(422);
    }

    public function test_income_and_expense_transactions_validate_category_direction_dates_and_ownership(): void
    {
        $contact = $this->supplier('Buyer & Seller', ['supplier', 'customer']);
        $expense = $this->expense(['contact_id' => $contact, 'description' => 'Diesel'])->assertCreated()->json('data');
        $this->assertSame('expense', $expense['direction']);
        $this->assertSame('2500.00', $expense['amount']);
        $this->assertMatchesRegularExpression('/^FIN-\d{4}-00001$/', $expense['reference']);
        $this->assertSame('Buyer & Seller', $expense['contact_name']);
        $this->assertNull($expense['source']);
        $income = $this->income()->assertCreated()->json('data');
        $this->assertSame('income', $income['direction']);

        // Direction and category must agree; shortcut endpoints pin the direction.
        $this->expense(['finance_category_id' => $this->category('egg_sales', 'income')])->assertStatus(422)->assertJsonValidationErrors('finance_category_id');
        $this->income(['direction' => 'expense'])->assertStatus(422);
        $this->postJson('/api/v1/finance/transactions', ['direction' => 'expense', 'finance_category_id' => $this->category('feed'), 'amount' => '10', 'occurred_on' => now('Africa/Lagos')->toDateString(), 'idempotency_key' => $this->key()])->assertCreated();
        $this->expense(['finance_category_id' => (string) Str::uuid()])->assertStatus(422);
        $this->expense(['occurred_on' => now('Africa/Lagos')->addDays(2)->toDateString()])->assertStatus(422)->assertJsonValidationErrors('occurred_on');
        $this->expense(['recorded_at' => now()->addHour()->utc()->format('Y-m-d\TH:i:s\Z')])->assertStatus(422);
        $this->expense(['production_cycle_id' => (string) Str::uuid()])->assertNotFound();
        $this->expense(['source' => ['type' => 'sale', 'id' => (string) Str::uuid()]])->assertStatus(422);

        $filtered = $this->getJson('/api/v1/finance/transactions?direction=income')->assertOk()->json('data');
        $this->assertSame([$income['id']], array_column($filtered, 'id'));
        $this->getJson('/api/v1/finance/transactions?from=2999-01-01')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_retry_and_changed_payload_on_transactions(): void
    {
        $key = $this->key();
        $a = $this->expense(['idempotency_key' => $key])->assertCreated()->json('data');
        $b = $this->postJson('/api/v1/expenses', ['finance_category_id' => $a['category']['id'], 'amount' => '2500.00', 'occurred_on' => $a['occurred_on'], 'idempotency_key' => $key])->assertCreated()->json('data');
        $this->assertSame($a['id'], $b['id']);
        $this->assertSame(1, FinanceTransaction::count());
        $this->postJson('/api/v1/expenses', ['finance_category_id' => $a['category']['id'], 'amount' => '2600.00', 'occurred_on' => $a['occurred_on'], 'idempotency_key' => $key])->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame(1, FinanceTransaction::count());
    }

    public function test_record_as_expense_links_one_source_and_inherits_its_cycle(): void
    {
        $cycle = $this->cycle();
        $record = $this->mortality($cycle);
        $source = ['type' => 'operational_record', 'id' => $record];

        $tx = $this->expense(['source' => $source, 'description' => 'Vet call-out', 'amount' => '3500'])->assertCreated()->json('data');
        $this->assertSame($source, $tx['source']);
        $this->assertSame($cycle, $tx['production_cycle_id']); // allocation comes from the record's cycle
        $dup = $this->expense(['source' => $source])->assertStatus(409);
        $dup->assertJsonPath('code', 'finance_already_recorded')->assertJsonPath('details.transaction_id', $tx['id']);
        $this->assertSame(1, FinanceTransaction::where('source_id', $record)->count());
        $this->assertSame([$tx['id']], array_column($this->getJson('/api/v1/finance/transactions?source_type=operational_record&source_id='.$record)->json('data'), 'id'));

        // Booking money never alters the record, population or stock.
        $this->assertSame(0, InventoryMovement::count());
        $this->getJson('/api/v1/records/'.$record)->assertOk();

        // The allocation must agree with the record's own cycle.
        $other = $this->mortality($this->cycle());
        $this->expense(['source' => ['type' => 'operational_record', 'id' => $other], 'production_cycle_id' => $cycle])->assertStatus(422)->assertJsonValidationErrors('production_cycle_id');

        // A reversed record cannot be booked.
        $gone = $this->mortality($cycle);
        $this->postJson('/api/v1/records/'.$gone.'/reverse', ['reason' => 'typo', 'recorded_at' => $this->at(1), 'idempotency_key' => $this->key()])->assertCreated();
        $this->expense(['source' => ['type' => 'operational_record', 'id' => $gone]])->assertStatus(409)->assertJsonPath('code', 'source_reversed');
        $this->expense(['source' => ['type' => 'health_record', 'id' => $record]])->assertNotFound();
    }

    public function test_a_health_record_can_be_booked_as_its_medicine_cost_once_without_changing_stock(): void
    {
        $cycle = $this->cycle();
        $drug = $this->item('medicine', 'ml');
        $loc = $this->store();
        $this->purchase([$this->stockLine($drug, $loc, '500', 'ml', '6000')])->assertCreated();
        $health = $this->postJson('/api/v1/health-records', ['production_cycle_id' => $cycle, 'type' => 'vaccination', 'recorded_at' => $this->at(2), 'idempotency_key' => $this->key(),
            'details' => ['target_disease' => 'Newcastle'], 'medicines' => [['inventory_item_id' => $drug, 'storage_location_id' => $loc, 'components' => [['quantity' => '30', 'unit' => 'ml']]]]])->assertCreated()->json('data');
        $movements = InventoryMovement::count();

        $source = ['type' => 'health_record', 'id' => $health['id']];
        $tx = $this->expense(['source' => $source, 'amount' => '360', 'finance_category_id' => $this->category('medicine_veterinary')])->assertCreated()->json('data');
        $this->assertSame($cycle, $tx['production_cycle_id']);
        $this->expense(['source' => $source])->assertStatus(409)->assertJsonPath('code', 'finance_already_recorded');
        $this->assertSame($movements, InventoryMovement::count()); // money is not a stock change
        $this->assertSame('470', $this->stock($drug));
        $this->assertSame('360.00', $this->summary('?production_cycle_id='.$cycle)['totals']['expense']);
    }

    public function test_purchase_listing_filters_by_status_supplier_cycle_and_text(): void
    {
        $supplier = $this->supplier('Filter Supplier');
        $cycle = $this->cycle();
        $a = $this->purchase([['kind' => 'non_stock', 'description' => 'One', 'amount' => '10']], ['contact_id' => $supplier, 'supplier_reference' => 'ABC-1', 'production_cycle_id' => $cycle])->assertCreated()->json('data');
        $b = $this->purchase([['kind' => 'non_stock', 'description' => 'Two', 'amount' => '20']])->assertCreated()->json('data');
        $this->postJson('/api/v1/purchases/'.$b['id'].'/cancel', ['reason' => 'x', 'recorded_at' => $this->at(0), 'idempotency_key' => $this->key()])->assertOk();

        $ids = fn (string $q) => array_column($this->getJson('/api/v1/purchases'.$q)->assertOk()->json('data'), 'id');
        $this->assertEqualsCanonicalizing([$a['id'], $b['id']], $ids(''));
        $this->assertSame([$b['id']], $ids('?status=cancelled'));
        $this->assertSame([$a['id']], $ids('?contact_id='.$supplier));
        $this->assertSame([$a['id']], $ids('?production_cycle_id='.$cycle));
        $this->assertSame([$a['id']], $ids('?search=ABC'));
        $this->assertSame([], $ids('?from=2999-01-01'));
        $this->getJson('/api/v1/purchases?status=lost')->assertStatus(422);
    }

    public function test_reversal_and_correction_keep_one_live_transaction_per_source(): void
    {
        $record = $this->mortality($this->cycle());
        $source = ['type' => 'operational_record', 'id' => $record];
        $tx = $this->expense(['source' => $source, 'amount' => '3500'])->assertCreated()->json('data');

        $rev = ['reason' => 'Wrong amount', 'idempotency_key' => $this->key()];
        $res = $this->postJson('/api/v1/finance/transactions/'.$tx['id'].'/reverse', $rev)->assertCreated()->json('data');
        $this->assertSame('reversal', $res['entry_type']);
        $this->assertSame('3500.00', $res['amount']);
        $this->assertSame($tx['id'], $res['reverses_transaction_id']);
        $this->assertSame($source, $res['source']);
        $this->assertTrue($this->getJson('/api/v1/finance/transactions/'.$tx['id'])->json('data.is_reversed'));
        $this->assertSame('0.00', $this->summary()['totals']['expense']);

        // Replay; reversing again or reversing the reversal is refused.
        $this->postJson('/api/v1/finance/transactions/'.$tx['id'].'/reverse', $rev)->assertCreated()->assertJsonPath('data.id', $res['id']);
        $this->postJson('/api/v1/finance/transactions/'.$tx['id'].'/reverse', ['idempotency_key' => $this->key()] + $rev)->assertStatus(409)->assertJsonPath('code', 'transaction_already_reversed');
        $this->postJson('/api/v1/finance/transactions/'.$res['id'].'/reverse', ['idempotency_key' => $this->key()] + $rev)->assertStatus(409)->assertJsonPath('code', 'transaction_already_reversed');
        $this->assertSame(2, FinanceTransaction::count());

        // Now the source is free again, and a linked replacement is allowed exactly once.
        $fixed = $this->expense(['source' => $source, 'amount' => '3200', 'corrects_transaction_id' => $tx['id']])->assertCreated()->json('data');
        $this->assertSame($tx['id'], $fixed['corrects_transaction_id']);
        $this->assertSame('3200.00', $this->summary()['totals']['expense']);
        $this->expense(['source' => $source])->assertStatus(409)->assertJsonPath('code', 'finance_already_recorded');
        $this->expense(['amount' => '1', 'corrects_transaction_id' => $tx['id']])->assertStatus(409)->assertJsonPath('code', 'invalid_correction');
        $this->assertSame(3, FinanceTransaction::count());
    }

    public function test_cycle_profitability_is_allocated_netted_and_closed_cycles_are_protected(): void
    {
        $cycle = $this->cycle();
        $other = $this->cycle();
        $feed = $this->item('feed', 'kg');
        $loc = $this->store();
        $today = now('Africa/Lagos')->toDateString();

        $this->income(['production_cycle_id' => $cycle, 'amount' => '120000'])->assertCreated();
        $this->purchase([$this->stockLine($feed, $loc, '500', 'kg', '45000.50')], ['production_cycle_id' => $cycle])->assertCreated();
        $this->expense(['production_cycle_id' => $cycle, 'amount' => '1999.99'])->assertCreated();
        $labour = $this->expense(['production_cycle_id' => $cycle, 'amount' => '10000', 'finance_category_id' => $this->category('labour')])->assertCreated()->json('data');
        $this->expense(['production_cycle_id' => $other, 'amount' => '777'])->assertCreated();
        $this->expense(['amount' => '55.05'])->assertCreated(); // unallocated overhead

        $profit = $this->summary('?production_cycle_id='.$cycle);
        $this->assertSame($cycle, $profit['production_cycle_id']);
        $this->assertSame('120000.00', $profit['totals']['income']);
        $this->assertSame('57000.49', $profit['totals']['expense']);
        $this->assertSame('62999.51', $profit['totals']['net']);
        $byCode = collect($profit['by_category'])->keyBy('code');
        $this->assertSame('45000.50', $byCode['feed']['amount']);
        $this->assertSame('10000.00', $byCode['labour']['amount']);
        $this->assertArrayNotHasKey('by_cycle', $profit);

        $all = $this->summary();
        $this->assertSame('57832.54', $all['totals']['expense']); // 57000.49 + 777 + 55.05
        $rows = collect($all['by_cycle'])->keyBy(fn ($r) => $r['production_cycle_id'] ?? 'none');
        $this->assertSame('62999.51', $rows[$cycle]['net']);
        $this->assertSame('-777.00', $rows[$other]['net']);
        $this->assertSame('-55.05', $rows['none']['net']);

        // Reversing a cost lowers that cycle's expense by exactly its amount.
        $this->postJson('/api/v1/finance/transactions/'.$labour['id'].'/reverse', ['reason' => 'dup', 'idempotency_key' => $this->key()])->assertCreated();
        $this->assertSame('47000.49', $this->summary('?production_cycle_id='.$cycle)['totals']['expense']);

        // Date windows use the farm-local business day.
        $this->assertSame('0.00', $this->summary('?from=2000-01-01&to=2000-12-31')['totals']['expense']);
        $this->assertSame('120000.00', $this->summary('?from='.$today.'&to='.$today)['totals']['income']);
        $this->getJson('/api/v1/finance/summary?production_cycle_id='.(string) Str::uuid())->assertNotFound();

        // Closed cycles take no new allocation, purchases or reversals of allocated money.
        $this->postJson('/api/v1/production-cycles/'.$cycle.'/close', ['end_date' => $today, 'reason' => 'Sold out'])->assertOk();
        $this->expense(['production_cycle_id' => $cycle])->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
        $this->purchase([['kind' => 'non_stock', 'description' => 'x', 'amount' => '5']], ['production_cycle_id' => $cycle])->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
        $kept = FinanceTransaction::where('production_cycle_id', $cycle)->where('direction', 'expense')->where('entry_type', 'entry')->whereNull('source_type')->whereDoesntHave('reversal')->firstOrFail();
        $this->postJson('/api/v1/finance/transactions/'.$kept->id.'/reverse', ['reason' => 'x', 'idempotency_key' => $this->key()])->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
        $this->getJson('/api/v1/finance/summary?production_cycle_id='.$cycle)->assertOk(); // still readable
    }

    public function test_ledger_rows_are_append_only_and_isolated_between_farms(): void
    {
        $tx = $this->expense()->assertCreated()->json('data');
        $row = FinanceTransaction::findOrFail($tx['id']);
        try {
            $row->update(['amount' => '1.00']);
            $this->fail('A ledger row was edited.');
        } catch (LogicException) {
            $this->assertSame('2500.00', (string) $row->fresh()->amount);
        }
        $this->expectException(LogicException::class);
        try {
            $row->delete();
        } finally {
            $this->assertSame(1, FinanceTransaction::count());
        }
    }

    public function test_other_farms_cannot_see_or_touch_purchases_and_transactions(): void
    {
        $feed = $this->item('feed', 'kg');
        $loc = $this->store();
        $p = $this->purchase([$this->stockLine($feed, $loc, '10', 'kg', '1000')], ['production_cycle_id' => $this->cycle()])->assertCreated()->json('data');
        $cycle = $p['production_cycle_id'];

        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $this->getJson('/api/v1/purchases/'.$p['id'])->assertNotFound();
        $this->postJson('/api/v1/purchases/'.$p['id'].'/cancel', ['reason' => 'x', 'recorded_at' => $this->at(0), 'idempotency_key' => $this->key()])->assertNotFound();
        $this->getJson('/api/v1/finance/transactions/'.$p['finance_transaction_id'])->assertNotFound();
        $this->postJson('/api/v1/finance/transactions/'.$p['finance_transaction_id'].'/reverse', ['reason' => 'x', 'idempotency_key' => $this->key()])->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/purchases')->json('data'));
        $this->assertSame([], $this->getJson('/api/v1/finance/transactions')->json('data'));
        $this->assertSame('0.00', $this->getJson('/api/v1/finance/summary')->json('data.totals.expense'));
        $this->getJson('/api/v1/finance/summary?production_cycle_id='.$cycle)->assertNotFound();
        $this->postJson('/api/v1/expenses', ['finance_category_id' => $this->category('feed'), 'amount' => '5', 'occurred_on' => now('Africa/Lagos')->toDateString(), 'idempotency_key' => $this->key(),
            'production_cycle_id' => $cycle])->assertNotFound();
        $this->postJson('/api/v1/expenses', ['finance_category_id' => $this->category('feed'), 'amount' => '5', 'occurred_on' => now('Africa/Lagos')->toDateString(), 'idempotency_key' => $this->key(),
            'source' => ['type' => 'purchase', 'id' => $p['id']]])->assertNotFound();
        $this->postJson('/api/v1/purchases', ['recorded_at' => $this->at(1), 'idempotency_key' => $this->key(), 'items' => [$this->stockLine($feed, $loc, '1', 'kg', '10')]])->assertNotFound();

        $this->signInAs($this->owner);
        $this->assertSame('active', $this->getJson('/api/v1/purchases/'.$p['id'])->json('data.status'));
    }

    // ------------------------------------------------------------------ permissions

    public function test_finance_manager_and_owner_can_work_but_workers_and_vets_have_no_finance_access(): void
    {
        $feed = $this->item('feed', 'kg');
        $loc = $this->store();
        $p = $this->purchase([$this->stockLine($feed, $loc, '10', 'kg', '1000')])->assertCreated()->json('data');
        $tx = $this->expense()->assertCreated()->json('data');
        $contact = $this->supplier('Visible Vendor');

        $this->category('transport');
        $this->category('egg_sales', 'income');
        foreach ([FarmRole::FarmWorker, FarmRole::Vet] as $role) {
            $this->signInAs($this->member($role, name: $role->label()));
            foreach (['/api/v1/contacts', '/api/v1/contacts/'.$contact, '/api/v1/purchases', '/api/v1/purchases/'.$p['id'], '/api/v1/finance/categories', '/api/v1/finance/summary',
                '/api/v1/finance/transactions', '/api/v1/finance/transactions/'.$tx['id']] as $url) {
                $this->getJson($url)->assertForbidden();
            }
            $this->postJson('/api/v1/contacts', ['name' => 'Nope', 'roles' => ['supplier']])->assertForbidden();
            $this->postJson('/api/v1/purchases', ['recorded_at' => $this->at(1), 'idempotency_key' => $this->key(), 'items' => [['kind' => 'non_stock', 'description' => 'x', 'amount' => '5']]])->assertForbidden();
            $this->postJson('/api/v1/purchases/'.$p['id'].'/cancel', ['reason' => 'x', 'recorded_at' => $this->at(0), 'idempotency_key' => $this->key()])->assertForbidden();
            $this->expense()->assertForbidden();
            $this->income()->assertForbidden();
            $this->postJson('/api/v1/finance/transactions/'.$tx['id'].'/reverse', ['reason' => 'x', 'idempotency_key' => $this->key()])->assertForbidden();
        }
        $this->assertSame(1, Purchase::count());

        foreach ([FarmRole::Finance, FarmRole::Manager] as $role) {
            $this->signInAs($this->member($role, name: 'Fin '.$role->value));
            $this->getJson('/api/v1/purchases/'.$p['id'])->assertOk();
            $this->getJson('/api/v1/finance/summary')->assertOk();
            $this->expense()->assertCreated();
            $this->purchase([['kind' => 'non_stock', 'description' => 'Courier', 'amount' => '300']])->assertCreated();
            $this->postJson('/api/v1/contacts', ['name' => 'Vendor '.$role->value, 'roles' => ['supplier']])->assertCreated();
        }
    }

    public function test_correcting_and_reversing_need_their_own_permissions(): void
    {
        $this->assertTrue(FarmRole::Finance->can(Permission::FinanceReverse));
        $this->assertTrue(FarmRole::Finance->can(Permission::PurchaseCancel));
        $this->assertFalse(FarmRole::FarmWorker->can(Permission::FinanceView));
        $this->assertFalse(FarmRole::Vet->can(Permission::PurchaseView));
        $this->assertFalse(FarmRole::FarmWorker->can(Permission::ContactView));
        $this->assertTrue(FarmRole::Finance->can(Permission::InventoryView)); // can read stock the purchases created
        $this->assertFalse(FarmRole::Finance->can(Permission::InventoryManage)); // purchasing is its own permission path
    }
}
