<?php

namespace App\Http\Resources\Marketplace;

use App\Models\MarketplaceListingImage;
use App\Services\Marketplace\MarketplaceImageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property MarketplaceListingImage $resource */
class ListingImageResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $i = $this->resource;

        return [
            'id' => $i->id, 'url' => app(MarketplaceImageService::class)->photoUrl($i, 'member'), 'alt_text' => $i->alt_text, 'position' => $i->position,
            'width' => $i->width, 'height' => $i->height, 'mime_type' => $i->mime_type, 'size' => $i->size, 'original_name' => $i->original_name,
            'kind' => 'seller', 'illustrative' => false, 'created_at' => $i->created_at?->toIso8601String(),
        ];
    }
}
