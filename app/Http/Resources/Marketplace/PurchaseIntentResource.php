<?php

namespace App\Http\Resources\Marketplace;

use App\Enums\ListingStatus;
use App\Enums\ShopStatus;
use App\Models\MarketplacePurchaseIntent;
use App\Services\Marketplace\MarketplaceListingRules;
use App\Support\Measurement\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A recorded "proceed at listed price". Interest only: not an acceptance, order, payment or deal. `is_current` is computed on read - false when the listed
 * price or unit has since changed, or the listing is no longer live, so the buyer is never shown a stale price as if it still held.
 *
 * @property MarketplacePurchaseIntent $resource
 */
class PurchaseIntentResource extends JsonResource
{
    public static $wrap = null;

    private string $audience = 'buyer';

    public function audience(string $audience): static
    {
        $this->audience = $audience;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $i = $this->resource;
        $rules = app(MarketplaceListingRules::class);
        $listing = $i->listing;
        $live = $listing->status === ListingStatus::Published && $listing->shop->status === ShopStatus::Active;
        $current = $live && $listing->unit_id === $i->unit_id && Decimal::cmp(Decimal::trim((string) $listing->unit_price), Decimal::trim((string) $i->listed_unit_price)) === 0;

        $out = [
            'id' => $i->id, 'reference' => $i->reference, 'kind' => 'purchase_intent', 'status' => 'recorded', 'agreement' => 'none',
            'listing' => ['id' => $i->listing_id, 'slug' => $listing->slug, 'reference' => $listing->reference, 'title' => $i->listing_title, 'currently_live' => $live],
            'shop' => ['id' => $i->shop_id, 'name' => $i->shop->name] + ($this->audience === 'buyer' ? ['slug' => $i->shop->slug] : []),
            'terms' => [
                'quantity' => Decimal::trim((string) $i->quantity), 'unit' => $i->unit_code, 'unit_price' => $rules->money((string) $i->listed_unit_price),
                'total' => $rules->money((string) $i->total_amount), 'currency' => $i->currency,
            ],
            'is_current' => $current, 'listing_version' => $i->listing_version,
            'note' => 'Interest recorded at the listed price. Nothing is reserved, paid or agreed; the seller must confirm before any deal.',
            'created_at' => $i->created_at->toIso8601String(), 'updated_at' => $i->updated_at->toIso8601String(), 'contact' => null,
        ];
        if ($this->audience === 'seller') {
            $out['buyer'] = ['name' => $i->buyer->name];
        }

        return $out;
    }
}
