<?php

namespace Tests\Feature\Sales;

use App\Enums\FarmRole;
use App\Events\Sales\InvoiceIssued;
use App\Events\Sales\PaymentRecorded;
use App\Events\Sales\SaleRecorded;
use App\Models\Contact;
use App\Models\CropType;
use App\Models\FinanceTransaction;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\OperationalRecord;
use App\Models\OperationType;
use App\Models\Payment;
use App\Models\PopulationMovement;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Species;
use App\Support\Finance\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\Team\TeamTestCase;

class SalesTest extends TeamTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // UTC/Lagos date expectations must not depend on the hour the suite runs.
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

    private function cycle(string $kind = 'livestock', int $population = 100): string
    {
        if ($kind === 'crop') {
            return $this->postJson('/api/v1/production-cycles', ['kind' => 'crop', 'name' => 'Maize '.Str::random(4), 'operation_type_id' => OperationType::where('code', 'crops')->firstOrFail()->id,
                'crop_type_id' => CropType::where('code', 'yam')->firstOrFail()->id, 'planting_material_type' => 'tuber', 'planting_unit_type' => 'heap', 'initial_planting_units' => 500, 'planting_date' => '2026-01-10'])->assertCreated()->json('data.id');
        }

        return $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => 'Layer flock '.Str::random(4), 'operation_type_id' => OperationType::where('code', 'poultry')->firstOrFail()->id,
            'species_id' => Species::where('code', 'chicken')->firstOrFail()->id, 'initial_population' => $population, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
    }

    private function population(string $cycle): int
    {
        return $this->getJson('/api/v1/production-cycles/'.$cycle)->assertOk()->json('data.livestock.current_population');
    }

    private function store(): string
    {
        return $this->postJson('/api/v1/storage-locations', ['name' => 'Store '.Str::random(5), 'type' => 'store'])->assertCreated()->json('data.id');
    }

    private function produce(string $unit = 'piece', array $extra = [], string $category = 'produce'): string
    {
        return $this->postJson('/api/v1/inventory/items', array_replace(['name' => 'Produce '.Str::random(6), 'category' => $category, 'stock_unit' => $unit], $extra))->assertCreated()->json('data.id');
    }

    private function receive(string $item, string $loc, string $qty, string $unit, array $extra = []): void
    {
        $this->postJson('/api/v1/inventory/stock-in', array_replace(['inventory_item_id' => $item, 'storage_location_id' => $loc, 'reason' => 'opening_balance', 'components' => [['quantity' => $qty, 'unit' => $unit]],
            'recorded_at' => $this->at(10), 'idempotency_key' => $this->key()], $extra))->assertCreated();
    }

    private function stock(string $item): string
    {
        return $this->getJson('/api/v1/inventory/items/'.$item)->assertOk()->json('data.stock.quantity');
    }

    private function customer(string $name = 'Mama Put Restaurant', array $roles = ['customer']): string
    {
        return $this->postJson('/api/v1/contacts', ['name' => $name, 'kind' => 'business', 'roles' => $roles, 'phone' => '08030000000', 'address' => '12 Market Road'])->assertCreated()->json('data.id');
    }

    private function stockLine(string $item, string $loc, string $qty, string $unit, string $amount, array $extra = []): array
    {
        return array_replace(['kind' => 'stock', 'inventory_item_id' => $item, 'storage_location_id' => $loc, 'components' => [['quantity' => $qty, 'unit' => $unit]], 'amount' => $amount], $extra);
    }

    private function liveLine(string $cycle, int $heads, string $amount, array $extra = []): array
    {
        return array_replace(['kind' => 'livestock', 'production_cycle_id' => $cycle, 'head_count' => $heads, 'amount' => $amount], $extra);
    }

    private function sale(array $items, array $extra = [])
    {
        return $this->postJson('/api/v1/sales', array_replace(['recorded_at' => $this->at(2), 'idempotency_key' => $this->key(), 'items' => $items], $extra));
    }

    private function invoiceFor(string $sale, array $extra = [])
    {
        return $this->postJson('/api/v1/sales/'.$sale.'/invoice', array_replace(['idempotency_key' => $this->key()], $extra));
    }

    private function pay(string $invoice, string $amount, array $extra = [])
    {
        return $this->postJson('/api/v1/invoices/'.$invoice.'/payments', array_replace(['amount' => $amount, 'method' => 'bank_transfer', 'received_on' => $this->today(), 'idempotency_key' => $this->key()], $extra));
    }

    private function cancelSale(string $sale, array $extra = [])
    {
        return $this->postJson('/api/v1/sales/'.$sale.'/cancel', array_replace(['reason' => 'Buyer returned goods', 'recorded_at' => $this->at(0), 'idempotency_key' => $this->key()], $extra));
    }

    /** A paid-for-later sale of 100 eggs with an invoice: [sale, invoice, item, location]. */
    private function invoicedEggSale(string $amount = '3000.00'): array
    {
        $eggs = $this->produce();
        $loc = $this->store();
        $this->receive($eggs, $loc, '1000', 'piece');
        $sale = $this->sale([$this->stockLine($eggs, $loc, '100', 'piece', $amount)], ['contact_id' => $this->customer('Egg Buyer '.Str::random(4))])->assertCreated()->json('data');
        $invoice = $this->invoiceFor($sale['id'])->assertCreated()->json('data');

        return [$sale, $invoice, $eggs, $loc];
    }

    private function summary(string $query = ''): array
    {
        return $this->getJson('/api/v1/finance/summary'.$query)->assertOk()->json('data');
    }

    // ------------------------------------------------------------------ customers

    public function test_the_phase_14_contact_is_the_customer_and_walk_in_sales_need_no_contact(): void
    {
        $eggs = $this->produce();
        $loc = $this->store();
        $this->receive($eggs, $loc, '300', 'piece');
        $both = $this->customer('Mama Ngozi Farms', ['supplier', 'customer']);
        $sale = $this->sale([$this->stockLine($eggs, $loc, '30', 'piece', '900')], ['contact_id' => $both])->assertCreated()->json('data');
        $this->assertSame($both, $sale['contact_id']);
        $this->assertSame('Mama Ngozi Farms', $sale['customer_name']);
        $this->assertSame(1, Contact::where('farm_id', $this->farm->id)->count()); // no second customer system

        $walkIn = $this->sale([$this->stockLine($eggs, $loc, '30', 'piece', '900')], ['customer_name' => '  Aunty   Bisi '])->assertCreated()->json('data');
        $this->assertNull($walkIn['contact_id']);
        $this->assertSame('Aunty Bisi', $walkIn['customer_name']);
        $this->sale([$this->stockLine($eggs, $loc, '30', 'piece', '900')], ['contact_id' => $both, 'customer_name' => 'Someone'])->assertStatus(422)->assertJsonValidationErrors('customer_name');

        $supplierOnly = $this->customer('Feed Mill Ltd', ['supplier']);
        $this->sale([$this->stockLine($eggs, $loc, '1', 'piece', '30')], ['contact_id' => $supplierOnly])->assertStatus(422)->assertJsonValidationErrors('contact_id');
        $this->patchJson('/api/v1/contacts/'.$both, ['is_active' => false])->assertOk();
        $this->sale([$this->stockLine($eggs, $loc, '1', 'piece', '30')], ['contact_id' => $both])->assertStatus(409)->assertJsonPath('code', 'contact_inactive');
        $this->patchJson('/api/v1/contacts/'.$both, ['is_active' => true])->assertOk();
        // A customer with sales keeps the role.
        $this->patchJson('/api/v1/contacts/'.$both, ['roles' => ['supplier']])->assertStatus(409)->assertJsonPath('code', 'contact_in_use');
        $this->assertSame(240, (int) $this->stock($eggs)); // 300 - 30 - 30; failed sales left nothing behind
    }

    // ------------------------------------------------------------------ produce / inventory

    public function test_produce_sale_issues_one_stock_out_per_line_and_books_no_income_or_invoice(): void
    {
        $eggs = $this->produce();
        $loc = $this->store();
        $this->receive($eggs, $loc, '500', 'piece');
        Event::fake([SaleRecorded::class]);
        $sale = $this->sale([$this->stockLine($eggs, $loc, '120', 'piece', '3600.50')], ['contact_id' => $this->customer()])->assertCreated()->json('data');
        Event::assertDispatched(SaleRecorded::class, 1);

        $this->assertSame('380', $this->stock($eggs));
        $this->assertSame('active', $sale['status']);
        $this->assertSame('3600.50', $sale['total_amount']);
        $this->assertSame('uninvoiced', $sale['payment_status']);
        $this->assertNull($sale['invoice']);
        $this->assertMatchesRegularExpression('/^SAL-\d{4}-00001$/', $sale['reference']);
        $this->assertSame('crop_sales', $sale['finance_category']['code']);

        $line = $sale['items'][0];
        $movement = InventoryMovement::findOrFail($line['inventory_movement_id']);
        $this->assertSame($sale['id'], $movement->sale_id);
        $this->assertSame($line['id'], $movement->sale_item_id);
        $this->assertSame('stock_out', $movement->type->value);
        $this->assertSame('sale', $movement->reason);
        $this->assertSame('-120', rtrim(rtrim((string) $movement->quantity_delta, '0'), '.'));
        $this->assertSame(1, InventoryMovement::where('sale_id', $sale['id'])->count());
        $this->assertSame(0, FinanceTransaction::count()); // a sale is not income
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Payment::count());
        $movements = $this->getJson('/api/v1/inventory/movements?sale_id='.$sale['id'])->assertOk()->json('data');
        $this->assertCount(1, $movements);
        $this->assertSame($sale['id'], $movements[0]['sale_id']);
    }

    public function test_package_conversion_sums_components_through_the_item_context(): void
    {
        $maize = $this->produce('kg');
        $loc = $this->store();
        $this->receive($maize, $loc, '500', 'kg');
        $this->postJson('/api/v1/settings/package-conversions', ['context_type' => 'inventory_item', 'context_id' => $maize, 'package_unit' => 'bag', 'target_unit' => 'kg', 'quantity_per_package' => '25'])->assertCreated();
        $line = $this->stockLine($maize, $loc, '2', 'bag', '4000');
        $line['components'][] = ['quantity' => '5', 'unit' => 'kg'];
        $sale = $this->sale([$line])->assertCreated()->json('data');
        $this->assertSame('445', $this->stock($maize)); // 500 - (2 x 25 + 5)
        $this->assertSame('55', $sale['items'][0]['quantity']['quantity']);
        $this->assertSame('kg', $sale['items'][0]['quantity']['unit']);
        // No conversion for another item: bags are ambiguous there.
        $other = $this->produce('kg');
        $this->receive($other, $loc, '100', 'kg');
        $this->sale([$this->stockLine($other, $loc, '1', 'bag', '100')])->assertStatus(422);
        $this->assertSame('100', $this->stock($other));
    }

    public function test_only_sellable_categories_can_be_sold_and_insufficient_stock_rolls_everything_back(): void
    {
        $feed = $this->produce('kg', [], 'medicine');
        $eggs = $this->produce();
        $loc = $this->store();
        $this->receive($feed, $loc, '50', 'kg');
        $this->receive($eggs, $loc, '40', 'piece');
        $this->sale([$this->stockLine($feed, $loc, '5', 'kg', '100')])->assertStatus(422)->assertJsonValidationErrors('items.0.inventory_item_id');

        $cycle = $this->cycle();
        $this->sale([$this->stockLine($eggs, $loc, '30', 'piece', '900'), $this->stockLine($eggs, $loc, '30', 'piece', '900'), $this->liveLine($cycle, 5, '25000')])
            ->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->assertSame('40', $this->stock($eggs));
        $this->assertSame(100, $this->population($cycle));
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SaleItem::count());
        $this->assertSame(0, OperationalRecord::where('type', 'livestock_sale')->count());
        $this->assertSame(0, InventoryMovement::whereNotNull('sale_id')->count());
    }

    public function test_lot_and_location_rules_apply_to_sold_stock(): void
    {
        $tomato = $this->produce('kg', ['tracks_lots' => true, 'tracks_expiry' => true]);
        $a = $this->store();
        $b = $this->store();
        $fresh = now()->addDays(10)->toDateString();
        $this->receive($tomato, $a, '100', 'kg', ['lot' => ['code' => 'T-1', 'expires_on' => $fresh]]);
        $this->receive($tomato, $b, '5', 'kg', ['lot' => ['code' => 'T-1', 'expires_on' => $fresh]]);
        $lot = $this->getJson('/api/v1/inventory/lots?inventory_item_id='.$tomato)->assertOk()->json('data.0.id');

        $this->sale([$this->stockLine($tomato, $a, '10', 'kg', '500')])->assertStatus(422)->assertJsonValidationErrors('items.0.lot_id');
        $this->sale([$this->stockLine($tomato, $b, '10', 'kg', '500', ['lot_id' => $lot])])->assertStatus(409)->assertJsonPath('code', 'insufficient_stock'); // 5 kg in store B
        $sale = $this->sale([$this->stockLine($tomato, $a, '10', 'kg', '500', ['lot_id' => $lot])])->assertCreated()->json('data');
        $this->assertSame($lot, $sale['items'][0]['inventory_lot_id']);
        $this->assertSame('T-1', $sale['items'][0]['lot']['code']);
        $this->assertSame('95', $this->stock($tomato));
        $this->assertSame('90', collect($this->getJson('/api/v1/inventory/items/'.$tomato)->json('data.stock.balances') ?? [])->firstWhere('storage_location_id', $a)['quantity'] ?? '90');

        // Items that do not track lots refuse one; expired lots cannot be sold.
        $eggs = $this->produce();
        $this->receive($eggs, $a, '10', 'piece');
        $this->sale([$this->stockLine($eggs, $a, '1', 'piece', '30', ['lot_id' => $lot])])->assertStatus(422)->assertJsonValidationErrors('items.0.lot_id');
        $old = $this->produce('kg', ['tracks_lots' => true, 'tracks_expiry' => true]);
        $this->receive($old, $a, '10', 'kg', ['lot' => ['code' => 'OLD', 'expires_on' => now()->addDay()->toDateString()]]);
        $oldLot = $this->getJson('/api/v1/inventory/lots?inventory_item_id='.$old)->json('data.0.id');
        $this->travel(3)->days();
        $this->sale([$this->stockLine($old, $a, '1', 'kg', '30', ['lot_id' => $oldLot])], ['recorded_at' => now()->utc()->subHour()->format('Y-m-d\TH:i:s\Z')])->assertStatus(409)->assertJsonPath('code', 'lot_expired');
        $this->travelBack();
    }

    // ------------------------------------------------------------------ livestock / population

    public function test_livestock_sale_reduces_population_through_the_ledger_and_never_edits_it(): void
    {
        $cycle = $this->cycle();
        $sale = $this->sale([$this->liveLine($cycle, 12, '96000.00', ['description' => 'Spent layers'])], ['contact_id' => $this->customer()])->assertCreated()->json('data');
        $this->assertSame(88, $this->population($cycle));
        $this->assertSame('livestock_sales', $sale['finance_category']['code']);
        $line = $sale['items'][0];
        $this->assertSame('Spent layers', $line['description']);
        $this->assertSame(12, $line['head_count']);

        $record = OperationalRecord::findOrFail($line['operational_record_id']);
        $this->assertSame('livestock_sale', $record->type);
        $this->assertSame(-12, $record->population_delta);
        $this->assertSame($sale['id'], $record->details['sale_id']);
        $movement = PopulationMovement::where('operational_record_id', $record->id)->firstOrFail();
        $this->assertSame(-12, $movement->quantity);
        $this->assertSame($cycle, $movement->production_cycle_id);
        $this->assertSame(0, InventoryMovement::count()); // animals leave through the population ledger, not stock
        $this->assertSame(0, FinanceTransaction::count());
        $this->assertSame(1, PopulationMovement::where('production_cycle_id', $cycle)->where('quantity', '<', 0)->count());
    }

    public function test_livestock_sale_is_bounded_by_the_dated_population_and_the_cycle_kind(): void
    {
        $cycle = $this->cycle('livestock', 20);
        $this->sale([$this->liveLine($cycle, 21, '1000')])->assertStatus(409)->assertJsonPath('code', 'insufficient_population');
        $this->assertSame(20, $this->population($cycle));
        $this->assertSame(0, Sale::count());
        $this->sale([$this->liveLine($cycle, 20, '1000')])->assertCreated();
        $this->assertSame(0, $this->population($cycle));
        $this->sale([$this->liveLine($cycle, 1, '50')])->assertStatus(409)->assertJsonPath('code', 'insufficient_population');

        $crop = $this->cycle('crop');
        $this->sale([$this->liveLine($crop, 1, '50')])->assertStatus(422)->assertJsonValidationErrors('items.0.production_cycle_id');
        $this->sale([['kind' => 'livestock', 'amount' => '10', 'head_count' => 1]])->assertStatus(422)->assertJsonValidationErrors('items.0.production_cycle_id');
        $this->sale([$this->liveLine($cycle, 0, '10')])->assertStatus(422)->assertJsonValidationErrors('items.0.head_count');
        $this->sale([$this->liveLine($cycle, 1, '10', ['inventory_item_id' => (string) Str::uuid()])])->assertStatus(422)->assertJsonValidationErrors('items.0.inventory_item_id');
        $fresh = $this->cycle('livestock', 10);
        $this->sale([$this->liveLine($fresh, 1, '10')], ['recorded_at' => '2025-12-01T10:00:00Z'])->assertStatus(422)->assertJsonValidationErrors('recorded_at');
        // A back-dated sale cannot hide behind later population: the dated ledger must stay non-negative.
        $mortality = $this->postJson('/api/v1/records', ['production_cycle_id' => $fresh, 'type' => 'mortality', 'recorded_at' => $this->at(5), 'idempotency_key' => $this->key(), 'details' => ['quantity' => 6, 'cause' => 'Heat']])->assertCreated();
        $this->assertNotNull($mortality->json('data.id'));
        $this->sale([$this->liveLine($fresh, 5, '500')], ['recorded_at' => $this->at(2)])->assertStatus(409)->assertJsonPath('code', 'insufficient_population');
        $this->sale([$this->liveLine($fresh, 4, '400')], ['recorded_at' => $this->at(2)])->assertCreated();
        $this->assertSame(0, $this->population($fresh));
    }

    public function test_mixed_sale_with_other_lines_is_atomic_and_other_lines_have_no_physical_effect(): void
    {
        $eggs = $this->produce();
        $loc = $this->store();
        $this->receive($eggs, $loc, '100', 'piece');
        $cycle = $this->cycle();
        $sale = $this->sale([
            $this->stockLine($eggs, $loc, '60', 'piece', '1800.10'),
            $this->liveLine($cycle, 3, '15000.20'),
            ['kind' => 'other', 'description' => 'Manure (bags)', 'amount' => '500.05'],
        ])->assertCreated()->json('data');
        $this->assertSame('17300.35', $sale['total_amount']);
        $this->assertSame('other_income', $sale['finance_category']['code']); // mixed lines
        $this->assertSame('40', $this->stock($eggs));
        $this->assertSame(97, $this->population($cycle));
        $this->assertSame(1, InventoryMovement::count() - 1); // opening balance + the one sale stock-out
        $other = $sale['items'][2];
        $this->assertNull($other['inventory_movement_id']);
        $this->assertNull($other['operational_record_id']);
        $this->assertSame(1, OperationalRecord::where('type', 'livestock_sale')->count());

        $this->sale([['kind' => 'other', 'amount' => '5']])->assertStatus(422)->assertJsonValidationErrors('items.0.description');
        $this->sale([['kind' => 'other', 'description' => 'x', 'amount' => '5', 'head_count' => 2]])->assertStatus(422)->assertJsonValidationErrors('items.0.head_count');
        $this->sale([['kind' => 'other', 'description' => 'x', 'amount' => '5', 'inventory_item_id' => $eggs]])->assertStatus(422)->assertJsonValidationErrors('items.0.inventory_item_id');
        $this->sale([$this->stockLine($eggs, $loc, '1', 'piece', '5', ['head_count' => 1])])->assertStatus(422)->assertJsonValidationErrors('items.0.head_count');
        $this->sale([$this->stockLine($eggs, $loc, '1', 'piece', '5', ['description' => 'x'])])->assertStatus(422)->assertJsonValidationErrors('items.0.description');
    }

    // ------------------------------------------------------------------ money

    public function test_money_is_exact_and_floats_never_round(): void
    {
        $eggs = $this->produce();
        $loc = $this->store();
        $this->receive($eggs, $loc, '1000', 'piece');
        $lines = [];
        foreach (['0.10', '0.20', '0.30', '1234567.89', '9999999999999.99'] as $amount) {
            $lines[] = $this->stockLine($eggs, $loc, '1', 'piece', $amount);
        }
        $sale = $this->sale($lines)->assertCreated()->json('data');
        $this->assertSame(Money::sum(['0.10', '0.20', '0.30', '1234567.89', '9999999999999.99']), $sale['total_amount']);
        $this->assertSame('10000001234568.48', $sale['total_amount']);
        $this->assertIsString($sale['total_amount']);
        foreach (['10.005', '-5', '0', '0.00', 'abc', '1e3', '12345678901234567.00'] as $bad) {
            $this->sale([$this->stockLine($eggs, $loc, '1', 'piece', $bad)])->assertStatus(422)->assertJsonValidationErrors('items.0.amount');
        }
        $this->sale([$this->stockLine($eggs, $loc, '1', 'piece', '10.5')])->assertCreated()->assertJsonPath('data.total_amount', '10.50');
        $this->sale([array_replace($this->stockLine($eggs, $loc, '1', 'piece', '0'), ['amount' => 0.1])])->assertCreated()->assertJsonPath('data.total_amount', '0.10');
        $this->assertSame('993', $this->stock($eggs)); // 1000 - 5 - 1 - 1
    }

    // ------------------------------------------------------------------ invoices

    public function test_invoice_is_a_separate_snapshotted_document_with_one_live_invoice_per_sale(): void
    {
        $eggs = $this->produce();
        $loc = $this->store();
        $this->receive($eggs, $loc, '400', 'piece');
        $customer = $this->customer('Snapshot Buyer');
        $sale = $this->sale([$this->stockLine($eggs, $loc, '90', 'piece', '2700'), ['kind' => 'other', 'description' => 'Delivery', 'amount' => '300']], ['contact_id' => $customer])->assertCreated()->json('data');
        Event::fake([InvoiceIssued::class]);
        $invoice = $this->invoiceFor($sale['id'], ['due_date' => now('Africa/Lagos')->addDays(14)->toDateString(), 'notes' => 'Thank you'])->assertCreated()->json('data');
        Event::assertDispatched(InvoiceIssued::class, 1);

        $this->assertMatchesRegularExpression('/^INV-\d{4}-00001$/', $invoice['reference']);
        $this->assertSame('issued', $invoice['status']);
        $this->assertSame('unpaid', $invoice['payment_status']);
        $this->assertSame('3000.00', $invoice['total_amount']);
        $this->assertSame('3000.00', $invoice['outstanding']);
        $this->assertSame('0.00', $invoice['amount_paid']);
        $this->assertSame('Snapshot Buyer', $invoice['customer']['name']);
        $this->assertSame('08030000000', $invoice['customer']['phone']);
        $this->assertSame('Green Acres', $invoice['seller_name']);
        $this->assertSame('90 piece', $invoice['items'][0]['quantity_label']);
        $this->assertNull($invoice['items'][1]['quantity_label']);
        $this->assertSame($sale['id'], $invoice['sale_id']);
        $this->assertSame(0, FinanceTransaction::count()); // an invoice books nothing
        $this->assertSame($invoice['id'], $this->getJson('/api/v1/sales/'.$sale['id'])->json('data.invoice.id'));
        $this->assertSame('unpaid', $this->getJson('/api/v1/sales/'.$sale['id'])->json('data.payment_status'));

        // Later contact edits and product renames never touch the issued document.
        $this->patchJson('/api/v1/contacts/'.$customer, ['name' => 'Renamed Buyer', 'phone' => '09099999999', 'address' => 'Elsewhere'])->assertOk();
        $this->patchJson('/api/v1/inventory/items/'.$eggs, ['name' => 'Renamed produce'])->assertOk();
        $again = $this->getJson('/api/v1/invoices/'.$invoice['id'])->assertOk()->json('data');
        $this->assertSame('Snapshot Buyer', $again['customer']['name']);
        $this->assertSame('08030000000', $again['customer']['phone']);
        $this->assertSame($invoice['items'][0]['description'], $again['items'][0]['description']);
        $this->assertNotSame('Renamed produce', $again['items'][0]['description']);

        // One live invoice: a retry with the same key replays, another key is refused, and the database enforces it.
        $this->postJson('/api/v1/invoices', ['sale_id' => $sale['id'], 'idempotency_key' => $this->key()])->assertStatus(409)->assertJsonPath('code', 'invoice_exists');
        $this->assertSame(1, Invoice::count());
        $this->expectException(QueryException::class);
        Invoice::create(['farm_id' => $this->farm->id, 'sale_id' => $sale['id'], 'reference' => 'INV-X', 'status' => 'issued', 'live_sale_key' => $sale['id'], 'issue_date' => $this->today(),
            'seller_name' => 'x', 'total_amount' => '1.00', 'currency' => 'NGN', 'created_by' => $this->owner->id]);
    }

    public function test_invoice_idempotency_void_and_reissue(): void
    {
        [$sale, $invoice] = $this->invoicedEggSale();
        $key = $this->key();
        $void = $this->postJson('/api/v1/invoices/'.$invoice['id'].'/void', ['reason' => 'Wrong buyer', 'idempotency_key' => $key])->assertOk()->json('data');
        $this->assertSame('void', $void['status']);
        $this->assertSame('0.00', $void['outstanding']);
        $this->postJson('/api/v1/invoices/'.$invoice['id'].'/void', ['reason' => 'Wrong buyer', 'idempotency_key' => $key])->assertOk(); // replay
        $this->postJson('/api/v1/invoices/'.$invoice['id'].'/void', ['reason' => 'Different', 'idempotency_key' => $key])->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->postJson('/api/v1/invoices/'.$invoice['id'].'/void', ['reason' => 'again', 'idempotency_key' => $this->key()])->assertStatus(409)->assertJsonPath('code', 'invoice_already_void');
        $this->pay($invoice['id'], '10')->assertStatus(409)->assertJsonPath('code', 'invoice_void');
        $this->assertSame('active', $this->getJson('/api/v1/sales/'.$sale['id'])->json('data.status')); // voiding a document leaves the sale alone
        $this->assertSame('uninvoiced', $this->getJson('/api/v1/sales/'.$sale['id'])->json('data.payment_status'));

        $replacement = $this->invoiceFor($sale['id'])->assertCreated()->json('data');
        $this->assertNotSame($invoice['id'], $replacement['id']);
        $this->assertSame(2, Invoice::count());
        $this->assertSame(1, Invoice::where('status', 'issued')->count());

        $issueKey = $this->key();
        $second = $this->postJson('/api/v1/invoices', ['sale_id' => $this->sale([['kind' => 'other', 'description' => 'Service', 'amount' => '100']])->json('data.id'), 'idempotency_key' => $issueKey])->assertCreated()->json('data');
        $this->postJson('/api/v1/invoices', ['sale_id' => $second['sale_id'], 'idempotency_key' => $issueKey])->assertCreated()->assertJsonPath('data.id', $second['id']);
        $this->postJson('/api/v1/invoices', ['sale_id' => $second['sale_id'], 'idempotency_key' => $issueKey, 'notes' => 'changed'])->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame(3, Invoice::count());
        $this->postJson('/api/v1/invoices', ['idempotency_key' => $this->key()])->assertStatus(422)->assertJsonValidationErrors('sale_id');
        $free = $this->sale([['kind' => 'other', 'description' => 'Free', 'amount' => '10']])->json('data.id');
        $this->invoiceFor($free, ['due_date' => '2020-01-01'])->assertStatus(422)->assertJsonValidationErrors('due_date');
        $this->invoiceFor($free, ['issue_date' => now('Africa/Lagos')->addDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('issue_date');
    }

    public function test_save_and_create_invoice_is_one_transaction_with_two_distinct_documents(): void
    {
        $eggs = $this->produce();
        $loc = $this->store();
        $this->receive($eggs, $loc, '100', 'piece');
        $payload = ['recorded_at' => $this->at(1), 'idempotency_key' => $this->key(), 'contact_id' => $this->customer(), 'items' => [$this->stockLine($eggs, $loc, '10', 'piece', '300')],
            'invoice' => ['due_date' => now('Africa/Lagos')->addDays(7)->toDateString(), 'notes' => 'Net 7']];
        $sale = $this->postJson('/api/v1/sales', $payload)->assertCreated()->json('data');
        $this->assertSame('unpaid', $sale['payment_status']);
        $this->assertSame('300.00', $sale['outstanding']);
        $this->assertNotNull($sale['invoice']['id']);
        $this->assertSame(1, Sale::count());
        $this->assertSame(1, Invoice::count());
        $this->assertSame('Net 7', $this->getJson('/api/v1/invoices/'.$sale['invoice']['id'])->json('data.notes'));
        // Retry: no second sale, stock-out or invoice.
        $this->postJson('/api/v1/sales', $payload)->assertCreated()->assertJsonPath('data.id', $sale['id']);
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, InventoryMovement::where('sale_id', $sale['id'])->count());
        $this->assertSame('90', $this->stock($eggs));
        $payload['invoice']['notes'] = 'changed';
        $this->postJson('/api/v1/sales', $payload)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        // A failure while invoicing rolls the whole sale back.
        $bad = $payload;
        $bad['idempotency_key'] = $this->key();
        $bad['invoice'] = ['due_date' => '2001-01-01'];
        $this->postJson('/api/v1/sales', $bad)->assertStatus(422);
        $this->assertSame(1, Sale::count());
        $this->assertSame('90', $this->stock($eggs));
    }

    public function test_invoice_pdf_is_generated_from_the_snapshot(): void
    {
        [$sale, $invoice] = $this->invoicedEggSale('4500.75');
        $this->pay($invoice['id'], '1000.25')->assertCreated();
        $response = $this->get('/api/v1/invoices/'.$invoice['id'].'/pdf')->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString($invoice['reference'].'.pdf', $response->headers->get('Content-Disposition'));
        $body = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $body);
        $this->assertGreaterThan(1500, strlen($body));

        // The PDF view is built from the snapshot: render it and look for the figures.
        $html = view('invoices.pdf', [
            'invoice' => Invoice::with(['items', 'payments.reversal', 'sale'])->findOrFail($invoice['id']), 'paid' => '1000.25', 'outstanding' => '3500.50', 'status' => 'partially_paid', 'payments' => collect(),
            'money' => fn (string $a) => Money::format($a),
        ])->render();
        $this->assertStringContainsString($invoice['reference'], $html);
        $this->assertStringContainsString('4,500.75', $html);
        $this->assertStringContainsString('3,500.50', $html);
        $this->assertStringContainsString('partially paid', $html);
        $this->assertSame('1,234,567.80', Money::format('1234567.8'));
        $this->assertSame('0.05', Money::format('0.05'));

        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $this->get('/api/v1/invoices/'.$invoice['id'].'/pdf')->assertNotFound();
        $this->signInAs($this->member(FarmRole::FarmWorker));
        $this->get('/api/v1/invoices/'.$invoice['id'].'/pdf')->assertForbidden();
    }

    // ------------------------------------------------------------------ payments

    public function test_partial_and_multiple_payments_settle_the_invoice_and_each_books_one_income(): void
    {
        [$sale, $invoice] = $this->invoicedEggSale('1000.00');
        Event::fake([PaymentRecorded::class]);
        $first = $this->pay($invoice['id'], '250.10', ['method' => 'cash', 'payment_reference' => 'RCPT-1'])->assertCreated()->json('data');
        Event::assertDispatched(PaymentRecorded::class, 1);
        $this->assertSame('payment', $first['entry_type']);
        $this->assertMatchesRegularExpression('/^PAY-\d{4}-00001$/', $first['reference']);
        $this->assertSame('cash', $first['method']);

        $state = $this->getJson('/api/v1/invoices/'.$invoice['id'])->json('data');
        $this->assertSame('partially_paid', $state['payment_status']);
        $this->assertSame('250.10', $state['amount_paid']);
        $this->assertSame('749.90', $state['outstanding']);
        $this->assertSame('issued', $state['status']); // document state is independent of payment state
        $this->assertSame('partially_paid', $this->getJson('/api/v1/sales/'.$sale['id'])->json('data.payment_status'));

        $this->pay($invoice['id'], '749.91')->assertStatus(409)->assertJsonPath('code', 'payment_exceeds_balance')->assertJsonPath('details.outstanding', '749.90');
        $this->pay($invoice['id'], '0.00')->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->pay($invoice['id'], '10.005')->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->pay($invoice['id'], '100', ['method' => 'barter'])->assertStatus(422)->assertJsonValidationErrors('method');
        $this->pay($invoice['id'], '100', ['received_on' => now('Africa/Lagos')->addDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('received_on');
        $second = $this->pay($invoice['id'], '400.00')->assertCreated()->json('data');
        $third = $this->pay($invoice['id'], '349.90', ['method' => 'pos'])->assertCreated()->json('data');

        $final = $this->getJson('/api/v1/invoices/'.$invoice['id'])->json('data');
        $this->assertSame('paid', $final['payment_status']);
        $this->assertSame('1000.00', $final['amount_paid']);
        $this->assertSame('0.00', $final['outstanding']);
        $this->assertSame('paid', $this->getJson('/api/v1/sales/'.$sale['id'])->json('data.payment_status'));
        $this->pay($invoice['id'], '0.01')->assertStatus(409)->assertJsonPath('code', 'payment_exceeds_balance');

        // Exactly one income row per payment, source-linked, summing to the money received.
        $rows = FinanceTransaction::where('direction', 'income')->orderBy('recorded_at')->get();
        $this->assertCount(3, $rows);
        foreach ([$first, $second, $third] as $payment) {
            $row = FinanceTransaction::where('source_type', 'payment')->where('source_id', $payment['id'])->sole();
            $this->assertSame($row->id, $payment['finance_transaction_id']);
            $this->assertSame($payment['amount'], (string) $row->amount);
            $this->assertSame('crop_sales', $row->category->code);
            $this->assertSame($sale['contact_id'], $row->contact_id);
        }
        $this->assertSame('1000.00', $this->summary()['totals']['income']);
        $this->assertSame(3, Payment::count());
        $list = $this->getJson('/api/v1/payments?invoice_id='.$invoice['id'])->assertOk()->json('data');
        $this->assertCount(3, $list);
        $this->assertCount(1, $this->getJson('/api/v1/payments?method=pos')->json('data'));
        $this->assertCount(3, $this->getJson('/api/v1/finance/transactions?source_type=payment')->json('data'));
    }

    public function test_payments_are_idempotent_and_the_ledger_rejects_a_second_income_for_one_payment(): void
    {
        [, $invoice] = $this->invoicedEggSale('500.00');
        $key = $this->key();
        $body = ['amount' => '200.00', 'method' => 'cash', 'received_on' => $this->today(), 'idempotency_key' => $key];
        $first = $this->postJson('/api/v1/invoices/'.$invoice['id'].'/payments', $body)->assertCreated()->json('data');
        $this->postJson('/api/v1/invoices/'.$invoice['id'].'/payments', $body)->assertCreated()->assertJsonPath('data.id', $first['id']);
        $this->postJson('/api/v1/invoices/'.$invoice['id'].'/payments', ['amount' => '201.00'] + $body)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, FinanceTransaction::count());
        $this->assertSame('300.00', $this->getJson('/api/v1/invoices/'.$invoice['id'])->json('data.outstanding'));

        $this->expectException(QueryException::class);
        FinanceTransaction::create(['farm_id' => $this->farm->id, 'reference' => 'FIN-X', 'entry_type' => 'entry', 'direction' => 'income', 'finance_category_id' => FinanceTransaction::first()->finance_category_id,
            'amount' => '200.00', 'currency' => 'NGN', 'occurred_on' => $this->today(), 'recorded_at' => now(), 'source_type' => 'payment', 'source_id' => $first['id'], 'source_key' => 'payment:'.$first['id'], 'created_by' => $this->owner->id]);
    }

    public function test_payment_reversal_restores_the_balance_and_offsets_the_income_once(): void
    {
        [$sale, $invoice] = $this->invoicedEggSale('600.00');
        $payment = $this->pay($invoice['id'], '600.00')->assertCreated()->json('data');
        $this->assertSame('paid', $this->getJson('/api/v1/invoices/'.$invoice['id'])->json('data.payment_status'));

        // The ledger row of a payment can only be undone through the payment.
        $this->postJson('/api/v1/finance/transactions/'.$payment['finance_transaction_id'].'/reverse', ['reason' => 'x', 'idempotency_key' => $this->key()])->assertStatus(409)->assertJsonPath('code', 'reverse_via_payment');
        $key = $this->key();
        $reversal = $this->postJson('/api/v1/payments/'.$payment['id'].'/reverse', ['reason' => 'Cheque bounced', 'idempotency_key' => $key])->assertOk()->json('data');
        $this->assertSame('reversal', $reversal['entry_type']);
        $this->assertSame($payment['id'], $reversal['reverses_payment_id']);
        $this->assertSame('600.00', $reversal['amount']);
        $this->postJson('/api/v1/payments/'.$payment['id'].'/reverse', ['reason' => 'Cheque bounced', 'idempotency_key' => $key])->assertOk()->assertJsonPath('data.id', $reversal['id']);
        $this->postJson('/api/v1/payments/'.$payment['id'].'/reverse', ['reason' => 'Other', 'idempotency_key' => $key])->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->postJson('/api/v1/payments/'.$payment['id'].'/reverse', ['reason' => 'again', 'idempotency_key' => $this->key()])->assertStatus(409)->assertJsonPath('code', 'payment_already_reversed');
        $this->postJson('/api/v1/payments/'.$reversal['id'].'/reverse', ['reason' => 'x', 'idempotency_key' => $this->key()])->assertStatus(409)->assertJsonPath('code', 'payment_already_reversed');

        $state = $this->getJson('/api/v1/invoices/'.$invoice['id'])->json('data');
        $this->assertSame('unpaid', $state['payment_status']);
        $this->assertSame('600.00', $state['outstanding']);
        $this->assertSame('0.00', $this->summary()['totals']['income']);
        $this->assertSame(2, FinanceTransaction::count()); // entry + offsetting reversal row, nothing edited
        $this->assertSame(1, FinanceTransaction::where('entry_type', 'reversal')->count());
        $this->assertSame(1, FinanceTransaction::where('entry_type', 'reversal')->where('source_type', 'payment')->where('source_id', $payment['id'])->count());
        $this->assertTrue($this->getJson('/api/v1/payments/'.$payment['id'])->json('data.is_reversed'));

        // The money can be received again (a correction is a new payment), still one income per real payment.
        $this->pay($invoice['id'], '600.00')->assertCreated();
        $this->assertSame('600.00', $this->summary()['totals']['income']);
        $this->assertSame('paid', $this->getJson('/api/v1/sales/'.$sale['id'])->json('data.payment_status'));
        $this->expectException(LogicException::class);
        Payment::findOrFail($payment['id'])->update(['amount' => '1.00']);
    }

    public function test_payment_income_is_allocated_to_a_single_open_cycle_only(): void
    {
        $cycle = $this->cycle();
        $sale = $this->sale([$this->liveLine($cycle, 10, '50000')], ['contact_id' => $this->customer('Cycle Buyer')])->assertCreated()->json('data');
        $invoice = $this->invoiceFor($sale['id'])->assertCreated()->json('data');
        $paid = $this->pay($invoice['id'], '20000')->assertCreated()->json('data');
        $this->assertSame($cycle, FinanceTransaction::findOrFail($paid['finance_transaction_id'])->production_cycle_id);
        $this->assertSame('livestock_sales', FinanceTransaction::findOrFail($paid['finance_transaction_id'])->category->code);
        $profit = $this->summary('?production_cycle_id='.$cycle);
        $this->assertSame('20000.00', $profit['totals']['income']);

        // Collecting money after the cycle closed stays possible; it simply is not allocated to the closed cycle.
        $this->postJson('/api/v1/production-cycles/'.$cycle.'/close', ['end_date' => $this->today(), 'reason' => 'Sold out'])->assertOk();
        $late = $this->pay($invoice['id'], '10000')->assertCreated()->json('data');
        $this->assertNull(FinanceTransaction::findOrFail($late['finance_transaction_id'])->production_cycle_id);
        $this->assertSame('20000.00', $this->summary('?production_cycle_id='.$cycle)['totals']['income']);
        // ...but unwinding an allocated payment into a closed cycle is protected.
        $this->postJson('/api/v1/payments/'.$paid['id'].'/reverse', ['reason' => 'x', 'idempotency_key' => $this->key()])->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
        $this->postJson('/api/v1/payments/'.$late['id'].'/reverse', ['reason' => 'x', 'idempotency_key' => $this->key()])->assertOk();

        // Lines over two cycles are not guessed into either.
        $other = $this->cycle();
        $two = $this->sale([$this->liveLine($other, 1, '100'), $this->liveLine($this->cycle(), 1, '100')])->assertCreated()->json('data');
        $inv = $this->invoiceFor($two['id'])->assertCreated()->json('data');
        $pay = $this->pay($inv['id'], '50')->assertCreated()->json('data');
        $this->assertNull(FinanceTransaction::findOrFail($pay['finance_transaction_id'])->production_cycle_id);
        // A category override must be an income category.
        $this->pay($inv['id'], '10', ['finance_category_id' => $this->getJson('/api/v1/finance/categories?direction=expense')->json('data.0.id')])->assertStatus(422);
        $egg = collect($this->getJson('/api/v1/finance/categories?direction=income')->json('data'))->firstWhere('code', 'egg_sales')['id'];
        $override = $this->pay($inv['id'], '10', ['finance_category_id' => $egg])->assertCreated()->json('data');
        $this->assertSame('egg_sales', FinanceTransaction::findOrFail($override['finance_transaction_id'])->category->code);
    }

    // ------------------------------------------------------------------ cancellation / reversal

    public function test_cancelling_a_sale_compensates_stock_and_population_through_their_ledgers(): void
    {
        $eggs = $this->produce();
        $loc = $this->store();
        $this->receive($eggs, $loc, '200', 'piece');
        $cycle = $this->cycle();
        $sale = $this->sale([$this->stockLine($eggs, $loc, '80', 'piece', '2400'), $this->liveLine($cycle, 9, '45000')], ['contact_id' => $this->customer(), 'invoice' => []])->assertCreated()->json('data');
        $this->assertSame('120', $this->stock($eggs));
        $this->assertSame(91, $this->population($cycle));
        $this->assertSame('unpaid', $sale['payment_status']);

        $key = $this->key();
        $when = $this->at(0);
        $cancelled = $this->cancelSale($sale['id'], ['idempotency_key' => $key, 'recorded_at' => $when])->assertOk()->json('data');
        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertSame('200', $this->stock($eggs));
        $this->assertSame(100, $this->population($cycle));
        $this->assertNull($cancelled['invoice']); // the unpaid invoice was voided with the sale
        $this->assertSame('void', Invoice::sole()->status);
        $this->assertNotNull($cancelled['items'][0]['inventory_reversal_movement_id']);
        $this->assertNotNull($cancelled['items'][1]['reversal_record_id']);

        // History is preserved: original rows remain, compensating rows were appended.
        $this->assertSame(1, Sale::count());
        $this->assertSame(1, InventoryMovement::where('sale_id', $sale['id'])->where('type', 'stock_out')->count());
        $this->assertSame(1, InventoryMovement::where('sale_id', $sale['id'])->where('type', 'reversal')->count());
        $this->assertSame(2, OperationalRecord::whereIn('type', ['livestock_sale', 'reversal'])->where('production_cycle_id', $cycle)->count());
        $this->assertSame([-9, 9], PopulationMovement::where('production_cycle_id', $cycle)->where('source_key', '!=', 'initial')->orderBy('recorded_at')->orderBy('id')->pluck('quantity')->all());
        $this->assertSame(0, FinanceTransaction::count());

        // Replay, changed payload, already cancelled.
        $this->cancelSale($sale['id'], ['idempotency_key' => $key, 'recorded_at' => $when])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->cancelSale($sale['id'], ['idempotency_key' => $key, 'recorded_at' => $when, 'reason' => 'different'])->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->cancelSale($sale['id'])->assertStatus(409)->assertJsonPath('code', 'sale_already_cancelled');
        $this->assertSame('200', $this->stock($eggs));
        $this->invoiceFor($sale['id'])->assertStatus(409)->assertJsonPath('code', 'sale_cancelled');
        $this->assertSame(100, $this->population($cycle));
    }

    public function test_cancelling_never_pretends_money_was_refunded(): void
    {
        [$sale, $invoice, $eggs] = $this->invoicedEggSale('900.00');
        $payment = $this->pay($invoice['id'], '300.00')->assertCreated()->json('data');
        $this->cancelSale($sale['id'])->assertStatus(409)->assertJsonPath('code', 'sale_has_payments')->assertJsonPath('details.amount_paid', '300.00');
        $this->postJson('/api/v1/invoices/'.$invoice['id'].'/void', ['reason' => 'x', 'idempotency_key' => $this->key()])->assertStatus(409)->assertJsonPath('code', 'invoice_has_payments');
        $this->assertSame('900', $this->stock($eggs)); // the refused cancel changed nothing
        $this->assertSame('active', $this->getJson('/api/v1/sales/'.$sale['id'])->json('data.status'));
        $this->assertSame(0, InventoryMovement::where('type', 'reversal')->count());

        // Money is unwound explicitly, then the goods; the two stay independent records.
        $this->postJson('/api/v1/payments/'.$payment['id'].'/reverse', ['reason' => 'Refunded in cash', 'idempotency_key' => $this->key()])->assertOk();
        $this->cancelSale($sale['id'])->assertOk();
        $this->assertSame('1000', $this->stock($eggs));
        $this->assertSame('0.00', $this->summary()['totals']['income']); // +300 entry, -300 reversal
        $this->assertSame(2, FinanceTransaction::count());
    }

    public function test_impossible_reversals_are_refused_and_sale_effects_are_not_reversible_elsewhere(): void
    {
        $eggs = $this->produce();
        $loc = $this->store();
        $this->receive($eggs, $loc, '50', 'piece');
        $cycle = $this->cycle();
        $sale = $this->sale([$this->stockLine($eggs, $loc, '20', 'piece', '600'), $this->liveLine($cycle, 4, '20000')])->assertCreated()->json('data');

        $this->postJson('/api/v1/inventory/movements/'.$sale['items'][0]['inventory_movement_id'].'/reverse', ['reason' => 'x', 'recorded_at' => $this->at(0), 'idempotency_key' => $this->key()])
            ->assertStatus(409)->assertJsonPath('code', 'reverse_via_sale');
        $this->postJson('/api/v1/records/'.$sale['items'][1]['operational_record_id'].'/reverse', ['reason' => 'x', 'recorded_at' => $this->at(0), 'idempotency_key' => $this->key()])
            ->assertStatus(409)->assertJsonPath('code', 'reverse_via_sale');

        // Animals cannot come back into a closed cycle.
        $this->postJson('/api/v1/production-cycles/'.$cycle.'/close', ['end_date' => $this->today(), 'reason' => 'Done'])->assertOk();
        $this->cancelSale($sale['id'])->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
        $this->assertSame('30', $this->stock($eggs));
        $this->assertSame('active', $this->getJson('/api/v1/sales/'.$sale['id'])->json('data.status'));
        $this->assertSame(0, InventoryMovement::where('type', 'reversal')->count());
        $this->postJson('/api/v1/production-cycles/'.$cycle.'/reopen', ['reason' => 'Correcting a sale'])->assertOk();
        $this->cancelSale($sale['id'])->assertOk();
        $this->assertSame('50', $this->stock($eggs));

        $fresh = $this->sale([['kind' => 'other', 'description' => 'Fresh', 'amount' => '5']])->json('data.id');
        $this->cancelSale($fresh, ['recorded_at' => now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z')])->assertStatus(422)->assertJsonValidationErrors('recorded_at');
        $this->cancelSale($fresh, ['recorded_at' => '2020-01-01T00:00:00Z'])->assertStatus(422)->assertJsonValidationErrors('recorded_at');
    }

    public function test_a_cancelled_sale_can_be_replaced_once(): void
    {
        $eggs = $this->produce();
        $loc = $this->store();
        $this->receive($eggs, $loc, '100', 'piece');
        $sale = $this->sale([$this->stockLine($eggs, $loc, '10', 'piece', '300')])->assertCreated()->json('data');
        $this->sale([$this->stockLine($eggs, $loc, '10', 'piece', '300')], ['corrects_sale_id' => $sale['id']])->assertStatus(409)->assertJsonPath('code', 'invalid_correction');
        $this->cancelSale($sale['id'])->assertOk();
        $fixed = $this->sale([$this->stockLine($eggs, $loc, '12', 'piece', '360')], ['corrects_sale_id' => $sale['id']])->assertCreated()->json('data');
        $this->assertSame($sale['id'], $fixed['corrects_sale_id']);
        $this->sale([$this->stockLine($eggs, $loc, '12', 'piece', '360')], ['corrects_sale_id' => $sale['id']])->assertStatus(409)->assertJsonPath('code', 'invalid_correction');
        $this->assertSame('88', $this->stock($eggs));
    }

    // ------------------------------------------------------------------ idempotency

    public function test_sale_retries_never_duplicate_effects_and_changed_payloads_conflict(): void
    {
        $eggs = $this->produce();
        $loc = $this->store();
        $this->receive($eggs, $loc, '100', 'piece');
        $cycle = $this->cycle();
        $payload = ['recorded_at' => $this->at(1), 'idempotency_key' => $this->key(), 'items' => [$this->stockLine($eggs, $loc, '10', 'piece', '300'), $this->liveLine($cycle, 2, '10000')]];
        $first = $this->postJson('/api/v1/sales', $payload)->assertCreated()->json('data');
        $this->postJson('/api/v1/sales', $payload)->assertCreated()->assertJsonPath('data.id', $first['id']);
        $this->assertSame(1, Sale::count());
        $this->assertSame(2, SaleItem::count());
        $this->assertSame(1, InventoryMovement::where('sale_id', $first['id'])->count());
        $this->assertSame(1, OperationalRecord::where('type', 'livestock_sale')->count());
        $this->assertSame('90', $this->stock($eggs));
        $this->assertSame(98, $this->population($cycle));

        $changed = $payload;
        $changed['items'][0]['amount'] = '301';
        $this->postJson('/api/v1/sales', $changed)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        // A failed attempt does not burn the key.
        $fail = ['recorded_at' => $this->at(1), 'idempotency_key' => $this->key(), 'items' => [$this->stockLine($eggs, $loc, '9999', 'piece', '300')]];
        $this->postJson('/api/v1/sales', $fail)->assertStatus(409);
        $fail['items'][0]['components'][0]['quantity'] = '5';
        $this->postJson('/api/v1/sales', $fail)->assertCreated();

        // The database itself blocks a second stock-out per line and a second population record per line.
        $this->expectException(QueryException::class);
        InventoryMovement::create(['farm_id' => $this->farm->id, 'inventory_item_id' => $eggs, 'storage_location_id' => $loc, 'type' => 'stock_out', 'reason' => 'sale', 'quantity_delta' => '-1',
            'measurement' => [], 'recorded_at' => now(), 'created_by' => $this->owner->id, 'sale_id' => $first['id'], 'sale_item_id' => $first['items'][0]['id']]);
    }

    // ------------------------------------------------------------------ isolation / permissions / listing

    public function test_other_farms_cannot_see_or_touch_sales_invoices_and_payments(): void
    {
        [$sale, $invoice, $eggs, $loc] = $this->invoicedEggSale();
        $payment = $this->pay($invoice['id'], '100')->assertCreated()->json('data');
        $cycle = $this->cycle();
        $customer = $sale['contact_id'];

        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $this->getJson('/api/v1/sales/'.$sale['id'])->assertNotFound();
        $this->getJson('/api/v1/invoices/'.$invoice['id'])->assertNotFound();
        $this->get('/api/v1/invoices/'.$invoice['id'].'/pdf')->assertNotFound();
        $this->getJson('/api/v1/payments/'.$payment['id'])->assertNotFound();
        $this->cancelSale($sale['id'])->assertNotFound();
        $this->invoiceFor($sale['id'])->assertNotFound();
        $this->postJson('/api/v1/invoices', ['sale_id' => $sale['id'], 'idempotency_key' => $this->key()])->assertNotFound();
        $this->pay($invoice['id'], '10')->assertNotFound();
        $this->postJson('/api/v1/invoices/'.$invoice['id'].'/void', ['reason' => 'x', 'idempotency_key' => $this->key()])->assertNotFound();
        $this->postJson('/api/v1/payments/'.$payment['id'].'/reverse', ['reason' => 'x', 'idempotency_key' => $this->key()])->assertNotFound();
        foreach (['/sales', '/invoices', '/payments'] as $url) {
            $this->assertSame([], $this->getJson('/api/v1'.$url)->json('data'));
        }
        $this->assertSame('0.00', $this->summary()['totals']['income']);
        // Foreign customer, stock, location and cycle ids do not leak: plain 404s.
        $mine = $this->produce();
        $myStore = $this->store();
        $this->receive($mine, $myStore, '10', 'piece');
        $this->sale([$this->stockLine($mine, $myStore, '1', 'piece', '10')], ['contact_id' => $customer])->assertNotFound();
        $this->sale([$this->stockLine($eggs, $myStore, '1', 'piece', '10')])->assertNotFound();
        $this->sale([$this->stockLine($mine, $loc, '1', 'piece', '10')])->assertNotFound();
        $this->sale([$this->liveLine($cycle, 1, '10')])->assertNotFound();
        $this->sale([['kind' => 'other', 'description' => 'x', 'amount' => '5', 'production_cycle_id' => $cycle]])->assertNotFound();
        $this->getJson('/api/v1/inventory/movements?sale_id='.$sale['id'])->assertOk()->assertJsonPath('data', []);

        $this->signInAs($this->owner);
        $this->assertSame('active', $this->getJson('/api/v1/sales/'.$sale['id'])->json('data.status'));
        $this->assertSame('900', $this->stock($eggs));
    }

    public function test_finance_and_manager_can_sell_but_workers_and_vets_cannot(): void
    {
        [$sale, $invoice] = $this->invoicedEggSale();
        $payment = $this->pay($invoice['id'], '100')->assertCreated()->json('data');
        foreach ([FarmRole::FarmWorker, FarmRole::Vet] as $role) {
            $this->signInAs($this->member($role, name: $role->label()));
            foreach (['/sales', '/sales/'.$sale['id'], '/invoices', '/invoices/'.$invoice['id'], '/invoices/'.$invoice['id'].'/pdf', '/payments', '/payments/'.$payment['id']] as $url) {
                $this->get('/api/v1'.$url, ['Accept' => 'application/json'])->assertForbidden();
            }
            $this->sale([['kind' => 'other', 'description' => 'x', 'amount' => '5']])->assertForbidden();
            $this->cancelSale($sale['id'])->assertForbidden();
            $this->invoiceFor($sale['id'])->assertForbidden();
            $this->postJson('/api/v1/invoices', ['sale_id' => $sale['id'], 'idempotency_key' => $this->key()])->assertForbidden();
            $this->postJson('/api/v1/invoices/'.$invoice['id'].'/void', ['reason' => 'x', 'idempotency_key' => $this->key()])->assertForbidden();
            $this->pay($invoice['id'], '10')->assertForbidden();
            $this->postJson('/api/v1/payments/'.$payment['id'].'/reverse', ['reason' => 'x', 'idempotency_key' => $this->key()])->assertForbidden();
        }
        $this->assertSame(1, Sale::count());
        $this->assertSame(1, Payment::count());
        foreach ([FarmRole::Finance, FarmRole::Manager] as $role) {
            $this->signInAs($this->member($role, name: 'Sales '.$role->value));
            $this->getJson('/api/v1/sales/'.$sale['id'])->assertOk();
            $this->get('/api/v1/invoices/'.$invoice['id'].'/pdf')->assertOk();
            $this->sale([['kind' => 'other', 'description' => 'Consulting '.$role->value, 'amount' => '500']])->assertCreated();
            $this->pay($invoice['id'], '10')->assertCreated();
        }
    }

    public function test_client_cannot_inject_ownership_totals_status_or_balances(): void
    {
        $base = ['recorded_at' => $this->at(1), 'idempotency_key' => $this->key(), 'items' => [['kind' => 'other', 'description' => 'x', 'amount' => '5']]];
        foreach (['farm_id' => (string) Str::uuid(), 'created_by' => (string) Str::uuid(), 'total_amount' => '1', 'status' => 'cancelled', 'currency' => 'USD', 'reference' => 'SAL-X'] as $field => $value) {
            $this->postJson('/api/v1/sales', [$field => $value] + $base)->assertStatus(422)->assertJsonValidationErrors($field);
        }
        [, $invoice] = $this->invoicedEggSale();
        foreach (['farm_id' => (string) Str::uuid(), 'invoice_id' => (string) Str::uuid(), 'currency' => 'USD', 'entry_type' => 'reversal', 'reference' => 'PAY-X'] as $field => $value) {
            $this->pay($invoice['id'], '10', [$field => $value])->assertStatus(422)->assertJsonValidationErrors($field);
        }
        $this->assertSame(0, Payment::count());
    }

    public function test_listing_filters_and_derived_payment_status(): void
    {
        [$a, $invA] = $this->invoicedEggSale('1000.00');
        [$b, $invB] = $this->invoicedEggSale('2000.00');
        $c = $this->sale([['kind' => 'other', 'description' => 'Uninvoiced', 'amount' => '50']])->assertCreated()->json('data');
        $this->pay($invB['id'], '500')->assertCreated();
        $this->pay($invA['id'], '1000')->assertCreated();
        $d = $this->sale([['kind' => 'other', 'description' => 'Overdue job', 'amount' => '70']], ['contact_id' => $this->customer('Late Payer')])->assertCreated()->json('data');
        $overdue = $this->invoiceFor($d['id'], ['issue_date' => $this->today(), 'due_date' => $this->today()])->assertCreated()->json('data');
        $today = $this->today();
        $this->travel(2)->days();

        $ids = fn (string $q, string $url = '/api/v1/sales') => collect($this->getJson($url.$q)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$a['id']], $ids('?payment_status=paid'));
        $this->assertSame([$b['id']], $ids('?payment_status=partially_paid'));
        $this->assertSame([$d['id']], $ids('?payment_status=unpaid'));
        $this->assertSame([$c['id']], $ids('?payment_status=uninvoiced'));
        $this->assertCount(4, $ids(''));
        $this->assertSame([$overdue['id']], $ids('?overdue=1', '/api/v1/invoices'));
        $this->assertSame([$invA['id']], $ids('?payment_status=paid', '/api/v1/invoices'));
        $this->assertSame([$invB['id']], $ids('?payment_status=partially_paid', '/api/v1/invoices'));
        $this->assertSame([$overdue['id']], $ids('?search=Late', '/api/v1/invoices'));
        $this->assertTrue($this->getJson('/api/v1/invoices/'.$overdue['id'])->json('data.is_overdue'));
        $this->assertFalse($this->getJson('/api/v1/invoices/'.$invA['id'])->json('data.is_overdue'));
        $this->assertCount(2, $this->getJson('/api/v1/payments?from='.$today)->json('data'));
        $this->assertSame(0, count($this->getJson('/api/v1/payments?entry_type=reversal')->json('data')));
        $this->getJson('/api/v1/sales?payment_status=bogus')->assertStatus(422);
        $this->travelBack();
    }

    public function test_events_fire_only_after_commit_and_failed_sales_fire_none(): void
    {
        $eggs = $this->produce();
        $loc = $this->store();
        $this->receive($eggs, $loc, '5', 'piece');
        Event::fake([SaleRecorded::class]);
        $this->sale([$this->stockLine($eggs, $loc, '10', 'piece', '100')])->assertStatus(409);
        Event::assertNotDispatched(SaleRecorded::class);
        $this->sale([$this->stockLine($eggs, $loc, '1', 'piece', '10')])->assertCreated();
        Event::assertDispatchedTimes(SaleRecorded::class, 1);
    }

    public function test_sales_tables_are_append_only(): void
    {
        [$sale, $invoice] = $this->invoicedEggSale();
        $this->pay($invoice['id'], '10')->assertCreated();
        foreach ([Sale::class => ['notes' => 'edit'], SaleItem::class => ['amount' => '1'], Invoice::class => ['total_amount' => '1'], Payment::class => ['amount' => '1']] as $model => $change) {
            $row = $model::query()->firstOrFail();
            try {
                $row->update($change);
                $this->fail($model.' accepted an edit');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
            try {
                $row->delete();
                $this->fail($model.' accepted a delete');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame($sale['total_amount'], (string) Sale::sole()->total_amount);
    }
}
