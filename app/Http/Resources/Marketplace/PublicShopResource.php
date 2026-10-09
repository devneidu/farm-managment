<?php

namespace App\Http\Resources\Marketplace;

use App\Models\MarketplaceShop;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The anonymous view of an ACTIVE shop. An explicit allow-list: no owner/member identity, farm id, private contact values, address,
 * lifecycle reasons, internal ids or audit data. `contact_methods` names the channels that exist, never their values.
 *
 * @property MarketplaceShop $resource
 */
class PublicShopResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $s = $this->resource;

        return [
            'id' => $s->id, 'slug' => $s->slug, 'name' => $s->name, 'tagline' => $s->tagline, 'description' => $s->description,
            'seller_type' => $s->seller_type, 'categories' => $s->categories,
            'location' => ['country_code' => $s->country_code, 'state' => $s->state, 'city' => $s->city, 'area' => $s->area],
            'verified' => $s->verification_status->value === 'verified', 'verified_at' => $s->verified_at?->toIso8601String(),
            'farm_backed' => $s->farm_id !== null,
            'contact_methods' => $s->contactMethods(),
            'member_since' => $s->approved_at?->toDateString(),
        ];
    }
}
