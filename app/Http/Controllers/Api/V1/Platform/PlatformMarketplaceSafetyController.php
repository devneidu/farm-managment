<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\MarketplaceReportRequest;
use App\Http\Requests\Marketplace\TransitionReportRequest;
use App\Http\Resources\Marketplace\MarketplaceReportResource;
use App\Http\Resources\Marketplace\OfferResource;
use App\Models\MarketplaceOffer;
use App\Services\Marketplace\MarketplaceOfferService;
use App\Services\Marketplace\MarketplaceReportService;
use App\Services\Marketplace\MarketplaceSafetyDirectory;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformMarketplaceSafetyController extends Controller
{
    use Paginates;

    /** Marketplace status totals, including every report workflow state (content and deal, plus combined). */
    #[Response(status: 200, description: 'Status totals', type: 'array{data: array{shops: array<string, int>, sellers: int, listings: array<string, int>, offers: array<string, int>, deals: array<string, int>, reports: array{content: array{open: int, in_review: int, resolved: int, dismissed: int}, deal: array{open: int, in_review: int, resolved: int, dismissed: int}, total: array{open: int, in_review: int, resolved: int, dismissed: int}}}, meta: object, message: string|null}')]
    public function summary(MarketplaceSafetyDirectory $directory, MarketplaceReportService $reports): JsonResponse
    {
        return ApiResponse::success($directory->summary($reports));
    }

    /** Admin report queue; `type=content|deal`, status, reason, target and reference search. */
    #[Response(status: 200, description: 'OK', type: 'array{data: \App\Http\Resources\Marketplace\MarketplaceReportResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: string|null}')]
    public function reports(MarketplaceReportRequest $request, MarketplaceReportService $reports, MarketplaceSafetyDirectory $directory): JsonResponse
    {
        $type = $request->validated('type', 'content');
        $page = $directory->reports($request->validated(), $reports);

        return $this->page($page, $page->getCollection()->map(fn ($r) => (new MarketplaceReportResource($r))->admin()->resolve($request))->all());
    }

    /** Read a complaint with its confidential administrative history. */
    #[Response(status: 200, description: 'OK', type: 'array{data: \App\Http\Resources\Marketplace\MarketplaceReportResource, meta: object, message: string|null}')]
    public function report(Request $request, string $type, string $report, MarketplaceReportService $reports): JsonResponse
    {
        return ApiResponse::success((new MarketplaceReportResource($reports->show($type, $report)))->admin()->resolve($request));
    }

    /** Admin only: open -> in_review -> dismissed/resolved, required reason; enforcement is optional and explicit. */
    #[Response(status: 409, description: 'Invalid report or moderation state', type: 'array{message: string, code: string, request_id: string, details?: object}')]
    #[Response(status: 200, description: 'OK', type: 'array{data: \App\Http\Resources\Marketplace\MarketplaceReportResource, meta: object, message: string|null}')]
    public function transition(TransitionReportRequest $request, string $type, string $report, MarketplaceReportService $reports): JsonResponse
    {
        return ApiResponse::success((new MarketplaceReportResource($reports->transition($request->user(), $type, $report, $request->validated())))->admin()->resolve($request));
    }

    /** Read-only offer directory. Filters: offer_status, shop_id, listing_id, q, page, per_page. Expired includes elapsed pending offers. */
    #[Response(status: 200, description: 'Offers', type: 'array{data: \App\Http\Resources\Marketplace\OfferResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: string|null}')]
    public function offers(MarketplaceReportRequest $request, MarketplaceOfferService $offers, MarketplaceSafetyDirectory $directory): JsonResponse
    {
        $page = $directory->offers($request->validated(), $offers);

        return $this->page($page, $page->getCollection()->map(fn ($o) => (new OfferResource($o))->audience('admin')->resolve($request))->all());
    }

    /** Read an offer's frozen terms and append-only lifecycle history; no mutation or contact. */
    #[Response(status: 200, description: 'Offer', type: 'array{data: \App\Http\Resources\Marketplace\OfferResource, meta: object, message: string|null}')]
    public function offer(Request $request, string $offer, MarketplaceOfferService $offers): JsonResponse
    {
        return ApiResponse::success((new OfferResource(MarketplaceOffer::with($offers->relations())->findOrFail($offer)))->audience('admin')->detailed()->resolve($request));
    }
}
