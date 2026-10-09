<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Api\V1\Platform\Paginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\ListPublicShopsRequest;
use App\Http\Resources\Marketplace\PublicShopResource;
use App\Services\Marketplace\MarketplaceDirectory;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Anonymous shop discovery. Authentication is not required (and a session, if present, changes nothing). Only ACTIVE shops exist here.
 */
class MarketplacePublicController extends Controller
{
    use Paginates;

    /**
     * Discover shops
     *
     * Public, no authentication. Lists ACTIVE (approved and open) shops only; draft, pending, rejected, suspended and closed shops never appear.
     * Filters: `q` (name/tagline/description), `state`, `city`, `category`, `seller_type`, `verified`; `sort=newest|name`. Paginated (`per_page` ≤ 50).
     * Private seller contact details, owner identity and farm ids are never returned: `contact_methods` lists channel names only.
     *
     * @response array{data: PublicShopResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: string|null}
     */
    public function index(ListPublicShopsRequest $request, MarketplaceDirectory $directory): JsonResponse
    {
        $page = $directory->list($request->validated());

        return $this->page($page, PublicShopResource::collection($page->getCollection())->resolve($request));
    }

    /**
     * Show a shop
     *
     * Public, no authentication. Addressed by the immutable `slug`. `404 not_found` for any shop that is not active - a suspended or unapproved
     * shop is indistinguishable from one that does not exist.
     *
     * @response array{data: PublicShopResource, meta: object, message: string|null}
     */
    public function show(Request $request, string $slug, MarketplaceDirectory $directory): JsonResponse
    {
        return ApiResponse::success((new PublicShopResource($directory->find($slug)))->resolve($request));
    }
}
