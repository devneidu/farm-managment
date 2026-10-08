<?php

namespace App\Http\Resources\Marketplace;

use App\Enums\ShopPermission;
use App\Enums\ShopStatus;
use App\Models\MarketplaceListing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A listing as the members of its shop see it: every field, its lifecycle, a live-visibility flag and (on the detail view) the optional inventory
 * link and the lifecycle history. The private farm link is shown to these authorised members only; no public resource ever includes it.
 *
 * @property MarketplaceListing $resource
 */
class ListingResource extends JsonResource
{
    public static $wrap = null;

    /** @var array<string, mixed>|null */
    private ?array $inventory = null;

    private bool $detailed = false;

    /** @param  array<string, mixed>|null  $inventory */
    public function detailed(?array $inventory): static
    {
        $this->detailed = true;
        $this->inventory = $inventory;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $l = $this->resource;
        $shop = $l->relationLoaded('shop') ? $l->shop : null;
        $viewer = $shop && $shop->relationLoaded('viewer') ? $shop->getRelation('viewer') : null;
        $live = $l->status->isPublished() && $shop?->status === ShopStatus::Active;

        $out = [
            'id' => $l->id, 'reference' => $l->reference, 'slug' => $l->slug, 'shop_id' => $l->shop_id, 'version' => $l->version,
            'status' => $l->status->value,
            // Published listings of a shop that is not active are hidden from the public at once; the listing itself is untouched.
            'is_public' => $live, 'hidden_because' => $l->status->isPublished() && ! $live ? 'shop_not_active' : null,
            'title' => $l->title, 'description' => $l->description,
            'product' => ListingPresenter::product($l),
            'price' => ListingPresenter::price($l), 'quantity' => ListingPresenter::quantity($l), 'negotiable' => $l->negotiable,
            'package' => ListingPresenter::package($l),
            'fulfilment' => ListingPresenter::fulfilment($l), 'location' => ListingPresenter::location($l),
            'image' => ListingPresenter::image($l, 'member'), 'catalog_image_id' => $l->catalog_image_id, 'images' => ListingPresenter::photos($l, 'member'),
            'inventory_linked' => $l->inventory_item_id !== null,
            'restriction' => $l->status->value === 'restricted' ? ['reason' => $l->restricted_reason, 'at' => $l->restricted_at?->toIso8601String()] : null,
            'abilities' => $viewer ? [
                'edit' => $viewer->can(ShopPermission::ListingManage) && $l->status->value === 'draft' || $viewer->can(ShopPermission::ListingPublish) && in_array($l->status->value, ['published', 'paused'], true),
                'publish' => $viewer->can(ShopPermission::ListingPublish),
            ] : null,
            'published_at' => $l->published_at?->toIso8601String(), 'paused_at' => $l->paused_at?->toIso8601String(), 'archived_at' => $l->archived_at?->toIso8601String(),
            'created_at' => $l->created_at?->toIso8601String(), 'updated_at' => $l->updated_at?->toIso8601String(),
        ];

        if ($this->detailed) {
            $out['inventory'] = $this->inventory;
            $out['history'] = $l->relationLoaded('events') ? $l->events->map(fn ($e) => [
                'action' => $e->action, 'from' => $e->from_status, 'to' => $e->to_status, 'actor_kind' => $e->actor_kind, 'reason' => $e->reason, 'at' => $e->created_at?->toIso8601String(),
            ])->values()->all() : [];
        }

        return $out;
    }
}
