<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\ListDealReportsRequest;
use App\Http\Requests\Marketplace\ListPlatformDealsRequest;
use App\Http\Resources\Marketplace\DealReportResource;
use App\Http\Resources\Marketplace\DealResource;
use App\Services\Marketplace\MarketplaceDealContact;
use App\Services\Marketplace\MarketplaceDealDirectory;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform oversight of marketplace deals: READ ONLY, any platform role. Admins can see every deal and every report, and read the parties' contact through
 * a separate audited call, but cannot change a deal here: reports are preserved as filed and moderation arrives in a later phase.
 */
class PlatformMarketplaceDealController extends Controller
{
    use Paginates;

    /**
     * List deals
     *
     * Every deal, newest first. Filters: `status`, `shop_id`, `reported` (true = has reports), `q` (part of the reference). Each row has `report_count`. No
     * contact details.
     *
     * @response array{data: DealResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: string|null}
     */
    public function index(ListPlatformDealsRequest $request, MarketplaceDealDirectory $directory): JsonResponse
    {
        $page = $directory->adminDeals($request->validated());

        return $this->page($page, $page->getCollection()->map(fn ($d) => (new DealResource($d))->audience('admin')->resolve($request))->all());
    }

    /**
     * Show a deal
     *
     * The frozen terms, fulfilment, completion state, the full `history` (including `reported` events) and every report with its reporter. No contact.
     *
     * @response array{data: DealResource, meta: object, message: string|null}
     */
    public function show(Request $request, string $deal, MarketplaceDealDirectory $directory): JsonResponse
    {
        return ApiResponse::success((new DealResource($directory->adminDeal($deal)))->audience('admin')->detailed()->resolve($request));
    }

    /**
     * Both parties' contact for a deal
     *
     * A separate, audited read of the seller's and the buyer's contact, for investigating a complaint. Works in any deal state. Every call is recorded in the
     * contact-access log and audited as `platform.marketplace_deal_contact_viewed` (field names only, never values).
     *
     * @response array{data: array{deal: array{id: string, reference: string, status: string}, seller: array{name: string, preferred_contact_method: string|null, channels: array{phone?: string, whatsapp?: string, email?: string}, pickup?: array{address_line: string|null, area: string|null}, delivery?: array{coverage: string[], dispatch_estimate: string|null}}, buyer: array{name: string, preferred_contact_method: string, channels: array{email: string, phone?: string}}, notice: string}, meta: object, message: string|null}
     */
    public function contact(Request $request, string $deal, MarketplaceDealContact $contact): JsonResponse
    {
        return ApiResponse::success($contact->forAdmin($request->user(), $deal));
    }

    /**
     * List deal reports
     *
     * Every report filed by either party, newest first. Filters: `status` (`open`), `reason`, `deal_id`. Reports are preserved as filed; they trigger no
     * refund, penalty or settlement.
     *
     * @response array{data: DealReportResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: string|null}
     */
    #[Response(status: 200, description: 'OK', type: 'array{data: \App\Http\Resources\Marketplace\DealReportResource[], meta: object, message: string|null}')]
    public function reports(ListDealReportsRequest $request, MarketplaceDealDirectory $directory): JsonResponse
    {
        $page = $directory->adminReports($request->validated());

        return $this->page($page, $page->getCollection()->map(fn ($r) => (new DealReportResource($r))->audience('admin')->resolve($request))->all());
    }
}
