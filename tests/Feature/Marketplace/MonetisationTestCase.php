<?php

namespace Tests\Feature\Marketplace;

use App\Models\MarketplaceSellerPlan;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Phase 26 fixtures: a fake Paystack (initialize + verify answer from `$this->gateway`, keyed by reference), signed webhooks, configured paid plans and
 * promotion packages. No test reaches the real provider; the real HTTP client code runs against Http::fake.
 */
abstract class MonetisationTestCase extends ListingTestCase
{
    protected const SECRET = 'sk_test_phase26_secret';

    protected const PLATFORM = '/api/v1/platform-admin';

    /** @var array<string, array{status: string, amount: int, currency: string, reference?: string}> what Paystack "knows" per reference */
    protected array $gateway = [];

    protected bool $gatewayDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paystack.secret_key' => self::SECRET, 'marketplace.payments.callback_url' => 'https://app.farmvest.test/billing/return']);
        Http::fake([
            '*/transaction/initialize' => fn (Request $r) => $this->gatewayDown ? Http::response('', 503)
                : Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.test/'.$r['reference'], 'access_code' => 'ac_'.$r['reference']]]),
            '*/transaction/verify/*' => function (Request $r) {
                if ($this->gatewayDown) {
                    return Http::response('', 503);
                }
                $ref = urldecode(basename(parse_url($r->url(), PHP_URL_PATH)));
                $g = $this->gateway[$ref] ?? null;

                return $g === null ? Http::response(['status' => false, 'message' => 'Transaction reference not found'], 404)
                    : Http::response(['status' => true, 'data' => ['status' => $g['status'], 'reference' => $g['reference'] ?? $ref, 'amount' => $g['amount'], 'currency' => $g['currency']]]);
            },
        ]);
    }

    /** Runs $work as a platform admin and then signs the previous user back in, so set-up helpers never change who the test is acting as. */
    protected function asAdmin(callable $work): mixed
    {
        $who = $this->app['auth']->guard('web')->user();
        try {
            return $work($this->signInAs($this->admin()));
        } finally {
            if ($who) {
                $this->signInAs($who);
            }
        }
    }

    protected function setFlag(string $key, bool $enabled): void
    {
        $this->asAdmin(fn ($admin) => $admin->patchJson(self::PLATFORM."/feature-flags/$key", ['enabled' => $enabled])->assertOk());
    }

    protected function planId(string $code): string
    {
        return MarketplaceSellerPlan::where('code', $code)->value('id');
    }

    /** Configures and switches on Seller Plus (limit $limit, ₦5,000.00 / 30 days, ₦50,000 / 365 days) and turns the seller-plans flag on. */
    protected function plusOnSale(int $limit = 25): string
    {
        $id = $this->planId('seller_plus');
        $this->asAdmin(function ($admin) use ($id, $limit) {
            $admin->putJson(self::PLATFORM."/marketplace/seller-plans/$id/prices", ['prices' => [['interval_days' => 30, 'amount' => '5000.00'], ['interval_days' => 365, 'amount' => '50000']]])->assertOk();
            $admin->patchJson(self::PLATFORM."/marketplace/seller-plans/$id", ['listing_limit' => $limit, 'is_active' => true])->assertOk();
        });
        $this->setFlag('marketplace_seller_plans', true);

        return $id;
    }

    protected function proOnSale(int $limit = 100): string
    {
        $id = $this->planId('seller_pro');
        $this->asAdmin(function ($admin) use ($id, $limit) {
            $admin->putJson(self::PLATFORM."/marketplace/seller-plans/$id/prices", ['prices' => [['interval_days' => 30, 'amount' => '15000']]])->assertOk();
            $admin->patchJson(self::PLATFORM."/marketplace/seller-plans/$id", ['listing_limit' => $limit, 'is_active' => true])->assertOk();
        });

        return $id;
    }

    /** Creates a ₦3,000 / 7-day promotion package and turns the promotions flag on. */
    protected function packageOnSale(string $amount = '3000', int $days = 7, string $code = 'featured_week'): string
    {
        $id = $this->asAdmin(fn ($admin) => $admin->postJson(self::PLATFORM.'/marketplace/promotion-packages', ['code' => $code, 'name' => 'Featured '.$days.' days', 'duration_days' => $days, 'amount' => $amount])
            ->assertCreated()->json('data.id'));
        $this->setFlag('marketplace_promotions', true);

        return $id;
    }

    /** Starts a plan checkout as $owner and returns the payment reference. */
    protected function checkoutPlan(User $owner, string $shop, string $plan, int $days = 30): string
    {
        return $this->signInAs($owner)->postJson(self::SELLER."/shops/$shop/subscription/checkout", ['plan_id' => $plan, 'interval_days' => $days])->assertCreated()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.benefit_granted', false)->json('data.reference');
    }

    protected function checkoutPromotion(User $owner, string $shop, string $listing, string $package): string
    {
        return $this->signInAs($owner)->postJson(self::SELLER."/shops/$shop/listings/$listing/promotions/checkout", ['package_id' => $package])->assertCreated()->json('data.reference');
    }

    /** What Paystack will report for a reference. */
    protected function gatewayReports(string $reference, string $status, int $kobo, string $currency = 'NGN', ?string $echoedReference = null): void
    {
        $this->gateway[$reference] = ['status' => $status, 'amount' => $kobo, 'currency' => $currency] + ($echoedReference ? ['reference' => $echoedReference] : []);
    }

    protected function webhook(string $reference, string $event = 'charge.success', ?string $signature = null, array $data = [])
    {
        $body = json_encode(['event' => $event, 'data' => ['reference' => $reference, 'status' => 'success', 'amount' => 1, 'currency' => 'NGN'] + $data]);
        $this->app['auth']->forgetGuards();

        return $this->call('POST', '/api/v1/public/marketplace/payments/paystack/webhook', [], [], [], [
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature ?? hash_hmac('sha512', $body, self::SECRET), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    protected function verify(User $owner, string $shop, string $reference)
    {
        return $this->signInAs($owner)->postJson(self::SELLER."/shops/$shop/payments/$reference/verify");
    }
}
