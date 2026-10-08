<?php

namespace Tests\Feature\Marketplace;

use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\MarketplaceServicePayment;
use App\Models\MarketplaceShopSubscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

/** Seller plans: the publish allowance, paid subscriptions, expiry, renewal and admin configuration. */
class MarketplaceSellerPlanTest extends MonetisationTestCase
{
    private function fill(string $shop, int $n, string $prefix = 'Chickens'): array
    {
        $ids = [];
        for ($i = 1; $i <= $n; $i++) {
            $ids[] = $this->live($shop, $this->chicken(['title' => "$prefix $i"]));
        }

        return $ids;
    }

    // ------------------------------------------------------------------ the free allowance

    public function test_a_free_shop_may_publish_ten_listings_and_the_eleventh_is_refused_with_usage_details(): void
    {
        $owner = $this->seller();
        $shop = $this->activeShop($owner);
        $ids = $this->fill($shop, 10);

        $draft = $this->createId($shop, $this->chicken(['title' => 'Eleventh']));
        $this->publish($shop, $draft)->assertStatus(409)->assertJsonPath('code', 'listing_limit_reached')
            ->assertJsonPath('details.listing_limit', 10)->assertJsonPath('details.published_count', 10);
        $this->assertSame('draft', $this->getJson(self::SELLER."/shops/$shop/listings/$draft")->json('data.status'));

        $this->getJson(self::SELLER."/shops/$shop/allowance")->assertOk()->assertJsonPath('data.plan.code', 'free')->assertJsonPath('data.plan.source', 'free')
            ->assertJsonPath('data.listing_limit', 10)->assertJsonPath('data.published_count', 10)->assertJsonPath('data.remaining', 0)->assertJsonPath('data.can_publish', false);

        // Pausing one frees a place; re-publishing an already published listing is still a no-op and never trips the limit.
        $this->postJson(self::SELLER."/shops/$shop/listings/{$ids[0]}/pause")->assertOk();
        $this->publish($shop, $draft)->assertOk();
        $this->publish($shop, $draft)->assertOk();
        $this->publish($shop, $ids[0])->assertStatus(409)->assertJsonPath('code', 'listing_limit_reached');
    }

    public function test_the_free_limit_is_configurable_while_paid_plans_are_off_and_never_removes_existing_listings(): void
    {
        $owner = $this->seller();
        $shop = $this->activeShop($owner);
        $ids = $this->fill($shop, 4);
        $extra = $this->createId($shop, $this->chicken(['title' => 'Extra']));

        $free = $this->planId('free');
        $this->signInAs($this->admin())->patchJson(self::PLATFORM."/marketplace/seller-plans/$free", ['listing_limit' => 2])->assertOk()->assertJsonPath('data.listing_limit', 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.marketplace_seller_plan_updated', 'resource_id' => $free]);

        $this->signInAs($owner)->getJson(self::SELLER."/shops/$shop/allowance")->assertOk()->assertJsonPath('data.published_count', 4)->assertJsonPath('data.over_limit_by', 2)
            ->assertJsonPath('data.remaining', 0)->assertJsonPath('data.can_publish', false);
        foreach ($ids as $id) {   // nothing was paused or deleted
            $this->assertSame('published', $this->getJson(self::SELLER."/shops/$shop/listings/$id")->json('data.status'));
        }
        $this->publish($shop, $extra)->assertStatus(409)->assertJsonPath('code', 'listing_limit_reached')->assertJsonPath('details.over_limit_by', 2);
        $this->postJson(self::SELLER."/shops/$shop/listings/{$ids[0]}/archive")->assertOk();
        $this->postJson(self::SELLER."/shops/$shop/listings/{$ids[1]}/pause")->assertOk();
        $this->postJson(self::SELLER."/shops/$shop/listings/{$ids[2]}/pause")->assertOk();
        $this->publish($shop, $extra)->assertOk();   // 2 of 2 -> room again only once usage is below the limit
    }

    public function test_the_limit_is_per_shop_and_a_shop_without_a_farm_has_a_plan(): void
    {
        $owner = $this->seller();
        $a = $this->activeShop($owner);
        $b = $this->activeShop($owner);
        $this->fill($a, 10);

        $this->getJson(self::SELLER."/shops/$b/allowance")->assertOk()->assertJsonPath('data.published_count', 0)->assertJsonPath('data.can_publish', true);
        $this->live($b, $this->yam());
    }

    public function test_every_shop_role_can_read_the_allowance_but_a_non_member_gets_404(): void
    {
        $owner = $this->seller();
        $shop = $this->activeShop($owner);
        $staff = $this->addMember($owner, $shop, 'staff');
        $this->signInAs($staff)->getJson(self::SELLER."/shops/$shop/allowance")->assertOk();
        $this->getJson(self::SELLER."/shops/$shop/plan")->assertOk()->assertJsonPath('data.features.seller_plans', false);
        $this->signInAs($this->seller('Stranger'))->getJson(self::SELLER."/shops/$shop/allowance")->assertNotFound();
        $this->getJson(self::SELLER."/shops/$shop/plan")->assertNotFound();
    }

    // ------------------------------------------------------------------ buying a plan

    public function test_buying_a_plan_is_disabled_until_the_feature_flag_is_on_and_paid_plans_start_unsellable(): void
    {
        $owner = $this->seller();
        $shop = $this->activeShop($owner);

        $plans = $this->getJson(self::SELLER."/shops/$shop/plans")->assertOk()->assertJsonPath('meta.features.seller_plans', false);
        $this->assertSame(['free'], collect($plans->json('data'))->pluck('code')->all(), 'unpriced, inactive paid plans are not offered');

        $plus = $this->planId('seller_plus');
        $this->postJson(self::SELLER."/shops/$shop/subscription/checkout", ['plan_id' => $plus, 'interval_days' => 30])->assertStatus(409)->assertJsonPath('code', 'monetisation_disabled');

        $this->plusOnSale();
        $this->signInAs($owner)->postJson(self::SELLER."/shops/$shop/subscription/checkout", ['plan_id' => $this->planId('free'), 'interval_days' => 30])->assertStatus(409)->assertJsonPath('code', 'plan_not_purchasable');
        $this->postJson(self::SELLER."/shops/$shop/subscription/checkout", ['plan_id' => $this->planId('seller_pro'), 'interval_days' => 30])->assertStatus(409)->assertJsonPath('code', 'plan_not_purchasable');
        $this->postJson(self::SELLER."/shops/$shop/subscription/checkout", ['plan_id' => $plus, 'interval_days' => 7])->assertStatus(422)->assertJsonValidationErrors('interval_days');
    }

    public function test_a_confirmed_payment_activates_the_plan_raises_the_limit_and_activates_only_once(): void
    {
        $owner = $this->seller();
        $shop = $this->activeShop($owner);
        $this->fill($shop, 10);
        $plus = $this->plusOnSale(25);

        $ref = $this->checkoutPlan($owner, $shop, $plus, 30);
        $this->assertMatchesRegularExpression('/^MSP-\d{4}-\d{5}$/', $ref);
        $this->assertDatabaseHas('marketplace_service_payments', ['reference' => $ref, 'amount' => '5000.00', 'currency' => 'NGN', 'status' => 'pending', 'purpose' => 'subscription']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/transaction/initialize') && $r['amount'] === '500000' && $r['currency'] === 'NGN' && $r['reference'] === $ref
            && $r['callback_url'] === 'https://app.farmvest.test/billing/return');

        // Returning from the checkout page proves nothing: Paystack still says the customer has not paid.
        $this->gatewayReports($ref, 'abandoned', 500000);
        $this->verify($owner, $shop, $ref)->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.benefit_granted', false);
        $this->getJson(self::SELLER."/shops/$shop/allowance")->assertJsonPath('data.plan.code', 'free')->assertJsonPath('data.can_publish', false);

        $this->gatewayReports($ref, 'success', 500000);
        $this->verify($owner, $shop, $ref)->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.benefit_granted', true);
        $allowance = $this->getJson(self::SELLER."/shops/$shop/allowance")->assertOk()->assertJsonPath('data.plan.code', 'seller_plus')->assertJsonPath('data.plan.source', 'subscription')
            ->assertJsonPath('data.listing_limit', 25)->assertJsonPath('data.remaining', 15)->assertJsonPath('data.can_publish', true)->json('data');
        $this->assertSame(30, (int) round(now()->diffInDays(Carbon::parse($allowance['period']['ends_at']))));
        $this->publish($shop, $this->createId($shop, $this->chicken(['title' => 'Eleventh'])))->assertOk();

        // Verifying again, and a webhook for the same payment, grant nothing more.
        $this->verify($owner, $shop, $ref)->assertOk();
        $this->webhook($ref)->assertOk();
        $this->assertSame(1, MarketplaceShopSubscription::count());
        $this->assertSame(1, AuditLog::where('action', 'marketplace.subscription_activated')->count());
    }

    public function test_renewing_extends_the_current_period_and_a_different_plan_must_wait(): void
    {
        $owner = $this->seller();
        $shop = $this->activeShop($owner);
        $plus = $this->plusOnSale();
        $pro = $this->proOnSale();

        $first = $this->checkoutPlan($owner, $shop, $plus, 30);
        $this->gatewayReports($first, 'success', 500000);
        $this->verify($owner, $shop, $first)->assertOk();
        $firstEnd = MarketplaceShopSubscription::first()->ends_at;

        $this->postJson(self::SELLER."/shops/$shop/subscription/checkout", ['plan_id' => $pro, 'interval_days' => 30])->assertStatus(409)->assertJsonPath('code', 'subscription_active');

        $second = $this->checkoutPlan($owner, $shop, $plus, 365);
        $this->gatewayReports($second, 'success', 5000000);
        $this->verify($owner, $shop, $second)->assertOk()->assertJsonPath('data.benefit_granted', true);

        $periods = MarketplaceShopSubscription::orderBy('starts_at')->get();
        $this->assertCount(2, $periods);
        $this->assertTrue($periods[1]->starts_at->equalTo($firstEnd), 'the renewal starts exactly where the current period ends');
        $this->assertTrue($periods[1]->ends_at->equalTo($firstEnd->copy()->addDays(365)));
        $this->getJson(self::SELLER."/shops/$shop/plan")->assertOk()->assertJsonCount(1, 'data.upcoming_periods');
    }

    public function test_an_expired_plan_reads_as_free_without_any_job_and_keeps_every_listing(): void
    {
        $owner = $this->seller();
        $shop = $this->activeShop($owner);
        $plus = $this->plusOnSale(15);
        $ref = $this->checkoutPlan($owner, $shop, $plus, 30);
        $this->gatewayReports($ref, 'success', 500000);
        $this->verify($owner, $shop, $ref)->assertOk();
        $ids = $this->fill($shop, 14);
        $extra = $this->createId($shop, $this->chicken(['title' => 'Extra']));

        $this->travel(29)->days();
        $this->getJson(self::SELLER."/shops/$shop/allowance")->assertJsonPath('data.plan.code', 'seller_plus')->assertJsonPath('data.listing_limit', 15);

        $this->travel(3)->days();   // day 32: the period has ended
        $this->getJson(self::SELLER."/shops/$shop/allowance")->assertOk()->assertJsonPath('data.plan.code', 'free')->assertJsonPath('data.listing_limit', 10)
            ->assertJsonPath('data.published_count', 14)->assertJsonPath('data.over_limit_by', 4)->assertJsonPath('data.period', null);
        $this->publish($shop, $extra)->assertStatus(409)->assertJsonPath('code', 'listing_limit_reached');
        foreach ($ids as $id) {
            $this->assertContains($this->slug($id), $this->publicSlugs(), 'no listing was paused or deleted by the downgrade');
        }
    }

    public function test_a_period_keeps_the_limit_it_was_bought_with_when_the_plan_is_edited_later(): void
    {
        $owner = $this->seller();
        $shop = $this->activeShop($owner);
        $plus = $this->plusOnSale(25);
        $ref = $this->checkoutPlan($owner, $shop, $plus, 30);
        $this->gatewayReports($ref, 'success', 500000);
        $this->verify($owner, $shop, $ref)->assertOk();

        $this->signInAs($this->admin())->patchJson(self::PLATFORM."/marketplace/seller-plans/$plus", ['listing_limit' => 12])->assertOk();
        $this->signInAs($owner)->getJson(self::SELLER."/shops/$shop/allowance")->assertJsonPath('data.listing_limit', 25);
    }

    public function test_only_owner_and_manager_can_buy_or_see_payments_and_another_shops_payment_is_404(): void
    {
        $owner = $this->seller();
        $shop = $this->activeShop($owner);
        $manager = $this->addMember($owner, $shop, 'manager', 'Manager');
        $staff = $this->addMember($owner, $shop, 'staff', 'Staff');
        $plus = $this->plusOnSale();
        $stranger = $this->seller('Stranger');
        $otherShop = $this->activeShop($stranger);

        $this->signInAs($staff)->postJson(self::SELLER."/shops/$shop/subscription/checkout", ['plan_id' => $plus, 'interval_days' => 30])->assertForbidden();
        $this->getJson(self::SELLER."/shops/$shop/payments")->assertForbidden();
        $this->getJson(self::SELLER."/shops/$shop/promotions")->assertForbidden();
        $this->getJson(self::SELLER."/shops/$shop/plans")->assertOk();   // the catalogue is not secret

        $ref = $this->checkoutPlan($manager, $shop, $plus);
        $this->getJson(self::SELLER."/shops/$shop/payments")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', $ref);
        $this->verify($staff, $shop, $ref)->assertForbidden();
        $this->verify($stranger, $shop, $ref)->assertNotFound();
        $this->verify($stranger, $otherShop, $ref)->assertNotFound();   // a reference does not leak across shops
        $this->getJson(self::SELLER."/shops/$otherShop/payments")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_repeated_checkout_reuses_the_pending_payment_and_a_provider_outage_charges_nothing(): void
    {
        $owner = $this->seller();
        $shop = $this->activeShop($owner);
        $plus = $this->plusOnSale();

        $this->gatewayDown = true;
        $this->signInAs($owner)->postJson(self::SELLER."/shops/$shop/subscription/checkout", ['plan_id' => $plus, 'interval_days' => 30])->assertStatus(502)->assertJsonPath('code', 'gateway_unavailable');
        $this->assertDatabaseHas('marketplace_service_payments', ['status' => 'failed', 'failure_reason' => 'initialize_failed']);
        $this->gatewayDown = false;

        $first = $this->checkoutPlan($owner, $shop, $plus);
        $again = $this->postJson(self::SELLER."/shops/$shop/subscription/checkout", ['plan_id' => $plus, 'interval_days' => 30])->assertOk()->json('data.reference');
        $this->assertSame($first, $again);
        $this->assertSame(2, MarketplaceServicePayment::count());

        config(['services.paystack.secret_key' => null]);
        $this->postJson(self::SELLER."/shops/$shop/subscription/checkout", ['plan_id' => $plus, 'interval_days' => 365])->assertStatus(503)->assertJsonPath('code', 'payments_unavailable');
    }

    // ------------------------------------------------------------------ platform configuration

    public function test_admins_configure_plans_with_validation_and_a_paid_plan_cannot_go_on_sale_unpriced(): void
    {
        $admin = $this->signInAs($this->admin());
        $plus = $this->planId('seller_plus');

        $listing = $admin->getJson(self::PLATFORM.'/marketplace/seller-plans')->assertOk()->json('data');
        $this->assertSame(['free', 'seller_plus', 'seller_pro'], collect($listing)->pluck('code')->all());
        $this->assertSame(10, collect($listing)->firstWhere('code', 'free')['listing_limit']);
        $this->assertSame([], collect($listing)->firstWhere('code', 'seller_plus')['prices'], 'no paid price is invented');

        $admin->patchJson(self::PLATFORM."/marketplace/seller-plans/$plus", ['is_active' => true])->assertStatus(422)->assertJsonValidationErrors(['listing_limit', 'prices']);
        $admin->putJson(self::PLATFORM."/marketplace/seller-plans/$plus/prices", ['prices' => [['interval_days' => 30, 'amount' => '0']]])->assertStatus(422);
        $admin->putJson(self::PLATFORM."/marketplace/seller-plans/$plus/prices", ['prices' => [['interval_days' => 30, 'amount' => '5000.555']]])->assertStatus(422);
        $admin->patchJson(self::PLATFORM.'/marketplace/seller-plans/'.$this->planId('free'), ['is_active' => false])->assertStatus(409)->assertJsonPath('code', 'free_plan_required');
        $admin->putJson(self::PLATFORM.'/marketplace/seller-plans/'.$this->planId('free').'/prices', ['prices' => [['interval_days' => 30, 'amount' => '100']]])->assertStatus(409);

        $this->plusOnSale(40);
        $admin->postJson(self::PLATFORM.'/marketplace/seller-plans', ['code' => 'seller_max', 'name' => 'Seller Max', 'listing_limit' => 500])->assertCreated()->assertJsonPath('data.is_active', false);
        $admin->postJson(self::PLATFORM.'/marketplace/seller-plans', ['code' => 'seller_max', 'name' => 'Dup', 'listing_limit' => 5])->assertStatus(422);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.marketplace_seller_plan_prices_set', 'resource_id' => $plus]);
    }

    public function test_a_price_change_applies_to_future_checkouts_and_never_to_a_pending_payment(): void
    {
        $owner = $this->seller();
        $shop = $this->activeShop($owner);
        $plus = $this->plusOnSale();
        $ref = $this->checkoutPlan($owner, $shop, $plus, 30);

        $this->signInAs($this->admin())->putJson(self::PLATFORM."/marketplace/seller-plans/$plus/prices", ['prices' => [['interval_days' => 30, 'amount' => '9000']]])->assertOk();
        $this->gatewayReports($ref, 'success', 900000);   // the customer paid the NEW price for a checkout frozen at the old one
        $this->verify($owner, $shop, $ref)->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.benefit_granted', false);
        $this->assertDatabaseHas('marketplace_service_payments', ['reference' => $ref, 'amount' => '5000.00', 'failure_reason' => 'amount_mismatch']);
        $this->assertSame(0, MarketplaceShopSubscription::count());
    }

    public function test_platform_writes_need_the_admin_role_and_reads_any_platform_role(): void
    {
        $plus = $this->planId('seller_plus');
        $this->signInAs($this->admin(PlatformRole::Support))->getJson(self::PLATFORM.'/marketplace/seller-plans')->assertOk();
        $this->getJson(self::PLATFORM.'/marketplace/service-payments')->assertOk();
        $this->patchJson(self::PLATFORM."/marketplace/seller-plans/$plus", ['listing_limit' => 5])->assertForbidden();
        $this->postJson(self::PLATFORM.'/marketplace/promotion-packages', ['code' => 'x_pack', 'name' => 'X', 'duration_days' => 3, 'amount' => '100'])->assertForbidden();
        $this->signInAs($this->seller())->getJson(self::PLATFORM.'/marketplace/seller-plans')->assertForbidden();
    }
}
