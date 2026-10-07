<?php

namespace App\Http\Resources\Marketplace;

use App\Models\MarketplaceShop;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A shop as its own members see it: profile, lifecycle and the caller's role/permissions. The PRIVATE contact configuration is never part
 * of this resource (see ShopContactResource), only the list of configured channel names.
 *
 * @property MarketplaceShop $resource
 */
class ShopResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $s = $this->resource;
        $viewer = $s->relationLoaded('viewer') ? $s->getRelation('viewer') : null;

        return [
            'id' => $s->id, 'reference' => $s->reference, 'slug' => $s->slug,
            'name' => $s->name, 'tagline' => $s->tagline, 'description' => $s->description, 'seller_type' => $s->seller_type, 'categories' => $s->categories,
            'location' => ['country_code' => $s->country_code, 'state' => $s->state, 'city' => $s->city, 'area' => $s->area],
            'farm_backed' => $s->farm_id !== null,
            'contact_methods' => $s->contactMethods(), 'preferred_contact_method' => $s->preferred_contact_method,
            'status' => $s->status->value, 'status_reason' => $s->status_reason, 'is_public' => $s->status->isPublic(),
            'submitted_at' => $s->submitted_at?->toIso8601String(), 'approved_at' => $s->approved_at?->toIso8601String(),
            'verification' => [
                'status' => $s->verification_status->value, 'reason' => $s->verification_reason,
                'requested_at' => $s->verification_requested_at?->toIso8601String(), 'verified_at' => $s->verified_at?->toIso8601String(),
            ],
            'viewer' => $viewer ? ['role' => $viewer->role->value, 'permissions' => array_map(fn ($p) => $p->value, $viewer->role->permissions())] : null,
            'created_at' => $s->created_at?->toIso8601String(), 'updated_at' => $s->updated_at?->toIso8601String(),
        ];
    }
}
