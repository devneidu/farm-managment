<?php

namespace App\Http\Resources\Marketplace;

use App\Enums\ListingStatus;
use App\Enums\ShopStatus;
use App\Models\MarketplacePromotion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A paid promotion. `state` is read from the paid window: `running` | `scheduled` | `expired` | `cancelled`. `benefit_active` is true only while it is running
 * AND the listing is published AND the shop is active - a suspended shop or a restricted listing gets no priority while the window keeps running (it is
 * not extended or refunded).
 *
 * @property MarketplacePromotion $resource
 */
class ShopPromotionResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $p = $this->resource;
        $state = match (true) {
            $p->status === MarketplacePromotion::CANCELLED => 'cancelled',
            $p->expires_at->lte(now()) => 'expired',
            $p->starts_at->gt(now()) => 'scheduled',
            default => 'running',
        };
        $listing = $p->relationLoaded('listing') ? $p->listing : null;
        $shop = $p->relationLoaded('shop') ? $p->shop : null;

        return [
            'id' => $p->id, 'reference' => $p->reference, 'shop_id' => $p->shop_id, 'listing' => $listing ? ['id' => $listing->id, 'title' => $listing->title, 'slug' => $listing->slug, 'status' => $listing->status->value] : ['id' => $p->listing_id],
            'package' => $p->package_name, 'duration_days' => $p->duration_days, 'amount' => (string) $p->amount, 'currency' => $p->currency,
            'state' => $state, 'benefit_active' => $state === 'running' && $listing?->status === ListingStatus::Published && $shop?->status === ShopStatus::Active,
            'starts_at' => $p->starts_at->toIso8601String(), 'expires_at' => $p->expires_at->toIso8601String(),
            'cancelled_at' => $p->cancelled_at?->toIso8601String(), 'cancel_reason' => $p->cancel_reason, 'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}
