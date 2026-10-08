<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\ListImageLibraryRequest;
use App\Services\Marketplace\MarketplaceListingDirectory;
use App\Services\Marketplace\MarketplaceProductCatalogue;
use App\Support\Api\ApiResponse;
use App\Support\Api\ApiRoute;
use Illuminate\Http\JsonResponse;

/** The farm-less lookups a seller needs to build a listing: what can be sold in which unit, and the reusable illustrative image library. */
class MarketplaceCatalogueController extends Controller
{
    /**
     * Product options
     *
     * Needs sign-in and a verified email; NO farm. One call returns everything the listing form needs: the product kinds (livestock, fish, crop_produce,
     * eggs, milk, feed, other), for each the selling units allowed (with `integer_only`, `decimal_places` and `requires_package_details`) and the existing
     * species / crop types to pick from (master data, not a second taxonomy; `allows_custom_name` when nothing fits), plus the fulfilment, dispatch and
     * delivery-charge vocabularies, the package content units and the limits.
     *
     * @response array{data: array{kinds: array<int, mixed>, fulfilment: array<int, mixed>, dispatch_estimates: array<int, mixed>, delivery_charges: array<int, mixed>, package_content_units: array<int, mixed>, currency: string, limits: object}, meta: object, message: string|null}
     */
    public function productOptions(MarketplaceProductCatalogue $catalogue): JsonResponse
    {
        return ApiResponse::success($catalogue->options());
    }

    /**
     * Image library
     *
     * Needs sign-in and a verified email. The reusable ILLUSTRATIVE image catalogue (`illustrative: true`; never an actual product photo). Only entries that
     * have an asset appear: until the licensed assets are seeded this list is empty and listings fall back to a placeholder indicator (the picker is
     * optional). `kind_fallback` entries are the category-level fallbacks. Filter with `product_kind`. Pass an entry's `id` as `catalog_image_id`.
     *
     * @response array{data: array<int, array{id: string, code: string, label: string, product_kind: string, kind_fallback: bool, illustrative: bool, alt_text: string, url: string}>, meta: object, message: string|null}
     */
    public function imageLibrary(ListImageLibraryRequest $request, MarketplaceListingDirectory $directory): JsonResponse
    {
        return ApiResponse::success($directory->library($request->validated('product_kind'))->map(fn ($i) => [
            'id' => $i->id, 'code' => $i->code, 'label' => $i->label, 'product_kind' => $i->product_kind, 'kind_fallback' => $i->is_kind_fallback,
            'illustrative' => true, 'alt_text' => $i->alt_text, 'url' => ApiRoute::url('/public/marketplace/catalogue-images/'.$i->code),
        ])->values()->all());
    }
}
