<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Api\V1\Platform\Paginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\AcceptDealConfirmationRequest;
use App\Http\Requests\Marketplace\CancelDealRequest;
use App\Http\Requests\Marketplace\ConfirmOfferDealRequest;
use App\Http\Requests\Marketplace\ListDealsRequest;
use App\Http\Requests\Marketplace\ReportDealRequest;
use App\Http\Resources\Marketplace\DealConfirmationResource;
use App\Http\Resources\Marketplace\DealReportResource;
use App\Http\Resources\Marketplace\DealResource;
use App\Services\Marketplace\MarketplaceDealContact;
use App\Services\Marketplace\MarketplaceDealDirectory;
use App\Services\Marketplace\MarketplaceDealLifecycle;
use App\Services\Marketplace\MarketplaceDealService;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The buyer's side of deals. Needs sign-in and a verified email only - no farm. A buyer reaches only their own deals (`404` for anyone else's).
 *
 * A deal is a SUMMARY of what the buyer and seller agreed: product, quantity, unit price, product total, fulfilment and delivery arrangement, all frozen.
 * Farmvest is not an escrow, payment processor or logistics provider: it collects no payment, holds no funds, reserves or deducts no stock and creates no
 * sale or invoice. Payment and transport are arranged directly between the parties. Contact details appear in NO payload here except the dedicated,
 * audited `contact` endpoint.
 */
class MarketplaceDealController extends Controller
{
    use Paginates;

    /**
     * Confirm an accepted offer as a deal
     *
     * Only the BUYER can do this, for an offer the seller already accepted (Phase 24), within `marketplace_deal_confirmation_hours` (default 72) of acceptance
     * (`offer.deal_confirmation.deadline`). The deal keeps the offer's agreed quantity, unit price and total exactly; later listing edits never change it.
     * Body: `fulfilment_method` (`pickup` | `seller_delivery`; required only when the listing offers both) and an optional `contact_phone` for this deal
     * (email is the fallback). On a delivery deal whose charge is "agreed separately" the charge is stored as `null` and shown as "To be agreed directly";
     * it is never added to the product total. Contact details become available through the `contact` endpoint from this moment.
     *
     * Checked under lock: the offer is `accepted` and unanswered, the shop is active, the listing is live and still sells the same product in the same unit,
     * and the quantity still fits what the seller DECLARES available (nothing is reserved). Idempotent: a repeat returns the same deal (`200`; the first call
     * is `201`). Errors: `409 offer_not_accepted`, `409 deal_window_closed`, `409 shop_not_active`, `409 listing_unavailable`, `409 deal_terms_stale`,
     * `409 quantity_unavailable`, `409 seller_contact_unavailable`, `422 fulfilment_method` / `contact_phone`, `404` (not your offer).
     * Audited as `marketplace.deal_created`.
     */
    #[Response(status: 201, description: 'Created', type: 'array{data: \App\Http\Resources\Marketplace\DealResource, meta: object, message: string|null}')]
    #[Response(status: 409, description: 'Conflict (see the endpoint description for the `code`)', type: 'array{message: string, code: string, request_id: string, details?: object}')]
    public function confirmOffer(ConfirmOfferDealRequest $request, string $offer, MarketplaceDealService $deals): JsonResponse
    {
        [$deal, $created] = $deals->confirmOffer($request->user(), $offer, $request->validated());

        return ApiResponse::success((new DealResource($deal))->audience('buyer')->detailed()->resolve($request), message: $created ? 'Deal confirmed.' : 'Deal already confirmed.', status: $created ? 201 : 200);
    }

    /**
     * Show a seller confirmation
     *
     * The terms the seller confirmed for the caller's fixed-price purchase request, with `confirmable` and `expires_at`. NOT an agreement and no contact is
     * shared until the buyer confirms it. `404` for someone else's. A lapsed one reads `lapsed`.
     *
     * @response array{data: DealConfirmationResource, meta: object, message: string|null}
     */
    public function showConfirmation(Request $request, string $confirmation, MarketplaceDealDirectory $directory): JsonResponse
    {
        return ApiResponse::success((new DealConfirmationResource($directory->buyerConfirmation($request->user(), $confirmation)))->audience('buyer')->resolve($request));
    }

    /**
     * Confirm a seller's confirmation as a deal
     *
     * The buyer's step for a FIXED-PRICE purchase: after the seller confirmed the request, the buyer confirms those exact terms. Body: `accept_terms: true`
     * and an optional `contact_phone` (email is the fallback). The terms (quantity, unit price, total, fulfilment method, any delivery charge) come from the
     * seller's confirmation and cannot be changed here. This is the moment the deal exists and contact becomes available. Idempotent (`201` first, then `200`).
     * Errors: `409 confirmation_lapsed` (past `expires_at`, recorded), `409 confirmation_withdrawn`, `409 confirmation_voided`, `409 confirmation_stale` (the
     * listed price, unit, product or offered fulfilment changed; the confirmation is voided), `409 shop_not_active`, `409 listing_unavailable`,
     * `409 quantity_unavailable`, `409 seller_contact_unavailable`, `422 accept_terms` / `contact_phone`. Audited as `marketplace.deal_created`.
     */
    #[Response(status: 201, description: 'Created', type: 'array{data: \App\Http\Resources\Marketplace\DealResource, meta: object, message: string|null}')]
    #[Response(status: 409, description: 'Conflict (see the endpoint description for the `code`)', type: 'array{message: string, code: string, request_id: string, details?: object}')]
    public function acceptConfirmation(AcceptDealConfirmationRequest $request, string $confirmation, MarketplaceDealService $deals): JsonResponse
    {
        [$deal, $created] = $deals->acceptConfirmation($request->user(), $confirmation, $request->validated());

        return ApiResponse::success((new DealResource($deal))->audience('buyer')->detailed()->resolve($request), message: $created ? 'Deal confirmed.' : 'Deal already confirmed.', status: $created ? 201 : 200);
    }

    /**
     * My deals
     *
     * The caller's deals, newest first. Filter: `status` (`accepted` = active, `completed`, `cancelled`). Paginated. No contact details.
     *
     * @response array{data: DealResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: string|null}
     */
    public function index(ListDealsRequest $request, MarketplaceDealDirectory $directory): JsonResponse
    {
        $page = $directory->buyerDeals($request->user(), $request->validated());

        return $this->page($page, $page->getCollection()->map(fn ($d) => (new DealResource($d))->audience('buyer')->resolve($request))->all());
    }

    /**
     * Show my deal
     *
     * The frozen terms, fulfilment and delivery arrangement, the two-sided `completion` state (always `verification: self_reported`), `can` flags, the
     * `history` and the caller's own reports. `contact` is always `null` here; use the `contact` endpoint. `404` for someone else's deal.
     *
     * @response array{data: DealResource, meta: object, message: string|null}
     */
    public function show(Request $request, string $deal, MarketplaceDealDirectory $directory): JsonResponse
    {
        return ApiResponse::success((new DealResource($directory->buyerDeal($request->user(), $deal)))->audience('buyer')->detailed()->resolve($request));
    }

    /**
     * Report that the deal was carried out
     *
     * The buyer's SELF-REPORTED completion; Farmvest does not verify it. The deal becomes `completed` only when the seller has reported it too; until then it
     * stays `accepted` and `completion.state` shows `awaiting_seller`. Repeating it is a no-op (`200`). There is no automatic completion after any period.
     * Completing records nothing in inventory, sales, invoices or finance. `409 deal_not_open` (cancelled). Audited as `marketplace.deal_completion_confirmed`
     * (and `marketplace.deal_completed` when both are in).
     *
     * @response array{data: DealResource, meta: object, message: string|null}
     */
    #[Response(status: 409, description: 'Conflict (see the endpoint description for the `code`)', type: 'array{message: string, code: string, request_id: string, details?: object}')]
    public function complete(Request $request, string $deal, MarketplaceDealLifecycle $lifecycle): JsonResponse
    {
        return ApiResponse::success((new DealResource($lifecycle->complete($request->user(), 'buyer', null, $deal)))->audience('buyer')->detailed()->resolve($request), message: 'Completion recorded.');
    }

    /**
     * Cancel a deal
     *
     * Either party can cancel an ACTIVE deal. Body: `reason` (code) and an optional `note`. Cancelling ends further contact access through the API
     * (`409 deal_contact_unavailable` afterwards) but details already shared cannot be recalled. It restores no stock, refunds nothing and takes no payment
     * action: Farmvest never held either. Repeating it is a no-op (`200`). `409 deal_not_open` (already completed). Audited as `marketplace.deal_cancelled`.
     *
     * @response array{data: DealResource, meta: object, message: string|null}
     */
    #[Response(status: 409, description: 'Conflict (see the endpoint description for the `code`)', type: 'array{message: string, code: string, request_id: string, details?: object}')]
    public function cancel(CancelDealRequest $request, string $deal, MarketplaceDealLifecycle $lifecycle): JsonResponse
    {
        return ApiResponse::success((new DealResource($lifecycle->cancel($request->user(), 'buyer', null, $deal, $request->validated('reason'), $request->validated('note'))))->audience('buyer')->detailed()->resolve($request), message: 'Deal cancelled.');
    }

    /**
     * Report the deal or the seller
     *
     * Allowed in ANY deal state, including after a cancellation. Body: `target` (`deal` | `other_party`), `reason` and an optional `description`. The report
     * keeps the deal and its history as they were and is visible to platform admins; it does NOT change the deal's status, freeze completion or
     * cancellation, refund anything or settle any dispute (moderation arrives in a later phase). The seller is not shown the report. One report per target:
     * repeating it returns the existing one (`200`; the first is `201`). Audited as `marketplace.deal_reported`.
     *
     * @response array{data: DealReportResource, meta: object, message: string|null}
     */
    #[Response(status: 201, description: 'Created', type: 'array{data: \App\Http\Resources\Marketplace\DealReportResource, meta: object, message: string|null}')]
    public function report(ReportDealRequest $request, string $deal, MarketplaceDealLifecycle $lifecycle): JsonResponse
    {
        [$report, $created] = $lifecycle->report($request->user(), 'buyer', null, $deal, $request->validated('target'), $request->validated('reason'), $request->validated('description'));

        return ApiResponse::success((new DealReportResource($report))->resolve($request), message: $created ? 'Report received.' : 'You already reported this.', status: $created ? 201 : 200);
    }

    /**
     * The seller's contact for this deal
     *
     * The ONLY place the seller's private contact is returned. Available while the deal is `accepted` or `completed`; `409 deal_contact_unavailable` once
     * cancelled. Returns the shop's configured channels and preferred method; the exact `address_line` only when the deal is a PICKUP (a delivery deal gets
     * the delivery coverage instead). Every read is recorded (who, when, which fields - never values) and audited as `marketplace.deal_contact_viewed`.
     * `404` for someone else's deal.
     *
     * @response array{data: array{deal: array{id: string, reference: string, status: string, fulfilment_method: string}, party: 'seller', contact: array{name: string, preferred_contact_method: string|null, channels: array{phone?: string, whatsapp?: string, email?: string}, pickup?: array{address_line: string|null, area: string|null}, delivery?: array{coverage: string[], dispatch_estimate: string|null}}, notice: string}, meta: object, message: string|null}
     */
    #[Response(status: 409, description: 'Conflict (see the endpoint description for the `code`)', type: 'array{message: string, code: string, request_id: string, details?: object}')]
    public function contact(Request $request, string $deal, MarketplaceDealContact $contact): JsonResponse
    {
        return ApiResponse::success($contact->sellerContactFor($request->user(), $deal));
    }
}
