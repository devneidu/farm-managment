<?php

namespace App\Http\Resources\Marketplace;

use App\Models\MarketplaceShopMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property MarketplaceShopMember $resource */
class ShopMemberResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $m = $this->resource;

        return [
            'id' => $m->id, 'role' => $m->role->value,
            'user' => ['id' => $m->user_id, 'name' => $m->user?->name, 'email' => $m->user?->email],
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
