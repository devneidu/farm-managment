<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Api\V1\Platform\Paginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\ListMyEnquiriesRequest;
use App\Http\Requests\Marketplace\PurchaseIntentRequest;
use App\Http\Requests\Marketplace\SubmitOfferRequest;
use App\Http\Resources\Marketplace\EnquiryResource;
use App\Http\Resources\Marketplace\OfferResource;
use App\Http\Resources\Marketplace\PurchaseIntentResource;
use App\Services\Marketplace\MarketplaceOfferDirectory;
use App\Services\Marketplace\MarketplaceOfferService;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The buyer's side of negotiation. Needs sign-in and a verified email only - no farm. A listing that is not public right now (draft, paused, archived,
 * restricted, or its shop not active) answers `404`, exactly like one that does not exist. A buyer only ever sees their own offers.
 *
 * This is controlled negotiation, not chat or checkout: an offer carries a quantity and a unit price and nothing else, and nothing is reserved, charged
 * or sold. Seller contact details are never returned here.
 */
class MarketplaceOfferController extends Controller
{
    use Paginates;

    /**
     * Make an offer
     *
     * Only on a `negotiable` listing. Body: `quantity` (decimal string, the unit's precision, within minimum order and declared availability) and `unit_price`
     * (naira per unit, at most 2 decimals). The price must be STRICTLY BELOW the listed unit price and at least `marketplace_min_offer_percent` (default 70%)
     * of it - exact decimal arithmetic on the unit price, not the order total. Everything that can be rejected for its own reason (`422` on `quantity` or
     * `unit_price`, `404`, `403`, `409 listing_not_negotiable`) is rejected BEFORE an attempt is used.
     *
     * One open offer at a time (`409 offer_pending`); at most `marketplace_max_offers_per_buyer` (default 3) per listing, where pending, accepted, rejected
     * and expired offers count and voided ones do not (`409 offer_limit_reached`, `details.max_attempts`, `details.attempts_used`); no more once the seller
     * accepted one (`409 offer_already_accepted`). A shop member (or the owner of its linked farm) cannot offer on their own listing
     * (`403 cannot_negotiate_own_listing`). The offer expires after `marketplace_offer_expiry_hours` (default 48h) and the response carries `expires_at`.
     * The listing is snapshotted on the offer. Audited as `marketplace.offer_submitted`.
     */
    #[Response(status: 201, description: 'Created', type: 'array{data: \App\Http\Resources\Marketplace\OfferResource, meta: object, message: string|null}')]
    #[Response(status: 409, description: 'Conflict (see the endpoint description for the `code`)', type: 'array{message: string, code: string, request_id: string, details?: object}')]
    public function store(SubmitOfferRequest $request, string $slug, MarketplaceOfferService $offers): JsonResponse
    {
        $offer = $offers->submit($request->user(), $slug, $request->validated());

        return ApiResponse::success((new OfferResource($offer))->detailed()->resolve($request), message: 'Offer sent.', status: 201);
    }

    /**
     * My negotiation status on a listing
     *
     * Everything a buyer UI needs before showing the offer form: `rules` (max attempts, attempts used and remaining, the price floor as `min_offer_percent`
     * and the resulting `minimum_unit_price`, the offer expiry in hours), `can_offer` with the `blocked_reason` when false (`listing_not_negotiable`,
     * `offer_already_accepted`, `offer_pending`, `offer_limit_reached`), the open `pending_offer`, and any recorded `purchase_intent`. `404` for a listing that
     * is not public.
     */
    public function status(Request $request, string $slug, MarketplaceOfferDirectory $directory): JsonResponse
    {
        return ApiResponse::success($directory->status($request->user(), $directory->liveListing($slug)));
    }

    /**
     * Proceed at the listed price
     *
     * Records the buyer's INTEREST in buying `quantity` at the listed unit price. It is not an acceptance, a payment, an order or a deal: nothing is reserved,
     * charged or deducted, and the seller must still confirm before any deal or contact exchange. Allowed on negotiable and fixed-price listings alike.
     * Idempotent per buyer and listing: repeating with the same quantity returns the same record (`200`); a different quantity refreshes it (`200`); the first
     * call is `201`. `is_current` is computed on read. Same `404` / `403 cannot_negotiate_own_listing` / `422 quantity` rules as an offer.
     * Audited as `marketplace.purchase_intent_recorded`.
     */
    #[Response(status: 201, description: 'Created', type: 'array{data: \App\Http\Resources\Marketplace\PurchaseIntentResource, meta: object, message: string|null}')]
    public function intent(PurchaseIntentRequest $request, string $slug, MarketplaceOfferService $offers): JsonResponse
    {
        [$intent, $created] = $offers->recordIntent($request->user(), $slug, $request->validated('quantity'));

        return ApiResponse::success((new PurchaseIntentResource($intent))->resolve($request), message: $created ? 'Interest recorded.' : 'Interest already recorded.', status: $created ? 201 : 200);
    }

    /**
     * My enquiries
     *
     * The caller's offers and purchase intents together, newest first. Each row has a `kind` (`offer` | `purchase_intent`) and an `agreement`
     * (`seller_accepted` | `none`). Filters: `kind`, `status` (offers; `expired` includes pending offers past their deadline). Paginated. Statuses are
     * effective: a lapsed pending offer reads `expired`.
     *
     * @response array{data: EnquiryResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: string|null}
     */
    public function index(ListMyEnquiriesRequest $request, MarketplaceOfferDirectory $directory): JsonResponse
    {
        $page = $directory->enquiries($request->user(), $request->validated());

        return $this->page($page, EnquiryResource::collection($page->getCollection())->resolve($request));
    }

    /**
     * Show my offer
     *
     * Includes the `history` of the offer. `404` for an offer that is not the caller's. An accepted offer has `agreement: seller_accepted` but
     * `contact` stays `null`: contact exchange arrives with deals in a later phase.
     *
     * @response array{data: OfferResource, meta: object, message: string|null}
     */
    public function show(Request $request, string $offer, MarketplaceOfferDirectory $directory): JsonResponse
    {
        return ApiResponse::success((new OfferResource($directory->myOffer($request->user(), $offer)))->detailed()->resolve($request));
    }
}
