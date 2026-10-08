<?php

namespace App\Http\Resources\Marketplace;

use App\Models\MarketplacePromotionPackage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A fixed-price, fixed-duration promotion package.
 *
 * @property MarketplacePromotionPackage $resource
 */
class PromotionPackageResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $p = $this->resource;

        return [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'description' => $p->description, 'duration_days' => $p->duration_days,
            'amount' => (string) $p->amount, 'currency' => $p->currency, 'is_active' => $p->is_active, 'sort_order' => $p->sort_order,
        ];
    }
}
