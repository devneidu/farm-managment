<?php

namespace Tests\Feature\Sales;

use App\Enums\FarmRole;
use App\Models\Contact;
use App\Models\FinanceTransaction;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\OperationType;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Species;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Team\TeamTestCase;

/** The "Sell" flow built on top of the sale / invoice / payment writers: paid-now, quick buyer, quantity x price, sellables, one-step cancel. */
class QuickSellTest extends TeamTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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

    private function cycle(int $population = 100): string
    {
        return $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => 'Layer flock '.Str::random(4), 'operation_type_id' => OperationType::where('code', 'poultry')->firstOrFail()->id,
            'species_id' => Species::where('code', 'chicken')->firstOrFail()->id, 'production_purpose' => 'breeding', 'initial_population' => $population, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
    }

    private function population(string $cycle): int
    {
        return $this->getJson('/api/v1/production-cycles/'.$cycle)->assertOk()->json('data.livestock.current_population');
    }

    private function store(): string
    {
        return $this->postJson('/api/v1/storage-locations', ['name' => 'Store '.Str::random(5), 'type' => 'store'])->assertCreated()->json('data.id');
    }

    private function produce(array $extra = []): string
    {
        return $this->postJson('/api/v1/inventory/items', array_replace(['name' => 'Produce '.Str::random(6), 'category' => 'produce', 'stock_unit' => 'piece'], $extra))->assertCreated()->json('data.id');
    }

    private function receive(string $item, string $loc, string $qty, string $unit = 'piece'): void
    {
        $this->postJson('/api/v1/inventory/stock-in', ['inventory_item_id' => $item, 'storage_location_id' => $loc, 'reason' => 'opening_balance', 'components' => [['quantity' => $qty, 'unit' => $unit]],
            'recorded_at' => $this->at(10), 'idempotency_key' => $this->key()])->assertCreated();
    }

    private function receiveOutput(string $output, string $qty, string $unit): void
    {
        $this->postJson('/api/v1/inventory/stock-in', ['output' => $output, 'reason' => 'opening_balance', 'components' => [['quantity' => $qty, 'unit' => $unit]],
            'recorded_at' => $this->at(10), 'idempotency_key' => $this->key()])->assertCreated();
    }

    private function stock(string $item): string
    {
        return $this->getJson('/api/v1/inventory/items/'.$item)->assertOk()->json('data.stock.quantity');
    }

    private function sale(array $items, array $extra = [])
    {
        return $this->postJson('/api/v1/sales', array_replace(['recorded_at' => $this->at(2), 'idempotency_key' => $this->key(), 'items' => $items], $extra));
    }

    private function other(string $amount = '1000.00'): array
    {
        return ['kind' => 'other', 'description' => 'Manure', 'amount' => $amount];
    }

    private function payNow(string $amount, array $extra = []): array
    {
        return array_replace(['amount' => $amount, 'method' => 'cash'], $extra);
    }

    // ------------------------------------------------------------------ sellables

    public function test_sellables_list_only_open_animals_and_stock_on_hand(): void
    {
        $flock = $this->cycle(40);
        $emptied = $this->cycle(5);
        $this->sale([['kind' => 'livestock', 'production_cycle_id' => $emptied, 'head_count' => 5, 'amount' => '5000']])->assertCreated();
        $closed = $this->cycle(10);
        $this->postJson('/api/v1/production-cycles/'.$closed.'/close', ['end_date' => '2026-10-10', 'reason' => 'Flock sold'])->assertOk();

        $loc = $this->store();
        $stocked = $this->produce(['name' => 'Tomatoes']);
        $this->receive($stocked, $loc, '25');
        $this->produce(['name' => 'Nothing here']);
        $meds = $this->postJson('/api/v1/inventory/items', ['name' => 'Seed bag', 'category' => 'general_supply', 'stock_unit' => 'kg'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/inventory/stock-in', ['inventory_item_id' => $meds, 'storage_location_id' => $loc, 'reason' => 'donation', 'components' => [['quantity' => '5', 'unit' => 'kg']],
            'recorded_at' => $this->at(10), 'idempotency_key' => $this->key()])->assertCreated();
        $this->receiveOutput('eggs', '90', 'piece');

        $data = $this->getJson('/api/v1/sales/sellables')->assertOk()->json('data');
        $this->assertSame([$flock], array_column($data['animals'], 'production_cycle_id'));
        $this->assertSame(40, $data['animals'][0]['head_count']);
        $this->assertSame('head', $data['animals'][0]['unit']);

        $byLabel = collect($data['in_store'])->keyBy('label');
        $this->assertEqualsCanonicalizing(['Tomatoes', 'Eggs'], $byLabel->keys()->all()); // no zero stock, no non-sellable category
        $this->assertSame('25', $byLabel['Tomatoes']['available']['quantity']);
        $this->assertNull($byLabel['Tomatoes']['output']);
        $this->assertSame($loc, $byLabel['Tomatoes']['locations'][0]['storage_location_id']);
        $this->assertSame('eggs', $byLabel['Eggs']['output']);
        $this->assertSame('90', $byLabel['Eggs']['available']['quantity']);
    }

    public function test_sellables_are_scoped_to_the_farm_and_need_sale_create(): void
    {
        $this->cycle(10);
        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $this->assertSame([], $this->getJson('/api/v1/sales/sellables')->assertOk()->json('data.animals'));
        $this->signInAs($this->member(FarmRole::Vet, name: 'Vet'));
        $this->getJson('/api/v1/sales/sellables')->assertForbidden();
    }

    // ------------------------------------------------------------------ paid now

    public function test_sale_with_full_payment_creates_invoice_payment_and_income_in_one_request(): void
    {
        $item = $this->produce();
        $loc = $this->store();
        $this->receive($item, $loc, '100');
        $sale = $this->sale([['kind' => 'stock', 'inventory_item_id' => $item, 'storage_location_id' => $loc, 'components' => [['quantity' => '30', 'unit' => 'piece']], 'amount' => '4500.00']],
            ['payment' => $this->payNow('4500.00', ['received_on' => '2026-10-14'])])->assertCreated()->json('data');

        $this->assertSame('paid', $sale['payment_status']);
        $this->assertSame('4500.00', $sale['amount_paid']);
        $this->assertSame('0.00', $sale['outstanding']);
        $this->assertNotNull($sale['invoice_id']);
        $this->assertSame($sale['invoice']['id'], $sale['invoice_id']);
        $this->assertCount(1, $sale['payments']);
        $this->assertSame('70', $this->stock($item));
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, FinanceTransaction::count());
        $this->assertSame('4500.00', (string) FinanceTransaction::firstOrFail()->amount);
        $this->assertSame($sale['payments'][0]['id'], Payment::firstOrFail()->id);
    }

    public function test_sale_with_part_payment_leaves_the_balance_on_the_invoice(): void
    {
        $sale = $this->sale([$this->other('1000.00')], ['payment' => $this->payNow('400.00', ['method' => 'mobile_money'])])->assertCreated()->json('data');
        $this->assertSame('partially_paid', $sale['payment_status']);
        $this->assertSame('400.00', $sale['amount_paid']);
        $this->assertSame('600.00', $sale['outstanding']);
        $this->assertSame('mobile_money', Payment::firstOrFail()->method);
        $this->assertSame('2026-10-14', Payment::firstOrFail()->received_on->toDateString()); // defaults to the sale day
    }

    public function test_sale_with_an_invoice_block_and_payment_issues_one_invoice_with_that_dates(): void
    {
        $sale = $this->sale([$this->other('1000.00')], ['invoice' => ['due_date' => '2026-10-30'], 'payment' => $this->payNow('1000')])->assertCreated()->json('data');
        $this->assertSame('paid', $sale['payment_status']);
        $this->assertSame('2026-10-30', Invoice::firstOrFail()->due_date->toDateString());
        $this->assertSame(1, Invoice::count());
    }

    public function test_sale_without_a_payment_block_stays_unpaid_and_uninvoiced(): void
    {
        $sale = $this->sale([$this->other()])->assertCreated()->json('data');
        $this->assertSame('uninvoiced', $sale['payment_status']);
        $this->assertNull($sale['invoice_id']);
        $this->assertSame([], $sale['payments']);
        $this->assertSame(0, Payment::count());
    }

    public function test_payment_above_the_total_or_badly_dated_is_refused_and_nothing_is_written(): void
    {
        $item = $this->produce();
        $loc = $this->store();
        $this->receive($item, $loc, '10');
        $line = ['kind' => 'stock', 'inventory_item_id' => $item, 'storage_location_id' => $loc, 'components' => [['quantity' => '5', 'unit' => 'piece']], 'amount' => '500'];
        $this->sale([$line], ['payment' => $this->payNow('500.01')])->assertStatus(422)->assertJsonValidationErrors('payment.amount');
        $this->sale([$line], ['payment' => $this->payNow('0')])->assertStatus(422)->assertJsonValidationErrors('payment.amount');
        $this->sale([$line], ['payment' => $this->payNow('10', ['received_on' => '2026-10-15'])])->assertStatus(422)->assertJsonValidationErrors('payment.received_on');
        $this->sale([$line], ['payment' => $this->payNow('10', ['received_on' => '2026-10-13'])])->assertStatus(422)->assertJsonValidationErrors('payment.received_on');
        $this->sale([$line], ['payment' => $this->payNow('10', ['method' => 'barter'])])->assertStatus(422)->assertJsonValidationErrors('payment.method');
        $this->assertSame('10', $this->stock($item));
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_a_failure_after_the_sale_rolls_the_payment_back_too(): void
    {
        $item = $this->produce();
        $loc = $this->store();
        $this->receive($item, $loc, '5');
        $this->sale([['kind' => 'stock', 'inventory_item_id' => $item, 'storage_location_id' => $loc, 'components' => [['quantity' => '6', 'unit' => 'piece']], 'amount' => '60']],
            ['payment' => $this->payNow('60')])->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->assertSame(0, Sale::count() + Invoice::count() + Payment::count() + FinanceTransaction::count());
    }

    public function test_retrying_the_same_request_replays_it_without_a_second_payment_or_stock_out(): void
    {
        $item = $this->produce();
        $loc = $this->store();
        $this->receive($item, $loc, '50');
        $body = ['recorded_at' => $this->at(2), 'idempotency_key' => 'quick-sell-retry-1', 'payment' => $this->payNow('300.00'),
            'items' => [['kind' => 'stock', 'inventory_item_id' => $item, 'storage_location_id' => $loc, 'components' => [['quantity' => '10', 'unit' => 'piece']], 'amount' => '300.00']]];
        $first = $this->postJson('/api/v1/sales', $body)->assertCreated()->json('data');
        $again = $this->postJson('/api/v1/sales', $body)->assertCreated()->json('data');
        $this->assertSame($first['id'], $again['id']);
        $this->assertSame($first['invoice_id'], $again['invoice_id']);
        $this->assertSame('40', $this->stock($item));
        $this->assertSame([1, 1, 1], [Sale::count(), Invoice::count(), Payment::count()]);
        $this->assertSame(1, InventoryMovement::where('sale_id', $first['id'])->count());

        // The same key with a different payment is a conflict, not a second payment.
        $this->postJson('/api/v1/sales', ['payment' => $this->payNow('100.00')] + $body)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame(1, Payment::count());
    }

    // ------------------------------------------------------------------ quick buyer

    public function test_buyer_creates_a_customer_contact_without_contact_manage(): void
    {
        $this->signInAs($this->member(FarmRole::FarmWorker, name: 'Field worker'));
        $this->postJson('/api/v1/contacts', ['name' => 'Nope', 'roles' => ['customer']])->assertForbidden();
        $sale = $this->sale([$this->other()], ['buyer' => ['name' => '  Mama   Ngozi ', 'phone' => '08031234567']])->assertCreated()->json('data');
        $contact = Contact::findOrFail($sale['contact_id']);
        $this->assertSame('Mama Ngozi', $contact->name);
        $this->assertSame('08031234567', $contact->phone);
        $this->assertTrue($contact->is_customer);
        $this->assertFalse($contact->is_supplier);
        $this->assertSame('Mama Ngozi', $sale['customer_name']);
    }

    public function test_buyer_reuses_an_existing_contact_and_adds_the_customer_role(): void
    {
        $supplier = $this->postJson('/api/v1/contacts', ['name' => 'Feed Mill Ltd', 'roles' => ['supplier']])->assertCreated()->json('data.id');
        $sale = $this->sale([$this->other()], ['buyer' => ['name' => 'feed mill ltd', 'phone' => '08090000000']])->assertCreated()->json('data');
        $this->assertSame($supplier, $sale['contact_id']);
        $this->assertSame(1, Contact::count());
        $contact = Contact::findOrFail($supplier);
        $this->assertTrue($contact->is_customer);
        $this->assertTrue($contact->is_supplier);
        $this->assertSame('08090000000', $contact->phone);

        $again = $this->sale([$this->other()], ['buyer' => ['name' => 'Feed Mill Ltd']])->assertCreated()->json('data');
        $this->assertSame($supplier, $again['contact_id']);
        $this->assertSame(1, Contact::count());
    }

    public function test_buyer_cannot_be_combined_and_inactive_contacts_are_refused(): void
    {
        $contact = $this->postJson('/api/v1/contacts', ['name' => 'Old Buyer', 'roles' => ['customer']])->assertCreated()->json('data.id');
        $this->sale([$this->other()], ['buyer' => ['name' => 'X'], 'contact_id' => $contact])->assertStatus(422)->assertJsonValidationErrors('buyer');
        $this->sale([$this->other()], ['buyer' => ['name' => 'X'], 'customer_name' => 'Y'])->assertStatus(422)->assertJsonValidationErrors('buyer');
        $this->sale([$this->other()], ['buyer' => ['phone' => '080']])->assertStatus(422)->assertJsonValidationErrors('buyer.name');
        $this->patchJson('/api/v1/contacts/'.$contact, ['is_active' => false])->assertOk();
        $this->sale([$this->other()], ['buyer' => ['name' => 'Old Buyer']])->assertStatus(409)->assertJsonPath('code', 'contact_inactive');
        $this->assertSame(1, Contact::count());
        $this->assertSame(0, Sale::count());
    }

    // ------------------------------------------------------------------ quantity x price

    public function test_lines_can_be_priced_by_quantity_and_unit_price(): void
    {
        $item = $this->produce();
        $loc = $this->store();
        $this->receive($item, $loc, '100');
        $cycle = $this->cycle(50);
        $sale = $this->sale([
            ['kind' => 'stock', 'inventory_item_id' => $item, 'storage_location_id' => $loc, 'quantity' => '12', 'unit_price' => '12.50'],
            ['kind' => 'livestock', 'production_cycle_id' => $cycle, 'quantity' => 4, 'unit_price' => '7500'],
            ['kind' => 'other', 'description' => 'Manure bags', 'quantity' => '2.5', 'unit_price' => '333.33'],
            ['kind' => 'stock', 'inventory_item_id' => $item, 'storage_location_id' => $loc, 'components' => [['quantity' => '3', 'unit' => 'piece']], 'unit_price' => '10', 'amount' => '30.00'],
        ])->assertCreated()->json('data');

        $this->assertSame(['150.00', '30000.00', '833.33', '30.00'], array_column($sale['items'], 'amount')); // 833.325 rounds half up
        $this->assertSame('31013.33', $sale['total_amount']);
        $this->assertSame(4, $sale['items'][1]['head_count']);
        $this->assertSame('85', $this->stock($item));
        $this->assertSame(46, $this->population($cycle));
    }

    public function test_amount_must_agree_with_quantity_times_unit_price_and_inputs_must_be_complete(): void
    {
        $item = $this->produce();
        $loc = $this->store();
        $this->receive($item, $loc, '100');
        $cycle = $this->cycle(50);
        $stock = ['kind' => 'stock', 'inventory_item_id' => $item, 'storage_location_id' => $loc];
        $this->sale([$stock + ['quantity' => '10', 'unit_price' => '5', 'amount' => '49.99']])->assertStatus(422)->assertJsonValidationErrors('items.0.amount');
        $this->sale([$stock + ['quantity' => '10', 'components' => [['quantity' => '10', 'unit' => 'piece']], 'unit_price' => '5']])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
        $this->sale([$stock + ['components' => [['quantity' => '10', 'unit' => 'piece']]]])->assertStatus(422)->assertJsonValidationErrors('items.0.amount');
        $this->sale([['kind' => 'livestock', 'production_cycle_id' => $cycle, 'head_count' => 3, 'quantity' => 4, 'unit_price' => '100']])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
        $this->sale([['kind' => 'livestock', 'production_cycle_id' => $cycle, 'quantity' => '2.5', 'unit_price' => '100']])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
        $this->sale([['kind' => 'other', 'description' => 'x', 'unit_price' => '100']])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
        $this->sale([['kind' => 'livestock', 'production_cycle_id' => $cycle, 'head_count' => 4, 'quantity' => 4, 'unit_price' => '100', 'amount' => '400.00']])->assertCreated();
        $this->assertSame('100', $this->stock($item));
        $this->assertSame(46, $this->population($cycle));
    }

    // ------------------------------------------------------------------ income category

    public function test_the_income_category_follows_what_was_sold(): void
    {
        $loc = $this->store();
        $this->receiveOutput('eggs', '100', 'piece');
        $this->receiveOutput('milk', '100', 'l');
        $item = $this->produce();
        $this->receive($item, $loc, '20');
        $cycle = $this->cycle(20);
        $eggs = ['kind' => 'stock', 'output' => 'eggs', 'quantity' => '10', 'amount' => '100'];
        $milk = ['kind' => 'stock', 'output' => 'milk', 'quantity' => '5', 'amount' => '100'];
        $crop = ['kind' => 'stock', 'inventory_item_id' => $item, 'storage_location_id' => $loc, 'quantity' => '5', 'amount' => '100'];
        $live = ['kind' => 'livestock', 'production_cycle_id' => $cycle, 'head_count' => 1, 'amount' => '100'];
        $code = fn (array $items) => $this->sale($items, ['payment' => $this->payNow('100')])->assertCreated();

        $this->assertSame('egg_sales', $code([$eggs])->json('data.finance_category.code'));
        $this->assertSame('egg_sales', $code([$eggs, $eggs])->json('data.finance_category.code'));
        $this->assertSame('milk_sales', $code([$milk])->json('data.finance_category.code'));
        $this->assertSame('crop_sales', $code([$crop])->json('data.finance_category.code'));
        $this->assertSame('crop_sales', $code([$eggs, $milk])->json('data.finance_category.code')); // mixed produce keeps the existing behaviour
        $this->assertSame('livestock_sales', $code([$live])->json('data.finance_category.code'));
        $this->assertSame('other_income', $code([$live, $crop])->json('data.finance_category.code'));
        $this->assertSame('other_income', $code([$this->other()])->json('data.finance_category.code'));
        $this->assertSame('egg_sales', FinanceTransaction::firstOrFail()->category->code); // the payment is booked to the sale's category
    }

    public function test_selling_farm_meat_books_livestock_sales_and_a_mixed_sale_keeps_the_old_rule(): void
    {
        $cycle = $this->cycle(20);
        $meat = $this->postJson('/api/v1/livestock-exits', ['production_cycle_id' => $cycle, 'type' => 'slaughtered', 'head_count' => 5, 'meat_weight_kg' => '12', 'recorded_at' => $this->at(3), 'idempotency_key' => $this->key()])
            ->assertCreated()->json('data.meat');
        $this->receiveOutput('eggs', '50', 'piece');
        $line = ['kind' => 'stock', 'inventory_item_id' => $meat['inventory_item_id'], 'storage_location_id' => $meat['storage_location_id'], 'quantity' => '4', 'unit_price' => '3000'];

        $this->assertSame('livestock_sales', $this->sale([$line], ['payment' => $this->payNow('1000')])->assertCreated()->json('data.finance_category.code'));
        $eggs = ['kind' => 'stock', 'output' => 'eggs', 'quantity' => '10', 'amount' => '100'];
        $this->assertSame('crop_sales', $this->sale([$line, $eggs])->assertCreated()->json('data.finance_category.code'));
    }

    // ------------------------------------------------------------------ one-step cancel

    public function test_cancel_with_reverse_payments_undoes_money_stock_and_invoice_in_one_request(): void
    {
        $item = $this->produce();
        $loc = $this->store();
        $this->receive($item, $loc, '40');
        $sale = $this->sale([['kind' => 'stock', 'inventory_item_id' => $item, 'storage_location_id' => $loc, 'quantity' => '10', 'unit_price' => '100']], ['payment' => $this->payNow('600')])->assertCreated()->json('data');
        $this->postJson('/api/v1/invoices/'.$sale['invoice_id'].'/payments', ['amount' => '400', 'method' => 'cash', 'received_on' => '2026-10-14', 'idempotency_key' => $this->key()])->assertCreated();
        $this->assertSame('30', $this->stock($item));

        $cancel = ['reason' => 'Wrong buyer', 'recorded_at' => $this->at(0), 'idempotency_key' => $this->key()];
        $this->postJson('/api/v1/sales/'.$sale['id'].'/cancel', $cancel)->assertStatus(409)->assertJsonPath('code', 'sale_has_payments');
        $done = $this->postJson('/api/v1/sales/'.$sale['id'].'/cancel', $cancel + ['reverse_payments' => true])->assertOk()->json('data');

        $this->assertSame('cancelled', $done['status']);
        $this->assertSame('40', $this->stock($item));
        $this->assertSame('void', Invoice::firstOrFail()->status);
        $this->assertSame(4, Payment::count()); // two payments + two offsetting rows
        $this->assertSame(2, Payment::where('entry_type', 'reversal')->count());
        $this->assertSame('0.00', Invoice::firstOrFail()->amountPaid());
        $this->assertSame(4, FinanceTransaction::count());

        // Replay with the same key changes nothing.
        $this->postJson('/api/v1/sales/'.$sale['id'].'/cancel', $cancel + ['reverse_payments' => true])->assertOk();
        $this->assertSame(4, Payment::count());
    }

    public function test_one_step_cancel_needs_payment_reverse_as_well_as_sale_cancel(): void
    {
        $sale = $this->sale([$this->other('100')], ['payment' => $this->payNow('100')])->assertCreated()->json('data');
        $this->signInAs($this->member(FarmRole::FarmWorker, name: 'Worker'));
        $cancel = ['reason' => 'x', 'recorded_at' => $this->at(0), 'idempotency_key' => $this->key()];
        $this->postJson('/api/v1/sales/'.$sale['id'].'/cancel', $cancel + ['reverse_payments' => true])->assertForbidden();
        $this->assertSame('active', Sale::firstOrFail()->status);
        $this->assertSame(1, Payment::count());
    }

    // ------------------------------------------------------------------ workers

    public function test_a_worker_can_sell_and_collect_payment_but_cannot_cancel_or_touch_money_documents(): void
    {
        $item = $this->produce();
        $loc = $this->store();
        $this->receive($item, $loc, '30');
        $worker = $this->member(FarmRole::FarmWorker, name: 'Field worker');
        $this->signInAs($worker);
        $this->getJson('/api/v1/sales/sellables')->assertOk();
        $sale = $this->sale([['kind' => 'stock', 'inventory_item_id' => $item, 'storage_location_id' => $loc, 'quantity' => '10', 'unit_price' => '50']],
            ['buyer' => ['name' => 'Roadside Buyer', 'phone' => '0803'], 'payment' => $this->payNow('500')])->assertCreated()->json('data');
        $this->assertSame('paid', $sale['payment_status']);
        $this->assertSame($worker->id, Sale::firstOrFail()->created_by);
        $this->getJson('/api/v1/sales/'.$sale['id'])->assertOk();

        $cancel = ['reason' => 'x', 'recorded_at' => $this->at(0), 'idempotency_key' => $this->key()];
        $this->postJson('/api/v1/sales/'.$sale['id'].'/cancel', $cancel)->assertForbidden();
        $this->postJson('/api/v1/invoices/'.$sale['invoice_id'].'/void', ['reason' => 'x', 'idempotency_key' => $this->key()])->assertForbidden();
        $this->postJson('/api/v1/invoices/'.$sale['invoice_id'].'/payments', ['amount' => '1', 'method' => 'cash', 'received_on' => '2026-10-14', 'idempotency_key' => $this->key()])->assertForbidden();
        $this->postJson('/api/v1/payments/'.$sale['payments'][0]['id'].'/reverse', ['reason' => 'x', 'idempotency_key' => $this->key()])->assertForbidden();
        $this->getJson('/api/v1/finance/transactions')->assertForbidden();
        $this->getJson('/api/v1/invoices')->assertForbidden();
        $this->postJson('/api/v1/sales', ['invoice' => ['notes' => 'n'], 'recorded_at' => $this->at(1), 'idempotency_key' => $this->key(), 'items' => [$this->other()]])->assertForbidden(); // an explicit invoice block still needs invoice.create
    }
}
