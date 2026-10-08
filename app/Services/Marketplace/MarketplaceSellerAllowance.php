<?php

namespace App\Services\Marketplace;

use App\Enums\ListingStatus;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSellerPlan;
use App\Models\MarketplaceShop;
use App\Models\MarketplaceShopSubscription;
use App\Support\Api\ApiHttpException;

/**
 * How many PUBLISHED listings a shop may have, resolved when read: a shop is on the free plan unless it has a paid period running right now, so a lapsed
 * subscription needs no job to take effect. Existing listings are never deleted or paused by a lower allowance - the only effect is that publishing
 * ANOTHER listing is refused while the shop is at or above its limit. A paid period keeps the limit it was bought with.
 */
class MarketplaceSellerAllowance
{
    /** Used only when no free plan row exists (it is seeded, but a deleted/edited-away row must not unlock unlimited publishing). */
    public const FALLBACK_FREE_LIMIT = 10;

    public function freePlan(): ?MarketplaceSellerPlan
    {
        return MarketplaceSellerPlan::where('is_free', true)->first();
    }

    public function currentPeriod(MarketplaceShop|string $shop): ?MarketplaceShopSubscription
    {
        $id = $shop instanceof MarketplaceShop ? $shop->id : $shop;

        return MarketplaceShopSubscription::where('shop_id', $id)->current()->orderByDesc('starts_at')->first();
    }

    /** @return array{plan_code: string, plan_name: string, source: string, listing_limit: int|null, period: MarketplaceShopSubscription|null} */
    public function effective(MarketplaceShop $shop): array
    {
        if ($period = $this->currentPeriod($shop)) {
            return ['plan_code' => $period->plan?->code ?? 'paid', 'plan_name' => $period->plan_name, 'source' => 'subscription', 'listing_limit' => $period->listing_limit, 'period' => $period];
        }
        $free = $this->freePlan();

        return ['plan_code' => $free?->code ?? 'free', 'plan_name' => $free?->name ?? 'Free', 'source' => 'free', 'listing_limit' => $free ? $free->listing_limit : self::FALLBACK_FREE_LIMIT, 'period' => null];
    }

    /** @param  bool  $latest  read the newest committed rows (a locking read) rather than this transaction's snapshot - needed when deciding under the shop lock */
    public function publishedCount(MarketplaceShop $shop, ?string $exceptListingId = null, bool $latest = false): int
    {
        return MarketplaceListing::where('shop_id', $shop->id)->where('status', ListingStatus::Published->value)
            ->when($exceptListingId, fn ($q) => $q->whereKeyNot($exceptListingId))->when($latest, fn ($q) => $q->lockForUpdate())->count();
    }

    /** @return array<string, mixed> */
    public function allowance(MarketplaceShop $shop): array
    {
        $effective = $this->effective($shop);
        $limit = $effective['listing_limit'];
        $used = $this->publishedCount($shop);

        return [
            'plan' => ['code' => $effective['plan_code'], 'name' => $effective['plan_name'], 'source' => $effective['source']],
            'listing_limit' => $limit, 'unlimited' => $limit === null, 'published_count' => $used,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
            'over_limit_by' => $limit === null ? 0 : max(0, $used - $limit),
            'can_publish' => $limit === null || $used < $limit,
            'period' => $effective['period'] ? ['starts_at' => $effective['period']->starts_at->toIso8601String(), 'ends_at' => $effective['period']->ends_at->toIso8601String()] : null,
        ];
    }

    /** Called with the shop row locked, so two simultaneous publishes cannot both take the last place. */
    public function assertCanPublish(MarketplaceShop $shop, string $listingId): void
    {
        $limit = $this->effective($shop)['listing_limit'];
        if ($limit === null) {
            return;
        }
        // The request's earlier plain reads pinned a snapshot, so the count must be a locking read to see publishes committed while we waited for the shop lock.
        $used = $this->publishedCount($shop, $listingId, latest: true);
        if ($used >= $limit) {
            throw new ApiHttpException(409, 'listing_limit_reached', 'Your plan allows '.$limit.' published '.($limit === 1 ? 'listing' : 'listings').' at a time. Pause or archive one, or move to a larger plan.',
                details: ['listing_limit' => $limit, 'published_count' => $used, 'over_limit_by' => max(0, $used - $limit)]);
        }
    }
}
