<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Api\V1\Platform\Paginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\ListPublicListingsRequest;
use App\Http\Requests\Marketplace\PricePreviewRequest;
use App\Http\Resources\Marketplace\PublicListingResource;
use App\Services\Marketplace\MarketplaceImageService;
use App\Services\Marketplace\MarketplaceListingDirectory;
use App\Services\Marketplace\MarketplaceListingService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Anonymous listing discovery. No authentication; a session, if present, changes nothing. Only PUBLISHED listings of ACTIVE shops exist here.
 */
class MarketplacePublicListingController extends Controller
{
    use Paginates;

    /**
     * Discover listings
     *
     * Public, no authentication, throttled. Only `published` listings of `active` shops: a draft, paused, archived or restricted listing, and every listing of
     * a suspended or closed shop, never appears. Filters: `q`, `product_kind`, `species`, `crop_type` (master-data codes), `state`, `city`, `min_price`,
     * `max_price` (naira per unit; combine with `unit` - prices of different units are not comparable), `unit`, `negotiable`, `fulfilment`
     * (pickup | seller_delivery), `delivers_to`, `shop` (slug), `verified`. Sort: `newest` (default), `price_asc`, `price_desc`, `relevance`. Paginated
     * (`per_page` <= 50). Quantities are `seller_declared`, never live stock. Contact details, addresses, farm ids and inventory are never returned.
     *
     * @response array{data: PublicListingResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: string|null}
     */
    public function index(ListPublicListingsRequest $request, MarketplaceListingDirectory $directory): JsonResponse
    {
        $page = $directory->list($request->validated());

        return $this->page($page, PublicListingResource::collection($page->getCollection())->resolve($request));
    }

    /**
     * Show a listing
     *
     * Public, addressed by the immutable `slug`. `404 not_found` for anything that is not currently public. Includes all photos in order.
     *
     * @response array{data: PublicListingResource, meta: object, message: string|null}
     */
    public function show(Request $request, string $slug, MarketplaceListingDirectory $directory): JsonResponse
    {
        return ApiResponse::success((new PublicListingResource($directory->find($slug)))->detailed()->resolve($request));
    }

    /**
     * Price estimate
     *
     * Public. `GET ?quantity=` returns a NON-BINDING total (`unit_price x quantity`, exact decimals, rounded to kobo). The quantity must respect the unit's
     * decimal places, the minimum order and the declared available quantity (`422`). Nothing is reserved or agreed.
     *
     * @response array{data: array{quantity: string, unit: string, unit_price: string, currency: string, binding: bool, note: string, total: string, total_minor: int}, meta: object, message: string|null}
     */
    public function preview(PricePreviewRequest $request, string $slug, MarketplaceListingDirectory $directory, MarketplaceListingService $listings): JsonResponse
    {
        return ApiResponse::success($listings->preview($directory->find($slug), $request->validated('quantity')));
    }

    /**
     * Listing photo file
     *
     * Public. Streams a seller photo, but only while its listing is publicly visible (`404` otherwise). Served with `X-Content-Type-Options: nosniff`.
     */
    public function image(string $image, MarketplaceListingDirectory $directory, MarketplaceImageService $images): StreamedResponse
    {
        $row = $directory->publicImage($image);

        return $images->respond($row->path, $row->mime_type);
    }

    /**
     * Catalogue image file
     *
     * Public. Streams an ILLUSTRATIVE catalogue image by `code`. `404` while the entry has no asset yet (awaiting asset) or is inactive.
     */
    public function catalogueImage(string $code, MarketplaceListingDirectory $directory, MarketplaceImageService $images): StreamedResponse
    {
        $row = $directory->catalogueImage($code);

        return $images->respond($row->asset_path, $row->mime_type ?? 'image/webp');
    }
}
