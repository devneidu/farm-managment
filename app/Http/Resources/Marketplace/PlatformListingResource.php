<?php

namespace App\Http\Resources\Marketplace;

use App\Models\MarketplaceListing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Platform-admin view: the listing in any state, its shop, lifecycle and (on the detail view) the append-only history. No farm business data: the
 * farm link is just an id, like on shops.
 *
 * @property MarketplaceListing $resource
 */
class PlatformListingResource extends JsonResource
{
    public static $wrap = null;

    private bool $detailed = false;

    public function detailed(): static
    {
        $this->detailed = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $l = $this->resource;
        $shop = $l->shop;

        $out = [
            'id' => $l->id, 'reference' => $l->reference, 'slug' => $l->slug, 'status' => $l->status->value, 'version' => $l->version,
            'is_public' => $l->status->isPublished() && $shop->status->isPublic(),
            'hidden_because' => $l->status->isPublished() && ! $shop->status->isPublic() ? 'shop_not_active' : null,
            'title' => $l->title, 'product' => ListingPresenter::product($l), 'price' => ListingPresenter::price($l), 'quantity' => ListingPresenter::quantity($l),
            'negotiable' => $l->negotiable,
            'shop' => ['id' => $shop->id, 'reference' => $shop->reference, 'slug' => $shop->slug, 'name' => $shop->name, 'status' => $shop->status->value, 'verification_status' => $shop->verification_status->value],
            'restriction' => ['reason' => $l->restricted_reason, 'at' => $l->restricted_at?->toIso8601String(), 'by' => $l->restricted_by],
            'published_at' => $l->published_at?->toIso8601String(), 'created_at' => $l->created_at?->toIso8601String(), 'updated_at' => $l->updated_at?->toIso8601String(),
            'deleted_at' => $l->deleted_at?->toIso8601String(),
        ];
        if ($this->detailed) {
            $out += [
                'description' => $l->description, 'package' => ListingPresenter::package($l), 'fulfilment' => ListingPresenter::fulfilment($l), 'location' => ListingPresenter::location($l),
                'image' => ListingPresenter::image($l, 'admin'), 'images' => ListingPresenter::photos($l, 'admin'),
                'farm_id' => $l->farm_id, 'inventory_linked' => $l->inventory_item_id !== null,
                'history' => $l->events->map(fn ($e) => [
                    'action' => $e->action, 'from' => $e->from_status, 'to' => $e->to_status, 'actor_kind' => $e->actor_kind, 'actor_id' => $e->actor_id, 'reason' => $e->reason, 'at' => $e->created_at?->toIso8601String(),
                ])->values()->all(),
            ];
        }

        return $out;
    }
}
