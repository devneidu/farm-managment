<?php

namespace App\Services\Marketplace;

use App\Models\MarketplacePromotion;
use App\Models\MarketplacePromotionPackage;
use App\Models\MarketplaceSellerPlan;
use App\Models\MarketplaceServicePayment;
use App\Models\MarketplaceShop;
use App\Models\MarketplaceShopSubscription;
use App\Models\User;
use App\Services\Platform\PlatformConfigService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The seller's read side of monetisation, always through the caller's shop membership (another shop answers 404). Plan, allowance and the catalogue need
 * only `listing.view` (every role, so staff can see why a publish was refused); payment and promotion history need `billing.view`; re-checking a payment
 * needs `billing.manage`.
 */
class MarketplaceSellerBilling
{
    public function __construct(
        private MarketplaceShopService $shops,
        private MarketplaceSellerAllowance $allowance,
        private ServicePaymentSettler $settler,
        private PlatformConfigService $config,
    ) {}

    /** @return array<string, mixed> */
    public function plan(User $user, string $shopId): array
    {
        $shop = $this->member($user, $shopId, 'viewListings');
        $periods = MarketplaceShopSubscription::where('shop_id', $shop->id)->where('ends_at', '>', now())->orderBy('starts_at')->get();

        return $this->allowance->allowance($shop) + [
            'upcoming_periods' => $periods->filter(fn ($p) => $p->starts_at->isFuture())->map(fn ($p) => $this->period($p))->values()->all(),
            'features' => $this->features(),
        ];
    }

    /** @return array<string, mixed> */
    public function allowance(User $user, string $shopId): array
    {
        return $this->allowance->allowance($this->member($user, $shopId, 'viewListings'));
    }

    /** @return array{plans: Collection<int, MarketplaceSellerPlan>, packages: Collection<int, MarketplacePromotionPackage>, features: array<string, bool>} */
    public function catalogue(User $user, string $shopId): array
    {
        $this->member($user, $shopId, 'viewListings');

        return [
            'plans' => MarketplaceSellerPlan::with('prices')->where('is_active', true)->orderBy('sort_order')->get(),
            'packages' => MarketplacePromotionPackage::where('is_active', true)->orderBy('sort_order')->orderBy('duration_days')->get(),
            'features' => $this->features(),
        ];
    }

    /** @param  array{status?: string, purpose?: string, per_page?: int}  $f */
    public function payments(User $user, string $shopId, array $f): LengthAwarePaginator
    {
        $shop = $this->member($user, $shopId, 'viewBilling');

        return MarketplaceServicePayment::where('shop_id', $shop->id)
            ->when(isset($f['status']), fn ($q) => $q->where('status', $f['status']))->when(isset($f['purpose']), fn ($q) => $q->where('purpose', $f['purpose']))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($f['per_page'] ?? 20);
    }

    /** @param  array{state?: string, per_page?: int}  $f */
    public function promotions(User $user, string $shopId, array $f): LengthAwarePaginator
    {
        $shop = $this->member($user, $shopId, 'viewBilling');

        return MarketplacePromotion::where('shop_id', $shop->id)->with(['listing', 'shop'])
            ->when(($f['state'] ?? null) === 'running', fn ($q) => $q->running())
            ->when(($f['state'] ?? null) === 'ended', fn ($q) => $q->where(fn ($w) => $w->where('status', MarketplacePromotion::CANCELLED)->orWhere('expires_at', '<=', now())))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($f['per_page'] ?? 20);
    }

    /** Re-checks a payment with the provider (the browser's return from checkout lands here); the redirect itself proves nothing. */
    public function verify(User $user, string $shopId, string $reference): MarketplaceServicePayment
    {
        $shop = $this->member($user, $shopId, 'manageBilling');
        $payment = MarketplaceServicePayment::where('shop_id', $shop->id)->where('reference', $reference)->first();
        abort_if($payment === null, 404);

        return $this->settler->settle($payment->reference) ?? $payment;
    }

    /** @return array<string, bool> */
    public function features(): array
    {
        return ['seller_plans' => $this->config->flag('marketplace_seller_plans'), 'promotions' => $this->config->flag('marketplace_promotions')];
    }

    /** @return array<string, mixed> */
    private function period(MarketplaceShopSubscription $p): array
    {
        return ['plan' => $p->plan_name, 'listing_limit' => $p->listing_limit, 'interval_days' => $p->interval_days, 'starts_at' => $p->starts_at->toIso8601String(), 'ends_at' => $p->ends_at->toIso8601String()];
    }

    private function member(User $user, string $shopId, string $ability): MarketplaceShop
    {
        $shop = $this->shops->memberShop($user, $shopId);
        if (! $user->can($ability, $shop)) {
            throw new AuthorizationException;
        }

        return $shop;
    }
}
