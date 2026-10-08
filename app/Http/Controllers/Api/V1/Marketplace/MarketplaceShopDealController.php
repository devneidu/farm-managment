<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Api\V1\Platform\Paginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\CancelDealRequest;
use App\Http\Requests\Marketplace\ConfirmIntentRequest;
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
 * The seller's side of deals, always inside a shop the caller belongs to (`404` for another shop's deal). Shop role permissions: `deal.view` (owner,
 * manager, staff) reads deals; `deal.respond` (owner, manager) confirms purchase requests, completes, cancels, reports and reads the buyer's contact. The
 * buyer appears by display name only everywhere except the audited `contact` endpoint. Farmvest does not take payment, hold funds, reserve stock or deliver.
 */
class MarketplaceShopDealController extends Controller
{
    use Paginates;

    /**
     * Confirm a purchase request
     *
     * Needs `deal.respond` (owner, manager). The seller's FIRST step for a fixed-price "Proceed at listed price" request: it offers to proceed on exactly
     * these terms - the intent's quantity at the listing's CURRENT unit price. It is NOT an agreement: no deal exists, no contact is exposed and nothing is
     * reserved until the BUYER confirms (`marketplace/my/deal-confirmations/{id}/confirm`) before `expires_at` (`marketplace_deal_confirmation_hours`,
     * default 72). Body: `fulfilment_method` (required when the listing offers both) and an optional `delivery_charge` (only for seller delivery on a
     * listing whose charge is "agreed separately"; omit when unknown - it then reads "To be agreed directly" and is never added to the product total).
     *
     * Checked under lock: the shop is active, the listing is live, the intent is still current (price, unit and product unchanged), the quantity fits the
     * seller-declared availability. Repeating the same confirmation returns the open one (`200`; first is `201`). Errors: `409 intent_stale`,
     * `409 intent_already_converted`, `409 confirmation_pending` (different terms; withdraw first), `409 shop_not_active`, `409 listing_unavailable`,
     * `409 quantity_unavailable`, `422 fulfilment_method` / `delivery_charge`, `403` (staff). Audited as `marketplace.deal_confirmation_created`.
     */
    #[Response(status: 201, description: 'Created', type: 'array{data: \App\Http\Resources\Marketplace\DealConfirmationResource, meta: object, message: string|null}')]
    #[Response(status: 409, description: 'Conflict (see the endpoint description for the `code`)', type: 'array{message: string, code: string, request_id: string, details?: object}')]
    public function confirmIntent(ConfirmIntentRequest $request, string $shop, string $intent, MarketplaceDealService $deals): JsonResponse
    {
        [$confirmation, $created] = $deals->confirmIntent($request->user(), $shop, $intent, $request->validated());

        return ApiResponse::success((new DealConfirmationResource($confirmation))->audience('seller')->resolve($request), message: $created ? 'Waiting for the buyer to confirm.' : 'Already confirmed; waiting for the buyer.', status: $created ? 201 : 200);
    }

    /**
     * Withdraw a confirmation
     *
     * Needs `deal.respond`. Takes back a confirmation the buyer has not answered, so the buyer can no longer turn it into a deal. Repeating it is a no-op
     * (`200`). `409 confirmation_not_open` once the buyer confirmed it or it lapsed. Audited as `marketplace.deal_confirmation_withdrawn`.
     *
     * @response array{data: DealConfirmationResource, meta: object, message: string|null}
     */
    #[Response(status: 409, description: 'Conflict (see the endpoint description for the `code`)', type: 'array{message: string, code: string, request_id: string, details?: object}')]
    public function withdraw(Request $request, string $shop, string $confirmation, MarketplaceDealService $deals): JsonResponse
    {
        return ApiResponse::success((new DealConfirmationResource($deals->withdraw($request->user(), $shop, $confirmation)))->audience('seller')->resolve($request), message: 'Confirmation withdrawn.');
    }

    /**
     * List my shop's deals
     *
     * Needs `deal.view` (all roles). Newest first. Filters: `status`, `listing` (id). Paginated. Buyers appear by display name only; no contact.
     *
     * @response array{data: DealResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: string|null}
     */
    public function index(ListDealsRequest $request, string $shop, MarketplaceDealDirectory $directory): JsonResponse
    {
        $page = $directory->shopDeals($request->user(), $shop, $request->validated());
        $may = $directory->mayRespond($request->user(), $shop);

        return $this->page($page, $page->getCollection()->map(fn ($d) => (new DealResource($d))->audience('seller')->mayAct($may)->resolve($request))->all());
    }

    /**
     * Show a deal
     *
     * Needs `deal.view`. The frozen terms and fulfilment, the two-sided `completion` state (always `verification: self_reported`), `can` flags for the
     * caller's role, the `history` and the shop's own reports. `contact` is `null`; use the `contact` endpoint.
     *
     * @response array{data: DealResource, meta: object, message: string|null}
     */
    public function show(Request $request, string $shop, string $deal, MarketplaceDealDirectory $directory): JsonResponse
    {
        return ApiResponse::success((new DealResource($directory->shopDeal($request->user(), $shop, $deal)))->audience('seller')->mayAct($directory->mayRespond($request->user(), $shop))->detailed()->resolve($request));
    }

    /**
     * Report that the deal was carried out
     *
     * Needs `deal.respond`. The shop's SELF-REPORTED completion; Farmvest does not verify it. The deal becomes `completed` only when the buyer has reported
     * it too; until then `completion.state` shows `awaiting_buyer`. Repeating it is a no-op. Completing creates no sale, invoice, stock movement or finance
     * record - recording the sale remains a separate, explicit action. `409 deal_not_open` (cancelled). Audited as `marketplace.deal_completion_confirmed`.
     *
     * @response array{data: DealResource, meta: object, message: string|null}
     */
    #[Response(status: 409, description: 'Conflict (see the endpoint description for the `code`)', type: 'array{message: string, code: string, request_id: string, details?: object}')]
    public function complete(Request $request, string $shop, string $deal, MarketplaceDealLifecycle $lifecycle): JsonResponse
    {
        return ApiResponse::success((new DealResource($lifecycle->complete($request->user(), 'seller', $shop, $deal)))->audience('seller')->mayAct(true)->detailed()->resolve($request), message: 'Completion recorded.');
    }

    /**
     * Cancel a deal
     *
     * Needs `deal.respond`. Same rules as the buyer's cancel: an ACTIVE deal, a `reason` code and optional `note`; ends further contact access; restores no
     * stock and refunds nothing. Repeating it is a no-op; `409 deal_not_open` once completed. Audited as `marketplace.deal_cancelled`.
     *
     * @response array{data: DealResource, meta: object, message: string|null}
     */
    #[Response(status: 409, description: 'Conflict (see the endpoint description for the `code`)', type: 'array{message: string, code: string, request_id: string, details?: object}')]
    public function cancel(CancelDealRequest $request, string $shop, string $deal, MarketplaceDealLifecycle $lifecycle): JsonResponse
    {
        return ApiResponse::success((new DealResource($lifecycle->cancel($request->user(), 'seller', $shop, $deal, $request->validated('reason'), $request->validated('note'))))->audience('seller')->mayAct(true)->detailed()->resolve($request), message: 'Deal cancelled.');
    }

    /**
     * Report the deal or the buyer
     *
     * Needs `deal.respond`. Same rules as the buyer's report: any deal state, `target` (`deal` | `other_party`), `reason`, optional `description`; the deal's
     * status is unchanged and the buyer is not shown the report. Audited as `marketplace.deal_reported`.
     *
     * @response array{data: DealReportResource, meta: object, message: string|null}
     */
    #[Response(status: 201, description: 'Created', type: 'array{data: \App\Http\Resources\Marketplace\DealReportResource, meta: object, message: string|null}')]
    public function report(ReportDealRequest $request, string $shop, string $deal, MarketplaceDealLifecycle $lifecycle): JsonResponse
    {
        [$report, $created] = $lifecycle->report($request->user(), 'seller', $shop, $deal, $request->validated('target'), $request->validated('reason'), $request->validated('description'));

        return ApiResponse::success((new DealReportResource($report))->resolve($request), message: $created ? 'Report received.' : 'You already reported this.', status: $created ? 201 : 200);
    }

    /**
     * The buyer's contact for this deal
     *
     * Needs `deal.respond` (owner, manager; staff get `403`). The ONLY place the buyer's contact is returned: the display name, the account email and the
     * optional phone the buyer gave for this deal. Available while the deal is `accepted` or `completed`; `409 deal_contact_unavailable` once cancelled. Every
     * read is recorded and audited as `marketplace.deal_contact_viewed`.
     *
     * @response array{data: array{deal: array{id: string, reference: string, status: string, fulfilment_method: string}, party: 'buyer', contact: array{name: string, preferred_contact_method: string, channels: array{email: string, phone?: string}}, notice: string}, meta: object, message: string|null}
     */
    #[Response(status: 409, description: 'Conflict (see the endpoint description for the `code`)', type: 'array{message: string, code: string, request_id: string, details?: object}')]
    public function contact(Request $request, string $shop, string $deal, MarketplaceDealContact $contact): JsonResponse
    {
        return ApiResponse::success($contact->buyerContactFor($request->user(), $shop, $deal));
    }
}
