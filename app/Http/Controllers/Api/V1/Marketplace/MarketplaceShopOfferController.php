<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Api\V1\Platform\Paginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\ListShopOffersRequest;
use App\Http\Resources\Marketplace\OfferResource;
use App\Http\Resources\Marketplace\PurchaseIntentResource;
use App\Services\Marketplace\MarketplaceOfferDirectory;
use App\Services\Marketplace\MarketplaceOfferService;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The seller's side of negotiation, always inside a shop the caller belongs to (`404` for another shop's offer). Shop role permissions: `offer.view`
 * (owner, manager, staff) reads; `offer.respond` (owner, manager) accepts or rejects. The buyer appears by display name only. Accepting an offer reserves
 * no stock and shares no contact details.
 */
class MarketplaceShopOfferController extends Controller
{
    use Paginates;

    /**
     * List my shop's offers
     *
     * Needs `offer.view`. Newest first. Filters: `status` (`expired` includes pending offers past their deadline; `pending` excludes them), `listing` (id).
     * Paginated. Buyers appear by display name only.
     *
     * @response array{data: OfferResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: string|null}
     */
    public function index(ListShopOffersRequest $request, string $shop, MarketplaceOfferDirectory $directory): JsonResponse
    {
        $page = $directory->shopOffers($request->user(), $shop, $request->validated());

        return $this->page($page, OfferResource::collection($page->getCollection())->map(fn ($r) => $r->audience('seller'))->map->resolve($request)->all());
    }

    /**
     * Show an offer
     *
     * Needs `offer.view`. Includes the `history`, the listing snapshot taken when the offer was made and `respondable`.
     *
     * @response array{data: OfferResource, meta: object, message: string|null}
     */
    public function show(Request $request, string $shop, string $offer, MarketplaceOfferDirectory $directory): JsonResponse
    {
        return ApiResponse::success((new OfferResource($directory->shopOffer($request->user(), $shop, $offer)))->audience('seller')->detailed()->resolve($request));
    }

    /**
     * Accept an offer
     *
     * Needs `offer.respond` (owner, manager; staff get `403`). Only a PENDING, unexpired offer on a currently LIVE listing of an ACTIVE shop whose snapshot
     * still matches the listing. Acceptance is a terminal agreement in principle: it reserves no stock, guarantees no availability, posts no sale and
     * reveals no contact. Repeating an accept that already succeeded returns `200` unchanged. Errors: `409 offer_expired` (the lapse is recorded),
     * `409 offer_voided` (the listing changed; the offer is voided and the buyer's attempt refunded), `409 offer_not_pending` (already rejected),
     * `409 listing_unavailable` (paused, archived or restricted - retry once live), `409 shop_not_active`. Audited as `marketplace.offer_accepted`.
     *
     * @response array{data: OfferResource, meta: object, message: string|null}
     */
    #[Response(status: 409, description: 'Conflict (see the endpoint description for the `code`)', type: 'array{message: string, code: string, request_id: string, details?: object}')]
    public function accept(Request $request, string $shop, string $offer, MarketplaceOfferService $offers): JsonResponse
    {
        return ApiResponse::success((new OfferResource($offers->accept($request->user(), $shop, $offer)))->audience('seller')->detailed()->resolve($request), message: 'Offer accepted.');
    }

    /**
     * Reject an offer
     *
     * Needs `offer.respond`. Allowed whenever the offer is pending, even while the listing is paused. A rejected offer still counts as one of the buyer's
     * attempts. Repeating a reject that already succeeded returns `200` unchanged; rejecting an accepted offer is `409 offer_not_pending`; a lapsed offer is
     * `409 offer_expired`. Audited as `marketplace.offer_rejected`.
     *
     * @response array{data: OfferResource, meta: object, message: string|null}
     */
    #[Response(status: 409, description: 'Conflict (see the endpoint description for the `code`)', type: 'array{message: string, code: string, request_id: string, details?: object}')]
    public function reject(Request $request, string $shop, string $offer, MarketplaceOfferService $offers): JsonResponse
    {
        return ApiResponse::success((new OfferResource($offers->reject($request->user(), $shop, $offer)))->audience('seller')->detailed()->resolve($request), message: 'Offer rejected.');
    }

    /**
     * List purchase intents
     *
     * Needs `offer.view`. Buyers who chose "Proceed at listed price" on this shop's listings, most recently recorded first. Interest only: nothing is accepted,
     * reserved or paid. Filter: `listing` (id). Paginated.
     *
     * @response array{data: PurchaseIntentResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: string|null}
     */
    public function intents(ListShopOffersRequest $request, string $shop, MarketplaceOfferDirectory $directory): JsonResponse
    {
        $page = $directory->shopIntents($request->user(), $shop, $request->validated());

        return $this->page($page, $page->getCollection()->map(fn ($i) => (new PurchaseIntentResource($i))->audience('seller')->resolve($request))->all());
    }
}
