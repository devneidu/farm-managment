<?php

namespace Tests\Feature\Finance;

use App\Models\FinanceTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Team\TeamTestCase;

/** Backend for the Transactions page: payment method, quantity x unit price, sales-only categories, source links, duplicate warning. */
class TransactionsPageTest extends TeamTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAs($this->owner);
    }

    private function today(): string
    {
        return now('Africa/Lagos')->toDateString();
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    private function category(string $code, string $direction): string
    {
        foreach ($this->getJson('/api/v1/finance/categories?direction='.$direction)->assertOk()->json('data') as $row) {
            if ($row['code'] === $code) {
                return $row['id'];
            }
        }
        $this->fail('Category '.$code.' missing');
    }

    private function expense(array $extra = [])
    {
        return $this->postJson('/api/v1/expenses', array_replace(['finance_category_id' => $this->category('transport', 'expense'), 'amount' => '2500.00', 'occurred_on' => $this->today(), 'idempotency_key' => $this->key()], $extra));
    }

    private function income(array $extra = [])
    {
        return $this->postJson('/api/v1/income', array_replace(['finance_category_id' => $this->category('other_income', 'income'), 'amount' => '9000', 'occurred_on' => $this->today(), 'idempotency_key' => $this->key()], $extra));
    }

    private function purchase(array $extra = [])
    {
        return $this->postJson('/api/v1/purchases', array_replace(['recorded_at' => now()->utc()->subHour()->format('Y-m-d\TH:i:s\Z'), 'idempotency_key' => $this->key(),
            'items' => [['kind' => 'non_stock', 'description' => 'Fuel', 'amount' => '900']]], $extra));
    }

    private function supplier(string $name): string
    {
        return $this->postJson('/api/v1/contacts', ['name' => $name, 'kind' => 'business', 'roles' => ['supplier']])->assertCreated()->json('data.id');
    }

    public function test_payment_method_is_saved_validated_returned_and_filterable(): void
    {
        $cash = $this->expense(['payment_method' => 'cash'])->assertCreated()->assertJsonPath('data.payment_method', 'cash')->json('data');
        $this->income(['payment_method' => 'bank_transfer'])->assertCreated();
        $none = $this->expense()->assertCreated()->assertJsonPath('data.payment_method', null)->json('data');
        $this->expense(['payment_method' => 'barter'])->assertStatus(422)->assertJsonValidationErrors('payment_method');

        $this->assertSame([$cash['id']], array_column($this->getJson('/api/v1/finance/transactions?payment_method=cash')->assertOk()->json('data'), 'id'));
        $this->assertCount(1, $this->getJson('/api/v1/finance/transactions?payment_method=bank_transfer')->json('data'));
        $this->getJson('/api/v1/finance/transactions?payment_method=barter')->assertStatus(422)->assertJsonValidationErrors('payment_method');

        // A reversal row offsets with the same method; a row without one stays null.
        $rev = $this->postJson('/api/v1/finance/transactions/'.$cash['id'].'/reverse', ['reason' => 'Typo', 'idempotency_key' => $this->key()])->assertCreated();
        $rev->assertJsonPath('data.payment_method', 'cash');
        $this->assertNull($none['payment_method']);
    }

    public function test_amount_is_computed_from_quantity_and_unit_price_and_the_pair_rules_hold(): void
    {
        $row = $this->expense(['amount' => null, 'quantity' => '12.5', 'unit_price' => '150.30'])->assertCreated()->json('data');
        $this->assertSame('1878.75', $row['amount']);
        $this->assertSame('12.5', $row['quantity']);
        $this->assertSame('150.30', $row['unit_price']);

        // Rounds half up to two decimals: 3 x 0.125 -> 0.375 -> 0.38 needs a price with 2 decimals, so use 0.33 x 0.5 = 0.165 -> 0.17.
        $this->expense(['amount' => null, 'quantity' => '0.5', 'unit_price' => '0.33'])->assertCreated()->assertJsonPath('data.amount', '0.17');

        // A matching amount is accepted; a different one is refused.
        $this->expense(['amount' => '1878.75', 'quantity' => '12.5', 'unit_price' => '150.30'])->assertCreated();
        $this->expense(['amount' => '1878.76', 'quantity' => '12.5', 'unit_price' => '150.30'])->assertStatus(422)->assertJsonValidationErrors('amount');

        // Only one of the pair, or no amount at all.
        $this->expense(['amount' => '100', 'quantity' => '2'])->assertStatus(422)->assertJsonValidationErrors('unit_price');
        $this->expense(['amount' => '100', 'unit_price' => '50'])->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->expense(['amount' => null])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->expense(['amount' => null, 'quantity' => '0', 'unit_price' => '50'])->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->expense(['amount' => null, 'quantity' => '1.1234567', 'unit_price' => '50'])->assertStatus(422)->assertJsonValidationErrors('quantity');

        // A plain amount still works and leaves the pair empty.
        $this->expense()->assertCreated()->assertJsonPath('data.quantity', null)->assertJsonPath('data.unit_price', null);
    }

    public function test_product_sales_categories_are_refused_manually_but_other_income_and_new_categories_work(): void
    {
        $categories = collect($this->getJson('/api/v1/finance/categories?direction=income')->assertOk()->json('data'))->keyBy('code');
        foreach (['livestock_sales', 'egg_sales', 'milk_sales', 'crop_sales'] as $code) {
            $this->assertFalse($categories[$code]['manual_entry'], $code);
            $res = $this->income(['finance_category_id' => $categories[$code]['id']])->assertStatus(422)->assertJsonValidationErrors('finance_category_id');
            $this->assertStringContainsString('Record this through Sales so your stock stays correct.', json_encode($res->json()));
            $this->postJson('/api/v1/finance/transactions', ['direction' => 'income', 'finance_category_id' => $categories[$code]['id'], 'amount' => '10', 'occurred_on' => $this->today(), 'idempotency_key' => $this->key()])->assertStatus(422);
        }
        $this->assertSame(0, FinanceTransaction::count());

        foreach (['other_income', 'services', 'grants_loans_support'] as $code) {
            $this->assertTrue($categories[$code]['manual_entry'], $code);
            $this->assertTrue($categories[$code]['is_system']);
            $this->income(['finance_category_id' => $categories[$code]['id']])->assertCreated()->assertJsonPath('data.category.code', $code);
        }
        $this->assertSame('Services', $categories['services']['name']);
        $this->assertSame('Grants, loans & support', $categories['grants_loans_support']['name']);
        foreach ($this->getJson('/api/v1/finance/categories?direction=expense')->json('data') as $expense) {
            $this->assertTrue($expense['manual_entry']);
        }
    }

    public function test_source_object_and_reversal_flag_for_manual_and_purchase_rows(): void
    {
        $manual = $this->expense()->assertCreated()->json('data');
        $this->assertSame(['type' => null, 'id' => null, 'label' => 'Entered manually'], $manual['source']);
        $this->assertTrue($manual['can_reverse_directly']);

        $purchase = $this->purchase()->assertCreated()->json('data');
        $row = $this->getJson('/api/v1/finance/transactions?source_type=purchase')->assertOk()->json('data.0');
        $this->assertSame('purchase', $row['source']['type']);
        $this->assertSame($purchase['id'], $row['source']['id']);
        $this->assertSame($purchase['reference'], $row['source']['reference']);
        $this->assertMatchesRegularExpression('/^Purchase #\d+$/', $row['source']['label']);
        $this->assertFalse($row['can_reverse_directly']);
        $this->postJson('/api/v1/finance/transactions/'.$row['id'].'/reverse', ['reason' => 'x', 'idempotency_key' => $this->key()])->assertStatus(409)->assertJsonPath('code', 'reverse_via_purchase');

        // A reversed manual row (and the reversal itself) can no longer be reversed directly.
        $this->postJson('/api/v1/finance/transactions/'.$manual['id'].'/reverse', ['reason' => 'Typo', 'idempotency_key' => $this->key()])->assertCreated()->assertJsonPath('data.can_reverse_directly', false);
        $this->assertFalse($this->getJson('/api/v1/finance/transactions/'.$manual['id'])->json('data.can_reverse_directly'));
    }

    public function test_a_manual_expense_matching_a_live_purchase_warns_and_proceeds_when_confirmed(): void
    {
        $supplier = $this->supplier('Fuel Depot');
        $purchase = $this->purchase(['contact_id' => $supplier])->assertCreated()->json('data');
        $day = CarbonImmutable::parse($purchase['recorded_at'])->setTimezone('Africa/Lagos')->toDateString();
        $manual = ['amount' => '900.00', 'occurred_on' => $day, 'contact_id' => $supplier];

        $warn = $this->expense($manual)->assertStatus(409)->assertJsonPath('code', 'possible_duplicate');
        $warn->assertJsonPath('details.purchase_id', $purchase['id'])->assertJsonPath('details.reference', $purchase['reference']);
        $this->assertMatchesRegularExpression('/^Purchase #\d+$/', $warn->json('details.label'));
        $this->assertSame(1, FinanceTransaction::count()); // only the purchase's own expense

        // Without a contact the same amount and day also warn; a different amount, day or contact does not.
        $this->expense(['amount' => '900', 'occurred_on' => $day])->assertStatus(409)->assertJsonPath('code', 'possible_duplicate');
        $this->expense(['amount' => '901.00', 'occurred_on' => $day, 'contact_id' => $supplier])->assertCreated();
        $this->expense(array_replace($manual, ['contact_id' => $this->supplier('Someone Else')]))->assertCreated();
        $this->expense(['amount' => '900.00', 'occurred_on' => CarbonImmutable::parse($day)->subDays(2)->toDateString()])->assertCreated();

        // Confirming records it; the acknowledgement is not part of the stored event, so a retry replays.
        $confirmed = $manual + ['confirm_duplicate' => true, 'idempotency_key' => $this->key()];
        $first = $this->expense($confirmed)->assertCreated()->json('data');
        $this->expense($confirmed)->assertSuccessful()->assertJsonPath('data.id', $first['id']);

        // Income is never warned, and a cancelled purchase is no longer live.
        $this->income(['amount' => '900.00', 'occurred_on' => $day])->assertCreated();
        $this->postJson('/api/v1/purchases/'.$purchase['id'].'/cancel', ['reason' => 'Wrong', 'recorded_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'idempotency_key' => $this->key()])->assertSuccessful();
        $this->expense(['amount' => '900.00', 'occurred_on' => $day, 'description' => 'after cancel'])->assertCreated();
    }
}
