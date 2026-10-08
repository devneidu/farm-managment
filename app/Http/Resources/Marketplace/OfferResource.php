<?php

namespace App\Http\Resources\Marketplace;

use App\Enums\ListingStatus;
use App\Enums\OfferStatus;
use App\Enums\ShopStatus;
use App\Models\MarketplaceOffer;
use App\Services\Marketplace\MarketplaceListingRules;
use App\Support\Measurement\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A buyer offer, for the buyer (`audience: buyer`) or for the shop's members (`audience: seller`). The status is the EFFECTIVE one (a lapsed pending offer
 * reads as `expired`). The seller sees the buyer's display name only - never an email, phone or id - and the buyer never sees seller contact: an accepted
 * offer is an agreement in principle, `contact` stays null until contact exchange exists (Phase 25).
 *
 * @property MarketplaceOffer $resource
 */
class OfferResource extends JsonResource
{
    public static $wrap = null;

    private string $audience = 'buyer';

    private bool $detailed = false;

    public function audience(string $audience): static
    {
        $this->audience = $audience;

        return $this;
    }

    public function detailed(): static
    {
        $this->detailed = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $o = $this->resource;
        $rules = app(MarketplaceListingRules::class);
        $status = $o->effectiveStatus();
        $live = $o->listing->status === ListingStatus::Published && $o->listing->shop->status === ShopStatus::Active;

        $out = [
            'id' => $o->id, 'reference' => $o->reference, 'kind' => 'offer', 'status' => $status->value, 'attempt_no' => $o->attempt_no,
            'listing' => ['id' => $o->listing_id, 'slug' => $o->listing->slug, 'reference' => $o->listing->reference, 'title' => $o->listing_title, 'currently_live' => $live],
            'shop' => ['id' => $o->shop_id, 'name' => $o->shop->name] + ($this->audience === 'buyer' ? ['slug' => $o->shop->slug] : []),
            'terms' => [
                'quantity' => Decimal::trim((string) $o->quantity), 'unit' => $o->unit_code, 'unit_price' => $rules->money((string) $o->unit_price),
                'total' => $rules->money((string) $o->total_amount), 'currency' => $o->currency,
            ],
            'listing_snapshot' => [
                'title' => $o->listing_title, 'product_name' => $o->product_name, 'product_kind' => $o->product_kind, 'unit' => $o->unit_code,
                'listed_unit_price' => $rules->money((string) $o->listed_unit_price), 'listing_version' => $o->listing_version,
                'available_quantity' => Decimal::trim((string) $o->listing_available_quantity),
                'min_order_quantity' => $o->listing_min_order_quantity === null ? null : Decimal::trim((string) $o->listing_min_order_quantity),
                'basis' => 'seller_declared_at_offer_time',
            ],
            'expires_at' => $o->expires_at->toIso8601String(), 'responded_at' => $o->responded_at?->toIso8601String(),
            'void_reason' => $o->void_reason, 'created_at' => $o->created_at->toIso8601String(),
            'agreement' => $status === OfferStatus::Accepted ? 'seller_accepted' : 'none',
            // Seller contact stays private until Phase 25 (deal confirmation + contact exchange).
            'contact' => null,
        ];
        if ($status === OfferStatus::Accepted) {
            $out['next_step'] = 'Accepted in principle. Availability is not reserved and the sale is not complete; contact exchange and deal confirmation come in a later step.';
        }
        if ($this->audience === 'seller') {
            $out['buyer'] = ['name' => $o->buyer->name];
            $out['respondable'] = $status === OfferStatus::Pending;
        }
        if ($this->detailed) {
            $out['history'] = $o->events->map(fn ($e) => ['action' => $e->action, 'actor' => $e->actor_kind, 'from' => $e->from_status, 'to' => $e->to_status, 'code' => $e->code, 'at' => $e->created_at->toIso8601String()])->all();
        }

        return $out;
    }
}
