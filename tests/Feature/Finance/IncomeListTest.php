<?php

namespace Tests\Feature\Finance;

use App\Enums\FarmRole;
use App\Models\InventoryMovement;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Team\TeamTestCase;

/** GET /finance/income: one row per sale + manual income rows (real UNION), money_status on every sale, typed product lines. */
class IncomeListTest extends TeamTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
        $this->signInAs($this->owner);
    }

    // ------------------------------------------------------------------ helpers

    private function key(): string
    {
        return (string) Str::uuid();
    }

    private function at(int $daysAgo = 0, int $hoursAgo = 2): string
    {
        return now()->utc()->subDays($daysAgo)->subHours($hoursAgo)->format('Y-m-d\TH:i:s\Z');
    }

    private function sale(string $amount, array $extra = [], array $line = [])
    {
        return $this->postJson('/api/v1/sales', array_replace(['recorded_at' => $this->at(), 'idempotency_key' => $this->key(),
            'items' => [array_replace(['kind' => 'other', 'description' => 'Manure', 'amount' => $amount], $line)]], $extra));
    }

    private function saleId(string $amount, array $extra = [], array $line = []): string
    {
        return $this->sale($amount, $extra, $line)->assertCreated()->json('data.id');
    }

    private function pay(string $sale, string $amount, string $receivedOn = '2026-10-14')
    {
        return $this->postJson('/api/v1/sales/'.$sale.'/payments', ['amount' => $amount, 'method' => 'cash', 'received_on' => $receivedOn, 'idempotency_key' => $this->key()]);
    }

    private function category(string $code): string
    {
        return collect($this->getJson('/api/v1/finance/categories')->assertOk()->json('data'))->firstWhere('code', $code)['id'];
    }

    private function otherIncome(string $amount, string $code = 'other_income', array $extra = [])
    {
        return $this->postJson('/api/v1/income', array_replace(['finance_category_id' => $this->category($code), 'amount' => $amount, 'occurred_on' => '2026-10-14', 'idempotency_key' => $this->key()], $extra));
    }

    private function income(string $query = ''): array
    {
        return $this->getJson('/api/v1/finance/income'.$query)->assertOk()->json();
    }

    private function setting(bool $on): void
    {
        $this->patchJson('/api/v1/farm', ['settings' => ['use_stock_for_usage' => $on]])->assertOk();
    }

    // ------------------------------------------------------------------ money status on a sale

    public function test_every_sale_shows_total_received_balance_and_money_status(): void
    {
        $unpaid = $this->sale('10000.00')->assertCreated();
        $unpaid->assertJsonPath('data.total', '10000.00')->assertJsonPath('data.amount_received', '0.00')->assertJsonPath('data.balance', '10000.00')
            ->assertJsonPath('data.money_status', 'not_paid')->assertJsonPath('data.payment_status', 'uninvoiced');

        $part = $this->saleId('10000.00');
        $this->pay($part, '4000.00')->assertCreated()->assertJsonPath('data.money_status', 'part_paid')->assertJsonPath('data.amount_received', '4000.00')
            ->assertJsonPath('data.balance', '6000.00')->assertJsonPath('data.payment_status', 'partially_paid');

        $paid = $this->saleId('10000.00');
        $this->pay($paid, '10000.00')->assertCreated()->assertJsonPath('data.money_status', 'paid')->assertJsonPath('data.balance', '0.00');

        $this->postJson('/api/v1/sales/'.$unpaid->json('data.id').'/cancel', ['reason' => 'Buyer changed mind', 'recorded_at' => $this->at(0, 1), 'idempotency_key' => $this->key()])
            ->assertOk()->assertJsonPath('data.money_status', 'cancelled')->assertJsonPath('data.balance', '0.00')->assertJsonPath('data.amount_received', '0.00');

        // The same fields on the list and the show endpoints.
        $this->getJson('/api/v1/sales/'.$part)->assertOk()->assertJsonPath('data.money_status', 'part_paid');
        $list = collect($this->getJson('/api/v1/sales')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('paid', $list[$paid]['money_status']);
        $this->assertSame('cancelled', $list[$unpaid->json('data.id')]['money_status']);
    }

    // ------------------------------------------------------------------ the combined list

    public function test_a_sale_is_one_row_even_with_two_payments_and_payments_are_never_listed(): void
    {
        $sale = $this->saleId('10000.00', ['buyer' => ['name' => 'Mama Put', 'phone' => '08031112222']], ['description' => 'Eggs', 'quantity' => '10', 'unit_price' => '1000.00']);
        $this->pay($sale, '3000.00')->assertCreated();
        $this->pay($sale, '2000.00')->assertCreated();
        $this->assertSame(2, Payment::count());

        $res = $this->income();
        $this->assertCount(1, $res['data']);
        $this->assertSame(1, $res['meta']['total']);
        $row = $res['data'][0];
        $this->assertSame('sale', $row['kind']);
        $this->assertSame($sale, $row['id']);
        $this->assertSame('2026-10-14', $row['date']);
        $this->assertSame(['10000.00', '5000.00', '5000.00', 'part_paid'], [$row['total'], $row['amount_received'], $row['balance'], $row['money_status']]);
        $this->assertSame('Mama Put', $row['contact_name']);
        $this->assertSame('08031112222', $row['contact_phone']);
        $this->assertSame('Eggs × 10', $row['summary']);
        $this->assertSame('/api/v1/sales/'.$sale, $row['links']['sale']);
        $this->assertNull($row['links']['transaction']);
        $this->assertSame('other_income', $row['category']['code']);
    }

    public function test_manual_income_rows_appear_and_reversed_ones_are_hidden(): void
    {
        $kept = $this->otherIncome('7000.00', 'services', ['description' => 'Ploughing for a neighbour'])->assertCreated()->json('data');
        $gone = $this->otherIncome('1500.00', 'grants_loans_support')->assertCreated()->json('data');
        $this->postJson('/api/v1/finance/transactions/'.$gone['id'].'/reverse', ['reason' => 'Entered twice', 'idempotency_key' => $this->key()])->assertCreated();

        $res = $this->income();
        $this->assertCount(1, $res['data']);
        $row = $res['data'][0];
        $this->assertSame(['other_income', $kept['id'], 'received', '7000.00', '7000.00', '0.00', 'services'], [$row['kind'], $row['id'], $row['money_status'], $row['total'], $row['amount_received'], $row['balance'], $row['category']['code']]);
        $this->assertSame('Ploughing for a neighbour', $row['summary']);
        $this->assertSame('/api/v1/finance/transactions/'.$kept['id'], $row['links']['transaction']);
        $this->assertNull($row['links']['sale']);
    }

    public function test_the_filters(): void
    {
        $buyerA = $this->postJson('/api/v1/contacts', ['name' => 'Adaeze Stores', 'kind' => 'business', 'roles' => ['customer'], 'phone' => '08055550001'])->assertCreated()->json('data.id');
        $paid = $this->saleId('5000.00', ['contact_id' => $buyerA, 'recorded_at' => $this->at(10)]);
        $this->pay($paid, '5000.00', now('Africa/Lagos')->subDays(10)->toDateString())->assertCreated();
        $part = $this->saleId('8000.00', ['customer_name' => 'Walk-in Bola', 'recorded_at' => $this->at(5)]);
        $this->pay($part, '1000.00', now('Africa/Lagos')->subDays(5)->toDateString())->assertCreated();
        $notPaid = $this->saleId('3000.00', ['recorded_at' => $this->at(1)]);
        $cancelled = $this->saleId('900.00', ['recorded_at' => $this->at(3)]);
        $this->postJson('/api/v1/sales/'.$cancelled.'/cancel', ['reason' => 'Mistake', 'recorded_at' => $this->at(0, 1), 'idempotency_key' => $this->key()])->assertOk();
        $other = $this->otherIncome('2000.00', 'services', ['occurred_on' => '2026-10-12', 'contact_id' => $buyerA])->assertCreated()->json('data.id');

        $ids = fn (string $q) => collect($this->income($q)['data'])->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$paid, $part, $notPaid, $other], $ids(''));
        $this->assertSame([$paid], $ids('?money_status=paid'));
        $this->assertSame([$part], $ids('?money_status=part_paid'));
        $this->assertSame([$notPaid], $ids('?money_status=not_paid'));
        $this->assertSame([$other], $ids('?money_status=received'));
        $this->assertEqualsCanonicalizing([$paid, $part, $notPaid, $other, $cancelled], $ids('?include_cancelled=1'));
        $this->assertSame([], $ids('?money_status=paid&production_cycle_id='.$this->key()));
        $this->assertEqualsCanonicalizing([$part, $notPaid, $other], $ids('?from='.now('Africa/Lagos')->subDays(5)->toDateString()));
        $this->assertEqualsCanonicalizing([$paid], $ids('?to='.now('Africa/Lagos')->subDays(9)->toDateString()));
        $this->assertEqualsCanonicalizing([$paid, $other], $ids('?contact_id='.$buyerA));
        $this->assertSame([$paid], $ids('?search=08055550001&money_status=paid'));
        $this->assertEqualsCanonicalizing([$paid, $other], $ids('?search=Adaeze'));
        $this->assertSame([$part], $ids('?search=Bola'));
        $reference = $this->getJson('/api/v1/sales/'.$notPaid)->json('data.reference');
        $this->assertSame([$notPaid], $ids('?search='.$reference));
        $this->assertSame([$other], $ids('?finance_category_id='.$this->category('services')));
        $this->assertEqualsCanonicalizing([$paid, $part, $notPaid], $ids('?finance_category_id='.$this->category('other_income')));
    }

    public function test_production_cycle_filter_matches_sales_with_a_line_in_the_cycle_and_ledger_rows(): void
    {
        $cycle = $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => 'Layer flock', 'operation_type_id' => \App\Models\OperationType::where('code', 'poultry')->firstOrFail()->id,
            'species_id' => \App\Models\Species::where('code', 'chicken')->firstOrFail()->id, 'production_purpose' => 'breeding', 'initial_population' => 50, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
        $inCycle = $this->saleId('4000.00', [], ['kind' => 'livestock', 'description' => null, 'production_cycle_id' => $cycle, 'head_count' => 2]);
        $this->saleId('1000.00');
        $manual = $this->otherIncome('500.00', 'other_income', ['production_cycle_id' => $cycle])->assertCreated()->json('data.id');
        $this->otherIncome('600.00')->assertCreated();

        $this->assertEqualsCanonicalizing([$inCycle, $manual], collect($this->income('?production_cycle_id='.$cycle)['data'])->pluck('id')->all());
    }

    public function test_the_union_is_paginated_newest_date_first_then_created_at(): void
    {
        $oldest = $this->saleId('100.00', ['recorded_at' => $this->at(6)]);
        $tx3 = $this->otherIncome('200.00', 'other_income', ['occurred_on' => '2026-10-10'])->assertCreated()->json('data.id');
        $sale2 = $this->saleId('300.00', ['recorded_at' => $this->at(2)]);
        $tx1 = $this->otherIncome('400.00', 'other_income', ['occurred_on' => '2026-10-14'])->assertCreated()->json('data.id');
        $this->travel(5)->minutes();
        $sale1 = $this->saleId('500.00', ['recorded_at' => $this->at(0)]);
        // Same date (2026-10-14): newest created_at first -> sale1 (created last), tx1, then the 2026-10-12 sale, the 2026-10-10 row, the 2026-10-08 sale.
        $expected = [$sale1, $tx1, $sale2, $tx3, $oldest];

        $p1 = $this->income('?per_page=2&page=1');
        $p2 = $this->income('?per_page=2&page=2');
        $p3 = $this->income('?per_page=2&page=3');
        $this->assertSame(5, $p1['meta']['total']);
        $this->assertSame(3, $p1['meta']['last_page']);
        $got = array_merge(array_column($p1['data'], 'id'), array_column($p2['data'], 'id'), array_column($p3['data'], 'id'));
        $this->assertSame($expected, $got);
        $this->assertSame(['sale', 'other_income'], array_column($p1['data'], 'kind'));
    }

    public function test_totals_money_received_and_still_owed_including_an_uninvoiced_sale(): void
    {
        $part = $this->saleId('10000.00');
        $this->pay($part, '4000.00')->assertCreated();
        $paid = $this->saleId('2000.00');
        $this->pay($paid, '2000.00')->assertCreated();
        $this->saleId('3000.00'); // never invoiced: owes its whole total
        $this->otherIncome('5000.00')->assertCreated();
        $reversed = $this->otherIncome('999.00')->assertCreated()->json('data.id');
        $this->postJson('/api/v1/finance/transactions/'.$reversed.'/reverse', ['reason' => 'Wrong', 'idempotency_key' => $this->key()])->assertCreated();
        // A cancelled sale owes nothing, whatever it was worth.
        $cancelled = $this->saleId('7777.00');
        $this->postJson('/api/v1/sales/'.$cancelled.'/cancel', ['reason' => 'Mistake', 'recorded_at' => $this->at(0, 1), 'idempotency_key' => $this->key()])->assertOk();

        $totals = $this->income()['meta']['totals'];
        $this->assertSame('11000.00', $totals['money_received']); // 4000 + 2000 payments + 5000 manual, the 999 entry netted to zero
        $this->assertSame('9000.00', $totals['still_owed']);      // 6000 on the part-paid + 3000 un-invoiced
        $this->assertSame('NGN', $totals['currency']);
        // money_received is the finance summary's income for the same range.
        $this->assertSame($totals['money_received'], $this->getJson('/api/v1/finance/summary')->json('data.totals.income'));

        // Date range: only money received inside it; still_owed is not date filtered.
        $this->otherIncome('1000.00', 'other_income', ['occurred_on' => '2026-10-01'])->assertCreated();
        $range = $this->income('?from=2026-10-14&to=2026-10-14')['meta']['totals'];
        $this->assertSame('11000.00', $range['money_received']);
        $this->assertSame('9000.00', $range['still_owed']);
        $this->assertSame('12000.00', $this->income()['meta']['totals']['money_received']);
        $this->assertSame($range['money_received'], $this->getJson('/api/v1/finance/summary?from=2026-10-14&to=2026-10-14')->json('data.totals.income'));
    }

    public function test_a_worker_sees_sale_rows_only_and_no_money_totals(): void
    {
        $sale = $this->saleId('3000.00');
        $this->otherIncome('5000.00')->assertCreated();

        $worker = $this->member(FarmRole::FarmWorker, name: 'Wale Worker');
        $this->signInAs($worker);
        $res = $this->income();
        $this->assertSame([$sale], array_column($res['data'], 'id'));
        $this->assertSame(['sale'], array_unique(array_column($res['data'], 'kind')));
        $this->assertNull($res['meta']['totals']);
        $this->assertSame([], $this->income('?money_status=received')['data']);
        // Everything else in Finance stays closed to them.
        $this->getJson('/api/v1/finance/transactions')->assertForbidden();
    }

    public function test_a_member_with_neither_permission_is_refused(): void
    {
        $this->signInAs($this->member(FarmRole::Vet, name: 'Vera Vet'));
        $this->getJson('/api/v1/finance/income')->assertForbidden();
    }

    public function test_another_farms_income_is_never_listed(): void
    {
        $this->saleId('3000.00');
        $this->otherIncome('5000.00')->assertCreated();
        $other = \App\Models\User::factory()->onboarded('Other Farm')->create();
        $this->signInAs($other);
        $res = $this->income();
        $this->assertSame([], $res['data']);
        $this->assertSame(['0.00', '0.00'], [$res['meta']['totals']['money_received'], $res['meta']['totals']['still_owed']]);
    }

    // ------------------------------------------------------------------ typed product lines

    public function test_typed_product_lines_pick_the_income_category_and_touch_no_stock(): void
    {
        $this->setting(false);
        $before = InventoryMovement::count();
        foreach (['eggs' => 'egg_sales', 'milk' => 'milk_sales', 'meat' => 'livestock_sales', 'crops' => 'crop_sales', 'other_produce' => 'crop_sales'] as $type => $code) {
            $this->postJson('/api/v1/sales', ['recorded_at' => $this->at(), 'idempotency_key' => $this->key(), 'items' => [
                ['kind' => 'other', 'product_type' => $type, 'description' => ucfirst($type), 'quantity' => '4', 'unit_price' => '250.00'],
            ]])->assertCreated()->assertJsonPath('data.finance_category.code', $code)->assertJsonPath('data.total_amount', '1000.00')
                ->assertJsonPath('data.items.0.product_type', $type)->assertJsonPath('data.items.0.entered_quantity', '4')->assertJsonPath('data.items.0.inventory_item_id', null);
        }
        $this->assertSame($before, InventoryMovement::count());

        // Mixed typed products follow the produce default; a typed line next to a plain one is plain other income.
        $this->postJson('/api/v1/sales', ['recorded_at' => $this->at(), 'idempotency_key' => $this->key(), 'items' => [
            ['kind' => 'other', 'product_type' => 'eggs', 'description' => 'Eggs', 'amount' => '500.00'],
            ['kind' => 'other', 'product_type' => 'milk', 'description' => 'Milk', 'amount' => '500.00'],
        ]])->assertCreated()->assertJsonPath('data.finance_category.code', 'crop_sales');
        $this->postJson('/api/v1/sales', ['recorded_at' => $this->at(), 'idempotency_key' => $this->key(), 'items' => [
            ['kind' => 'other', 'product_type' => 'eggs', 'description' => 'Eggs', 'amount' => '500.00'],
            ['kind' => 'other', 'description' => 'Delivery', 'amount' => '500.00'],
        ]])->assertCreated()->assertJsonPath('data.finance_category.code', 'other_income');

        // The payment books income under the typed category, and the Income list names it.
        $sale = $this->saleId('900.00', [], ['product_type' => 'eggs', 'description' => 'Eggs']);
        $this->pay($sale, '900.00')->assertCreated();
        $this->assertSame('egg_sales', collect($this->income('?money_status=paid')['data'])->firstWhere('id', $sale)['category']['code']);
        $this->assertSame('egg_sales', collect($this->getJson('/api/v1/finance/transactions')->json('data'))->first()['category']['code']);
    }

    public function test_typed_product_lines_are_refused_when_the_farm_sells_from_the_store(): void
    {
        $this->setting(true);
        $this->sale('1000.00', [], ['product_type' => 'eggs', 'description' => 'Eggs'])->assertStatus(422)
            ->assertJsonPath('errors', ['items.0.product_type' => ['Your farm sells from the store. Pick the item from your store.']]);
        // A plain "something else" line stays allowed.
        $this->sale('1000.00')->assertCreated()->assertJsonPath('data.items.0.product_type', null);
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_product_type_is_only_for_something_else_lines(): void
    {
        $this->setting(false);
        $this->sale('1000.00', [], ['kind' => 'livestock', 'product_type' => 'meat', 'production_cycle_id' => $this->key(), 'head_count' => 1])->assertStatus(422);
        $this->sale('1000.00', [], ['product_type' => 'fish'])->assertStatus(422);
    }
}
