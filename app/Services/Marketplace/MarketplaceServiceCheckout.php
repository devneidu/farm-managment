<?php

namespace App\Services\Marketplace;

use App\Enums\ListingStatus;
use App\Enums\ShopStatus;
use App\Models\MarketplaceListing;
use App\Models\MarketplacePromotion;
use App\Models\MarketplacePromotionPackage;
use App\Models\MarketplaceSellerPlan;
use App\Models\MarketplaceServicePayment;
use App\Models\MarketplaceShop;
use App\Models\MarketplaceShopSubscription;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Platform\PlatformConfigService;
use App\Support\Api\ApiHttpException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Starts a Farmvest service purchase for ONE shop: a prepaid seller-plan period or a promotion of one listing. This only records the intent to pay (a
 * `pending` payment with the amount frozen from the CURRENT configured price) and asks Paystack for a checkout page. It grants nothing: benefits are
 * granted only by ServicePaymentSettler after the provider itself confirms the payment. Needs `billing.manage` (owner, manager) in the shop.
 */
class MarketplaceServiceCheckout
{
    private const LOCK = 'marketplace_service_payment';

    public function __construct(
        private MarketplaceShopService $shops,
        private MarketplaceSellerAllowance $allowance,
        private PlatformConfigService $config,
        private PaystackClient $paystack,
        private AuditLogger $audit,
    ) {}

    /** @return array{0: MarketplaceServicePayment, 1: bool} the payment and whether it is new (false = an identical pending checkout was reused) */
    public function subscription(User $user, string $shopId, string $planId, int $intervalDays): array
    {
        $this->assertEnabled('marketplace_seller_plans');
        $shop = $this->billingShop($user, $shopId);
        $plan = MarketplaceSellerPlan::with('prices')->whereKey($planId)->first();
        $price = $plan?->prices->first(fn ($p) => $p->interval_days === $intervalDays && $p->is_active);
        if (! $plan || $plan->is_free || ! $plan->is_active || ! $price || $plan->listing_limit === 0) {
            throw new ApiHttpException(409, 'plan_not_purchasable', 'This plan cannot be bought for that period right now.');
        }
        $latest = MarketplaceShopSubscription::where('shop_id', $shop->id)->where('ends_at', '>', now())->orderByDesc('ends_at')->first();
        if ($latest && $latest->plan_id !== $plan->id) {
            // Periods never overlap and are not prorated: a different paid plan can be bought once the current one has ended.
            throw new ApiHttpException(409, 'subscription_active', 'This shop already has a paid plan running; renew the same plan or wait until it ends to switch.',
                details: ['plan' => $latest->plan_name, 'ends_at' => $latest->ends_at->toIso8601String()]);
        }

        return $this->start($user, $shop, [
            'purpose' => 'subscription', 'plan_id' => $plan->id, 'interval_days' => $intervalDays, 'amount' => (string) $price->amount, 'currency' => $price->currency,
            'subject_label' => $plan->name.' - '.$intervalDays.' days',
        ]);
    }

    /** @return array{0: MarketplaceServicePayment, 1: bool} */
    public function promotion(User $user, string $shopId, string $listingId, string $packageId): array
    {
        $this->assertEnabled('marketplace_promotions');
        $shop = $this->billingShop($user, $shopId);
        $listing = MarketplaceListing::where('shop_id', $shop->id)->whereKey($listingId)->first();
        abort_if($listing === null, 404);
        $package = MarketplacePromotionPackage::whereKey($packageId)->first();
        if (! $package || ! $package->is_active) {
            throw new ApiHttpException(409, 'package_not_available', 'This promotion package is not available.');
        }
        if ($listing->status !== ListingStatus::Published) {
            throw new ApiHttpException(409, 'listing_not_promotable', 'Only a published listing can be promoted.', details: ['listing_status' => $listing->status->value]);
        }
        $running = MarketplacePromotion::running()->where('listing_id', $listing->id)->first();
        if ($running) {
            throw new ApiHttpException(409, 'promotion_active', 'This listing is already promoted; you can buy another promotion after it ends.', details: ['expires_at' => $running->expires_at->toIso8601String()]);
        }

        return $this->start($user, $shop, [
            'purpose' => 'promotion', 'package_id' => $package->id, 'listing_id' => $listing->id, 'amount' => (string) $package->amount, 'currency' => $package->currency,
            'subject_label' => mb_substr($package->name.' - '.$listing->title, 0, 150),
        ]);
    }

    private function assertEnabled(string $flag): void
    {
        if (! $this->config->flag($flag)) {
            throw new ApiHttpException(409, 'monetisation_disabled', 'This service is not available yet.');
        }
    }

    private function billingShop(User $user, string $shopId): MarketplaceShop
    {
        $shop = $this->shops->memberShop($user, $shopId);
        if (! $user->can('manageBilling', $shop)) {
            throw new AuthorizationException;
        }
        if ($shop->status !== ShopStatus::Active) {
            throw new ApiHttpException(409, 'shop_not_active', 'Only an approved, open shop can buy Farmvest services.', details: ['shop_status' => $shop->status->value]);
        }

        return $shop;
    }

    /** @param  array<string, mixed>  $terms */
    private function start(User $user, MarketplaceShop $shop, array $terms): array
    {
        if (! $this->paystack->configured()) {
            throw new ApiHttpException(503, 'payments_unavailable', 'Online payment is not available right now.');
        }
        [$payment, $created] = MarketplaceReferences::locked(self::LOCK, fn () => DB::transaction(function () use ($user, $shop, $terms) {
            $same = array_intersect_key($terms, array_flip(['purpose', 'plan_id', 'interval_days', 'package_id', 'listing_id', 'amount']));
            $existing = MarketplaceServicePayment::where('shop_id', $shop->id)->where('user_id', $user->id)->where('status', MarketplaceServicePayment::PENDING)
                ->where('created_at', '>=', now()->subMinutes((int) config('marketplace.payments.pending_reuse_minutes')))->whereNotNull('authorization_url')
                ->where(fn ($q) => collect($same)->each(fn ($v, $k) => $q->where($k, $v)))->latest('created_at')->first();
            if ($existing) {
                return [$existing, false];
            }
            $payment = new MarketplaceServicePayment($terms + ['shop_id' => $shop->id, 'user_id' => $user->id, 'provider' => 'paystack', 'status' => MarketplaceServicePayment::PENDING]);
            $payment->forceFill(['reference' => MarketplaceReferences::next('MSP', MarketplaceServicePayment::class)])->save();
            $this->audit->record($shop->farm_id, $user->id, 'marketplace.service_payment_started', 'marketplace_service_payment', $payment->id, $payment->reference,
                ['purpose' => $payment->purpose, 'amount' => (string) $payment->amount, 'currency' => $payment->currency, 'subject' => $payment->subject_label]);

            return [$payment, true];
        }));
        if (! $created) {
            return [$payment, false];
        }
        try {
            $init = $this->paystack->initialize($user->email, (string) $payment->amount, $payment->currency, $payment->reference, config('marketplace.payments.callback_url'),
                ['purpose' => $payment->purpose, 'shop_id' => $shop->id]);
        } catch (PaymentGatewayException) {
            $payment->forceFill(['status' => MarketplaceServicePayment::FAILED, 'failure_reason' => 'initialize_failed'])->save();
            throw new ApiHttpException(502, 'gateway_unavailable', 'The payment provider could not be reached. Nothing was charged; please try again.');
        }
        $payment->forceFill(['authorization_url' => $init['authorization_url'], 'access_code' => $init['access_code']])->save();

        return [$payment, true];
    }
}
