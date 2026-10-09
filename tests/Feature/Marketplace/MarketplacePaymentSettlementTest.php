<?php

namespace Tests\Feature\Marketplace;

use App\Models\AuditLog;
use App\Models\MarketplacePaymentWebhookEvent;
use App\Models\MarketplaceServicePayment;
use App\Models\MarketplaceShopSubscription;
use App\Models\User;
use App\Services\Marketplace\ServicePaymentSettler;
use Illuminate\Support\Facades\Artisan;

/** The payment safeguards: server-side verification, amount/currency/reference checks, signed and de-duplicated webhooks, retry safety and idempotent settlement. */
class MarketplacePaymentSettlementTest extends MonetisationTestCase
{
    /** @return array{0: User, 1: string, 2: string} owner, shop, payment reference (Plus 30 days = 500000 kobo) */
    private function pending(): array
    {
        $owner = $this->seller();
        $shop = $this->activeShop($owner);
        $ref = $this->checkoutPlan($owner, $shop, $this->plusOnSale(), 30);

        return [$owner, $shop, $ref];
    }

    public function test_a_wrong_amount_a_wrong_currency_or_a_wrong_reference_never_activates(): void
    {
        foreach ([[400000, 'NGN', null, 'amount_mismatch'], [500001, 'NGN', null, 'amount_mismatch'], [500000, 'USD', null, 'currency_mismatch'], [500000, 'NGN', 'MSP-2026-99999', 'reference_mismatch']] as [$kobo, $cur, $echo, $reason]) {
            [$owner, $shop, $ref] = $this->pending();
            $this->gatewayReports($ref, 'success', $kobo, $cur, $echo);
            $this->verify($owner, $shop, $ref)->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.benefit_granted', false);
            $this->assertDatabaseHas('marketplace_service_payments', ['reference' => $ref, 'status' => 'failed', 'failure_reason' => $reason, 'settled_at' => null]);
            $this->getJson(self::SELLER."/shops/$shop/allowance")->assertJsonPath('data.plan.code', 'free');
        }
        $this->assertSame(0, MarketplaceShopSubscription::count());
    }

    public function test_failed_abandoned_unknown_and_reversed_payments_grant_nothing(): void
    {
        [$owner, $shop, $ref] = $this->pending();

        $this->verify($owner, $shop, $ref)->assertOk()->assertJsonPath('data.status', 'pending');          // Paystack has never seen it (404)
        $this->gatewayReports($ref, 'abandoned', 500000);
        $this->verify($owner, $shop, $ref)->assertOk()->assertJsonPath('data.status', 'pending');
        $this->gatewayReports($ref, 'failed', 500000);
        $this->verify($owner, $shop, $ref)->assertOk()->assertJsonPath('data.status', 'failed');
        $this->assertSame(0, MarketplaceShopSubscription::count());

        // A customer whose first attempt failed may pay on the same checkout: the later success is honoured.
        $this->gatewayReports($ref, 'success', 500000);
        $this->verify($owner, $shop, $ref)->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.benefit_granted', true);

        // ...and a granted payment can never be undone by a later report.
        $this->gatewayReports($ref, 'failed', 500000);
        $this->verify($owner, $shop, $ref)->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.benefit_granted', true);
        $this->assertSame(1, MarketplaceShopSubscription::count());
    }

    public function test_a_webhook_without_a_valid_signature_is_rejected_and_does_nothing(): void
    {
        [, $shop, $ref] = $this->pending();
        $this->gatewayReports($ref, 'success', 500000);

        $this->webhook($ref, signature: 'deadbeef')->assertStatus(401)->assertJsonPath('code', 'invalid_signature');
        $this->webhook($ref, signature: '')->assertStatus(401);
        $this->assertSame(0, MarketplacePaymentWebhookEvent::count());
        $this->assertSame(0, MarketplaceShopSubscription::count());

        config(['services.paystack.secret_key' => null]);   // an unconfigured server accepts nothing
        $this->webhook($ref, signature: hash_hmac('sha512', '{}', ''))->assertStatus(401);
    }

    public function test_a_signed_webhook_activates_once_and_redeliveries_are_acknowledged_without_a_second_activation(): void
    {
        [$owner, $shop, $ref] = $this->pending();
        $this->gatewayReports($ref, 'success', 500000);

        $this->webhook($ref)->assertOk()->assertJsonPath('result', 'processed');
        $this->webhook($ref)->assertOk()->assertJsonPath('result', 'duplicate');
        $this->webhook($ref)->assertOk()->assertJsonPath('result', 'duplicate');
        $this->verify($owner, $shop, $ref)->assertOk();

        $this->assertSame(1, MarketplaceShopSubscription::count());
        $this->assertSame(1, MarketplacePaymentWebhookEvent::count());
        $this->assertSame('processed', MarketplacePaymentWebhookEvent::first()->status);
        $this->getJson(self::SELLER."/shops/$shop/allowance")->assertJsonPath('data.plan.code', 'seller_plus');
    }

    public function test_the_webhook_body_is_never_believed_only_the_provider_is(): void
    {
        [$owner, $shop, $ref] = $this->pending();
        $this->gatewayReports($ref, 'failed', 500000);   // the body below claims success; Paystack says otherwise

        $this->webhook($ref, data: ['status' => 'success', 'amount' => 500000])->assertOk();
        $this->assertSame(0, MarketplaceShopSubscription::count());
        $this->assertSame('failed', MarketplaceServicePayment::first()->status);
    }

    public function test_unrelated_events_and_unknown_references_are_acknowledged_and_ignored(): void
    {
        $this->webhook('whatever', event: 'transfer.success')->assertOk()->assertJsonPath('result', 'ignored');
        $this->webhook('NOT-OURS-1')->assertOk()->assertJsonPath('result', 'processed');
        $this->assertSame(0, MarketplaceShopSubscription::count());
    }

    public function test_a_provider_outage_never_loses_a_successful_payment_it_is_retried(): void
    {
        [$owner, $shop, $ref] = $this->pending();
        $this->gatewayReports($ref, 'success', 500000);

        $this->gatewayDown = true;
        $this->webhook($ref)->assertStatus(500);                 // Paystack will redeliver
        $this->verify($owner, $shop, $ref)->assertStatus(502)->assertJsonPath('code', 'gateway_unavailable');
        $event = MarketplacePaymentWebhookEvent::first();
        $this->assertSame('failed', $event->status);
        $this->assertSame(1, $event->attempts);
        $this->assertSame(0, MarketplaceShopSubscription::count());

        // The provider comes back. Redelivery of the same event is processed (not dismissed as a duplicate).
        $this->gatewayDown = false;
        $this->webhook($ref)->assertOk()->assertJsonPath('result', 'processed');
        $this->assertSame(1, MarketplaceShopSubscription::count());
        $this->assertSame(2, $event->refresh()->attempts);
    }

    public function test_the_reconcile_command_recovers_a_lost_webhook_and_a_failed_event_and_abandons_stale_checkouts(): void
    {
        [$owner, $shop, $ref] = $this->pending();                                 // paid, webhook never arrived
        $this->gatewayReports($ref, 'success', 500000);
        $lazy = $this->checkoutPlan($owner, $shop, $this->planId('seller_plus'), 365);   // never paid

        $this->gatewayDown = true;
        $this->webhook($ref)->assertStatus(500);
        $this->gatewayDown = false;
        $this->travel(3)->minutes();
        Artisan::call('marketplace:reconcile-payments');
        $this->assertSame(1, MarketplaceShopSubscription::count());
        $this->assertSame('processed', MarketplacePaymentWebhookEvent::first()->status);
        $this->assertSame('pending', MarketplaceServicePayment::where('reference', $lazy)->value('status'));

        $this->travel(25)->hours();
        Artisan::call('marketplace:reconcile-payments');
        $this->assertSame('abandoned', MarketplaceServicePayment::where('reference', $lazy)->value('status'));
        $this->assertSame(1, MarketplaceShopSubscription::count());
        Artisan::call('marketplace:reconcile-payments');   // idempotent
        $this->assertSame(1, MarketplaceShopSubscription::count());
    }

    public function test_settling_the_same_payment_repeatedly_grants_the_benefit_exactly_once(): void
    {
        [, , $ref] = $this->pending();
        $this->gatewayReports($ref, 'success', 500000);
        $settler = app(ServicePaymentSettler::class);

        for ($i = 0; $i < 5; $i++) {
            $settler->settle($ref);
        }
        $this->assertSame(1, MarketplaceShopSubscription::count());
        $this->assertSame(1, AuditLog::where('action', 'marketplace.subscription_activated')->count());
        $this->assertNull($settler->settle('MSP-2026-00000'), 'an unknown reference is ignored');
    }

    public function test_the_secret_key_and_provider_internals_are_never_exposed_to_sellers(): void
    {
        [$owner, $shop, $ref] = $this->pending();
        $body = $this->getJson(self::SELLER."/shops/$shop/payments")->assertOk()->getContent();
        $this->assertStringNotContainsString(self::SECRET, $body);
        $this->assertStringNotContainsString('access_code', $body);
        $this->assertStringNotContainsString('gateway_status', $body);
        $this->assertStringContainsString('checkout.paystack.test', $body, 'a pending payment shows its checkout link');

        $this->gatewayReports($ref, 'success', 500000);
        $this->verify($owner, $shop, $ref)->assertOk()->assertJsonPath('data.authorization_url', null);
        $admin = $this->signInAs($this->admin())->getJson(self::PLATFORM.'/marketplace/service-payments?status=paid')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.gateway_status', 'success')->assertJsonPath('data.0.shop.id', $shop);
        $this->assertStringNotContainsString(self::SECRET, $admin->getContent());
    }
}
