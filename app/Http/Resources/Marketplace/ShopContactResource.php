<?php

namespace App\Http\Resources\Marketplace;

use App\Models\MarketplaceShop;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The seller's PRIVATE contact configuration. Returned only to members with `shop.manage_contact`, and to nobody through a public endpoint.
 *
 * @property MarketplaceShop $resource
 */
class ShopContactResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $s = $this->resource;

        return [
            'address_line' => $s->address_line, 'contact_phone' => $s->contact_phone, 'contact_whatsapp' => $s->contact_whatsapp,
            'contact_email' => $s->contact_email, 'preferred_contact_method' => $s->preferred_contact_method,
        ];
    }
}
