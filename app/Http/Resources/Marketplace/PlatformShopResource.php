<?php

namespace App\Http\Resources\Marketplace;

use App\Models\MarketplaceShop;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Platform-admin view for moderation: the shop, its owner and its lifecycle. The private contact configuration (phone, WhatsApp, email,
 * address) appears on the detail view only, so support can reach the seller during review; lists never carry it.
 *
 * @property MarketplaceShop $resource
 */
class PlatformShopResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $s = $this->resource;
        $owner = $s->members->firstWhere('role.value', 'owner')?->user;

        return (new ShopResource($s))->toArray($request) + [
            'owner' => $owner ? ['id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email] : null,
            'farm_id' => $s->farm_id, 'suspended_at' => $s->suspended_at?->toIso8601String(), 'closed_at' => $s->closed_at?->toIso8601String(),
        ] + ($request->routeIs('api.v1.platform.marketplace.shops.index') ? [] : ['contact' => (new ShopContactResource($s))->toArray($request)]);
    }
}
