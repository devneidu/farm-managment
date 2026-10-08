<?php

namespace App\Http\Resources\Marketplace;

use App\Models\MarketplaceSellerPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A marketplace seller plan with its prices. Money is a decimal string in naira.
 *
 * @property MarketplaceSellerPlan $resource
 */
class SellerPlanResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $p = $this->resource;

        return [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'description' => $p->description, 'listing_limit' => $p->listing_limit, 'is_free' => $p->is_free,
            'is_active' => $p->is_active, 'sort_order' => $p->sort_order,
            'prices' => $p->prices->map(fn ($x) => ['interval_days' => $x->interval_days, 'amount' => (string) $x->amount, 'currency' => $x->currency, 'is_active' => $x->is_active])->values()->all(),
        ];
    }
}
