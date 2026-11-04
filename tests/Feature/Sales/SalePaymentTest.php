<?php

namespace Tests\Feature\Sales;

use App\Models\FinanceTransaction;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Team\TeamTestCase;

/** POST /sales/{sale}/payments: money received straight on a sale; the invoice is created behind the scenes when missing. */
class SalePaymentTest extends TeamTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
        $this->signInAs($this->owner);
    }

    private function sale(string $amount = '10000.00'): string
    {
        return $this->postJson('/api/v1/sales', [
            'recorded_at' => now()->utc()->subHours(2)->format('Y-m-d\TH:i:s\Z'), 'idempotency_key' => (string) Str::uuid(),
            'items' => [['kind' => 'other', 'description' => 'Manure', 'amount' => $amount]],
        ])->assertCreated()->json('data.id');
    }

    private function pay(string $sale, string $amount, array $extra = [])
    {
        return $this->postJson('/api/v1/sales/'.$sale.'/payments', array_replace(['amount' => $amount, 'method' => 'cash', 'received_on' => '2026-10-14', 'idempotency_key' => (string) Str::uuid()], $extra));
    }

    public function test_paying_an_uninvoiced_sale_creates_the_invoice_and_books_income(): void
    {
        $sale = $this->sale();
        $this->getJson('/api/v1/sales/'.$sale)->assertOk()->assertJsonPath('data.payment_status', 'uninvoiced')->assertJsonPath('data.invoice_id', null);

        $res = $this->pay($sale, '4000.00')->assertCreated();
        $res->assertJsonPath('data.id', $sale)->assertJsonPath('data.payment_status', 'partially_paid')->assertJsonCount(1, 'data.payments');
        $this->assertSame('4000.00', $res->json('data.payments.0.amount'));
        $this->assertNotNull($res->json('data.invoice_id'));
        $this->assertSame(1, Invoice::where('sale_id', $sale)->count());
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, FinanceTransaction::count());
    }

    public function test_part_payment_then_the_rest_reuses_the_invoice_and_settles_the_sale(): void
    {
        $sale = $this->sale();
        $first = $this->pay($sale, '3000.00')->assertCreated();
        $second = $this->pay($sale, '7000.00')->assertCreated();

        $second->assertJsonPath('data.payment_status', 'paid')->assertJsonCount(2, 'data.payments');
        $this->assertSame($first->json('data.invoice_id'), $second->json('data.invoice_id'));
        $this->assertSame(1, Invoice::where('sale_id', $sale)->count());
        $this->assertSame(2, FinanceTransaction::count());
    }

    public function test_overpayment_is_refused_and_leaves_no_invoice_behind(): void
    {
        $sale = $this->sale();
        $this->pay($sale, '10000.01')->assertStatus(409)->assertJsonPath('code', 'payment_exceeds_balance');
        $this->assertSame(0, Invoice::where('sale_id', $sale)->count());
        $this->assertSame(0, Payment::count());

        $this->pay($sale, '6000.00')->assertCreated();
        $this->pay($sale, '4000.01')->assertStatus(409)->assertJsonPath('code', 'payment_exceeds_balance')->assertJsonPath('details.outstanding', '4000.00');
        $this->pay($sale, '0.00')->assertStatus(422);
    }

    public function test_an_identical_retry_returns_the_original_without_a_second_payment(): void
    {
        $sale = $this->sale();
        $body = ['amount' => '2500.00', 'method' => 'bank_transfer', 'received_on' => '2026-10-14', 'idempotency_key' => (string) Str::uuid()];

        $first = $this->postJson('/api/v1/sales/'.$sale.'/payments', $body)->assertCreated();
        $retry = $this->postJson('/api/v1/sales/'.$sale.'/payments', $body)->assertCreated();

        $this->assertSame($first->json('data.payments.0.id'), $retry->json('data.payments.0.id'));
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, Invoice::where('sale_id', $sale)->count());
        $this->assertSame(1, FinanceTransaction::count());

        $this->postJson('/api/v1/sales/'.$sale.'/payments', ['amount' => '2600.00'] + $body)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
    }

    public function test_a_cancelled_sale_cannot_be_paid_and_another_farms_sale_is_not_found(): void
    {
        $sale = $this->sale();
        $this->postJson('/api/v1/sales/'.$sale.'/cancel', ['reason' => 'Mistake', 'recorded_at' => now()->utc()->subHour()->format('Y-m-d\TH:i:s\Z'), 'idempotency_key' => (string) Str::uuid()])->assertOk();
        $this->pay($sale, '100.00')->assertStatus(409)->assertJsonPath('code', 'sale_cancelled');

        $live = $this->sale();
        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $this->pay($live, '100.00')->assertNotFound();
    }
}
