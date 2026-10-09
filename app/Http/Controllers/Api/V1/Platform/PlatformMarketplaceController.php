<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\ShopVerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\ListPlatformShopsRequest;
use App\Http\Requests\Marketplace\ShopReasonRequest;
use App\Http\Requests\Marketplace\ShopVerificationDecisionRequest;
use App\Http\Resources\Marketplace\PlatformShopResource;
use App\Services\Marketplace\MarketplaceModerationService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform oversight of seller shops. Reads: any platform role. Writes: role `admin`. Every write is audited (`platform.marketplace_*`).
 * No farm business data is exposed; the farm link is just an id.
 */
class PlatformMarketplaceController extends Controller
{
    use Paginates;

    /**
     * List shops
     *
     * Every shop in any state, newest first. `status=pending_review` is the review queue (oldest submission first). Filters: `q`, `status`,
     * `verification_status`, `farm_backed`. Rows carry the owner but never private contact values.
     *
     * @response array{data: PlatformShopResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: string|null}
     */
    public function index(ListPlatformShopsRequest $request, MarketplaceModerationService $moderation): JsonResponse
    {
        $page = $moderation->list($request->validated());

        return $this->page($page, PlatformShopResource::collection($page->getCollection())->resolve($request));
    }

    /**
     * Show a shop
     *
     * Includes the owner and the seller's private `contact` (so support can reach them during review).
     *
     * @response array{data: PlatformShopResource, meta: object, message: string|null}
     */
    public function show(Request $request, string $shop, MarketplaceModerationService $moderation): JsonResponse
    {
        return ApiResponse::success((new PlatformShopResource($moderation->show($shop)))->resolve($request));
    }

    /**
     * Approve a shop
     *
     * Admin write. `pending_review` -> `active`: the ONLY way a shop becomes public. `409 invalid_shop_state`. Audited as `platform.marketplace_shop_approved`.
     *
     * @response array{data: PlatformShopResource, meta: object, message: string|null}
     */
    public function approve(Request $request, string $shop, MarketplaceModerationService $moderation): JsonResponse
    {
        return ApiResponse::success((new PlatformShopResource($moderation->approve($request->user(), $shop)))->resolve($request));
    }

    /**
     * Reject a shop
     *
     * Admin write. `pending_review` -> `rejected`; the required `reason` is shown to the seller, who can edit and submit again.
     * `409 invalid_shop_state`. Audited as `platform.marketplace_shop_rejected`.
     *
     * @response array{data: PlatformShopResource, meta: object, message: string|null}
     */
    public function reject(ShopReasonRequest $request, string $shop, MarketplaceModerationService $moderation): JsonResponse
    {
        return ApiResponse::success((new PlatformShopResource($moderation->reject($request->user(), $shop, $request->validated('reason'))))->resolve($request));
    }

    /**
     * Suspend a shop
     *
     * Admin write. Any submitted, non-suspended shop -> `suspended`: removed from public discovery immediately and frozen against seller edits
     * (the seller cannot reopen it). Required `reason` is shown to the seller. `409 invalid_shop_state` (draft or already suspended).
     * Audited as `platform.marketplace_shop_suspended`.
     *
     * @response array{data: PlatformShopResource, meta: object, message: string|null}
     */
    public function suspend(ShopReasonRequest $request, string $shop, MarketplaceModerationService $moderation): JsonResponse
    {
        return ApiResponse::success((new PlatformShopResource($moderation->suspend($request->user(), $shop, $request->validated('reason'))))->resolve($request));
    }

    /**
     * Reinstate a shop
     *
     * Admin write. `suspended` -> `active` if the shop had been approved before, otherwise back to `pending_review`. `409 invalid_shop_state`.
     * Audited as `platform.marketplace_shop_reinstated`.
     *
     * @response array{data: PlatformShopResource, meta: object, message: string|null}
     */
    public function reinstate(ShopReasonRequest $request, string $shop, MarketplaceModerationService $moderation): JsonResponse
    {
        return ApiResponse::success((new PlatformShopResource($moderation->reinstate($request->user(), $shop, $request->validated('reason'))))->resolve($request));
    }

    /**
     * Decide verification
     *
     * Admin write. `decision`: `verified` (from pending/unverified/rejected; the shop must have been approved), `rejected` (from pending, reason
     * required) or `unverified` (revokes the badge, reason required). `409 invalid_verification_state`, `409 shop_not_approved`.
     * Audited as `platform.marketplace_verification_{decision}`. Never changes the publishing status.
     *
     * @response array{data: PlatformShopResource, meta: object, message: string|null}
     */
    public function verification(ShopVerificationDecisionRequest $request, string $shop, MarketplaceModerationService $moderation): JsonResponse
    {
        $shopModel = $moderation->verification($request->user(), $shop, ShopVerificationStatus::from($request->validated('decision')), $request->validated('reason'));

        return ApiResponse::success((new PlatformShopResource($shopModel))->resolve($request));
    }
}
