<?php

namespace App\Http\Resources\Marketplace;

use App\Models\MarketplaceListing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The anonymous view of a PUBLISHED listing of an ACTIVE shop. An explicit allow-list: no farm id, inventory link or live stock, no creator or member
 * identity, no version, no private contact values or address, no moderation data. Quantity is labelled `seller_declared`.
 *
 * @property MarketplaceListing $resource
 */
class PublicListingResource extends JsonResource
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

        $out = [
            'id' => $l->id, 'reference' => $l->reference, 'slug' => $l->slug,
            'title' => $l->title, 'description' => $l->description,
            'product' => ListingPresenter::product($l),
            'price' => ListingPresenter::price($l), 'quantity' => ListingPresenter::quantity($l), 'negotiable' => $l->negotiable,
            'package' => ListingPresenter::package($l),
            'fulfilment' => ListingPresenter::fulfilment($l), 'location' => ListingPresenter::location($l),
            'image' => ListingPresenter::image($l, 'public'),
            'shop' => (new PublicShopResource($l->shop))->toArray($request),
            'published_at' => $l->published_at?->toIso8601String(),
        ];
        if ($this->detailed) {
            $out['images'] = ListingPresenter::photos($l, 'public');
        }

        return $out;
    }
}
