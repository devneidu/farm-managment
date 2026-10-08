<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\CancelPromotionRequest;
use App\Http\Requests\Marketplace\ListPlatformPromotionsRequest;
use App\Http\Requests\Marketplace\ListPlatformServicePaymentsRequest;
use App\Http\Requests\Marketplace\SetSellerPlanPricesRequest;
use App\Http\Requests\Marketplace\StorePromotionPackageRequest;
use App\Http\Requests\Marketplace\StoreSellerPlanRequest;
use App\Http\Requests\Marketplace\UpdatePromotionPackageRequest;
use App\Http\Requests\Marketplace\UpdateSellerPlanRequest;
use App\Http\Resources\Marketplace\PromotionPackageResource;
use App\Http\Resources\Marketplace\SellerPlanResource;
use App\Http\Resources\Marketplace\ServicePaymentResource;
use App\Http\Resources\Marketplace\ShopPromotionResource;
use App\Services\Marketplace\MarketplaceMonetisationAdmin;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform configuration and oversight of marketplace monetisation. Reads: any platform role. Writes: role `admin`, throttled, audited as
 * `platform.marketplace_*`. Switch the features with the feature flags `marketplace_seller_plans` and `marketplace_promotions` (both off by default) and tune
 * priority placement with the setting `marketplace_max_promoted_per_page`. Prices and limits are configuration: nothing is hard-coded and no paid price ships.
 */
class PlatformMarketplaceMonetisationController extends Controller
{
    use Paginates;

    public function __construct(private MarketplaceMonetisationAdmin $admin) {}

    /**
     * List seller plans
     *
     * Every plan including inactive ones (`free`, `seller_plus`, `seller_pro` are seeded; the paid two start inactive with no price and no limit).
     *
     * @response array{data: SellerPlanResource[], meta: object, message: string|null}
     */
    public function plans(Request $request): JsonResponse
    {
        return ApiResponse::success(SellerPlanResource::collection($this->admin->plans())->resolve($request));
    }

    /**
     * Create a seller plan
     *
     * Role `admin`. Creates an INACTIVE paid plan; set its prices (`PUT .../prices`) and then switch it on with `PATCH`. `listing_limit` = published listings
     * allowed at once. Audited as `platform.marketplace_seller_plan_created`.
     */
    #[Response(status: 201, description: 'Created', type: 'array{data: \App\Http\Resources\Marketplace\SellerPlanResource, meta: object, message: string|null}')]
    public function storePlan(StoreSellerPlanRequest $request): JsonResponse
    {
        return ApiResponse::success((new SellerPlanResource($this->admin->createPlan($request->user(), $request->validated())))->resolve($request), status: 201);
    }

    /**
     * Update a seller plan
     *
     * Role `admin`. Name, description, `listing_limit`, `is_active`, `sort_order`. The free plan's `listing_limit` is THE free allowance (default 10) and applies
     * at once, even while paid plans are switched off; the free plan cannot be switched off (`409 free_plan_required`). A paid plan changes affect future purchases only
     * (a paid period keeps the limit it was bought with). Switching a paid plan on needs a limit and an active price (`422`). Audited.
     *
     * @response array{data: SellerPlanResource, meta: object, message: string|null}
     */
    public function updatePlan(UpdateSellerPlanRequest $request, string $plan): JsonResponse
    {
        return ApiResponse::success((new SellerPlanResource($this->admin->updatePlan($request->user(), $plan, $request->validated())))->resolve($request));
    }

    /**
     * Set plan prices
     *
     * Role `admin`. Body `prices`: `[{interval_days: 30|365, amount: "5000.00"|null, is_active?}]`. `amount: null` removes that period's price. Future checkouts
     * only: a pending or paid payment keeps the amount it was started with. `409 free_plan_has_no_price` for the free plan. Audited.
     *
     * @response array{data: SellerPlanResource, meta: object, message: string|null}
     */
    public function setPrices(SetSellerPlanPricesRequest $request, string $plan): JsonResponse
    {
        return ApiResponse::success((new SellerPlanResource($this->admin->setPrices($request->user(), $plan, $request->validated('prices'))))->resolve($request));
    }

    /**
     * List promotion packages
     *
     * Any platform role. Every package including inactive ones, in display order. Prices are naira decimal strings.
     *
     * @response array{data: PromotionPackageResource[], meta: object, message: string|null}
     */
    public function packages(Request $request): JsonResponse
    {
        return ApiResponse::success(PromotionPackageResource::collection($this->admin->packages())->resolve($request));
    }

    /**
     * Create a promotion package
     *
     * Role `admin`. A fixed price (naira) for a fixed number of days. `is_active` defaults to true. Audited as `platform.marketplace_promotion_package_created`.
     */
    #[Response(status: 201, description: 'Created', type: 'array{data: \App\Http\Resources\Marketplace\PromotionPackageResource, meta: object, message: string|null}')]
    public function storePackage(StorePromotionPackageRequest $request): JsonResponse
    {
        return ApiResponse::success((new PromotionPackageResource($this->admin->createPackage($request->user(), $request->validated())))->resolve($request), status: 201);
    }

    /**
     * Update a promotion package
     *
     * Role `admin`. Changes affect FUTURE purchases only. Switch a package off with `is_active: false`; packages are never deleted. Audited.
     *
     * @response array{data: PromotionPackageResource, meta: object, message: string|null}
     */
    public function updatePackage(UpdatePromotionPackageRequest $request, string $package): JsonResponse
    {
        return ApiResponse::success((new PromotionPackageResource($this->admin->updatePackage($request->user(), $package, $request->validated())))->resolve($request));
    }

    /**
     * List service payments
     *
     * Any platform role. Every Farmvest service payment, newest first. Filters: `status`, `purpose`, `shop_id`, `needs_attention` (paid but no benefit granted),
     * `q` (part of the reference). Includes the provider status and any failure or settlement reason.
     *
     * @response array{data: ServicePaymentResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: string|null}
     */
    public function payments(ListPlatformServicePaymentsRequest $request): JsonResponse
    {
        $page = $this->admin->payments($request->validated());

        return $this->page($page, $page->getCollection()->map(fn ($p) => (new ServicePaymentResource($p))->audience('admin')->resolve($request))->all());
    }

    /**
     * List promotions
     *
     * Any platform role. All promotions, newest first. Filters: `state` (running | ended), `shop_id`.
     *
     * @response array{data: ShopPromotionResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: string|null}
     */
    public function promotions(ListPlatformPromotionsRequest $request): JsonResponse
    {
        $page = $this->admin->promotions($request->validated());

        return $this->page($page, ShopPromotionResource::collection($page->getCollection())->resolve($request));
    }

    /**
     * Cancel a promotion
     *
     * Role `admin`. Body `reason`. Stops the promotion now and frees the listing; refunds are handled outside this API. Repeating it is a no-op. Audited as
     * `platform.marketplace_promotion_cancelled`.
     *
     * @response array{data: ShopPromotionResource, meta: object, message: string|null}
     */
    public function cancelPromotion(CancelPromotionRequest $request, string $promotion): JsonResponse
    {
        return ApiResponse::success((new ShopPromotionResource($this->admin->cancelPromotion($request->user(), $promotion, $request->validated('reason'))))->resolve($request));
    }
}
