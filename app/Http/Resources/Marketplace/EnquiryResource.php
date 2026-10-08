<?php

namespace App\Http\Resources\Marketplace;

use App\Models\MarketplaceOffer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of a buyer's enquiry history: an offer or a purchase intent, told apart by `kind`.
 *
 * @mixin MarketplaceOffer
 */
class EnquiryResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource instanceof MarketplaceOffer
            ? (new OfferResource($this->resource))->toArray($request)
            : (new PurchaseIntentResource($this->resource))->toArray($request);
    }
}
