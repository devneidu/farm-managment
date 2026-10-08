<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\ListingRestrictionRequest;
use App\Http\Requests\Marketplace\ListPlatformListingsRequest;
use App\Http\Resources\Marketplace\PlatformListingResource;
use App\Services\Marketplace\MarketplaceImageService;
use App\Services\Marketplace\MarketplaceListingModerationService;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Platform oversight of marketplace listings. Reads: any platform role. Writes: role `admin`. Restricting a listing IS hiding it; nothing is deleted.
 * Every write is audited (`platform.marketplace_listing_*`) and appended to the listing history.
 */
class PlatformMarketplaceListingController extends Controller
{
    use Paginates;

    /**
     * List listings
     *
     * Every listing in any state (soft-deleted drafts excluded), newest first. Filters: `q`, `status`, `shop_id`, `product_kind`. Rows show whether the
     * listing is currently public and why not (`hidden_because: shop_not_active`).
     *
     * @response array{data: PlatformListingResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: string|null}
     */
    public function index(ListPlatformListingsRequest $request, MarketplaceListingModerationService $moderation): JsonResponse
    {
        $page = $moderation->list($request->validated());

        return $this->page($page, PlatformListingResource::collection($page->getCollection())->resolve($request));
    }

    /**
     * Show a listing
     *
     * Full detail with photos, fulfilment and the append-only lifecycle `history` (seller and platform actions with reasons).
     *
     * @response array{data: PlatformListingResource, meta: object, message: string|null}
     */
    public function show(Request $request, string $listing, MarketplaceListingModerationService $moderation): JsonResponse
    {
        return ApiResponse::success((new PlatformListingResource($moderation->show($listing)))->detailed()->resolve($request));
    }

    /**
     * Restrict a listing
     *
     * Admin write. `draft` | `published` | `paused` -> `restricted` with a required `reason`: removed from the public feed at once and frozen against seller
     * edits and publishing; the seller sees the reason. Nothing is deleted. Repeating it is a no-op. `409 invalid_listing_state` (archived).
     * Audited as `platform.marketplace_listing_restricted`.
     *
     * @response array{data: PlatformListingResource, meta: object, message: string|null}
     */
    #[Response(status: 409, description: 'Conflict (see the endpoint description for the `code`)', type: 'array{message: string, code: string, request_id: string, details?: object}')]
    public function restrict(ListingRestrictionRequest $request, string $listing, MarketplaceListingModerationService $moderation): JsonResponse
    {
        return ApiResponse::success((new PlatformListingResource($moderation->restrict($request->user(), $listing, $request->validated('reason'))))->detailed()->resolve($request));
    }

    /**
     * Lift a restriction
     *
     * Admin write. `restricted` -> `paused`. The listing does NOT go live again by itself: the seller (owner/manager) publishes it. Repeating it is a no-op.
     * `409 invalid_listing_state`. Audited as `platform.marketplace_listing_restriction_lifted`.
     *
     * @response array{data: PlatformListingResource, meta: object, message: string|null}
     */
    #[Response(status: 409, description: 'Conflict (see the endpoint description for the `code`)', type: 'array{message: string, code: string, request_id: string, details?: object}')]
    public function lift(ListingRestrictionRequest $request, string $listing, MarketplaceListingModerationService $moderation): JsonResponse
    {
        return ApiResponse::success((new PlatformListingResource($moderation->lift($request->user(), $listing, $request->validated('reason'))))->detailed()->resolve($request));
    }

    /**
     * Fetch a listing photo
     *
     * Streams a stored seller photo for review, whatever the listing's state.
     */
    public function image(string $listing, string $image, MarketplaceListingModerationService $moderation, MarketplaceImageService $images): StreamedResponse
    {
        $row = $moderation->image($listing, $image);

        return $images->respond($row->path, $row->mime_type);
    }
}
