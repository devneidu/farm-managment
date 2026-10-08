<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Api\V1\Platform\Paginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\CheckoutPromotionRequest;
use App\Http\Requests\Marketplace\CheckoutSubscriptionRequest;
use App\Http\Requests\Marketplace\ListServicePaymentsRequest;
use App\Http\Requests\Marketplace\ListShopPromotionsRequest;
use App\Http\Resources\Marketplace\PromotionPackageResource;
use App\Http\Resources\Marketplace\SellerPlanResource;
use App\Http\Resources\Marketplace\ServicePaymentResource;
use App\Http\Resources\Marketplace\ShopPromotionResource;
use App\Services\Marketplace\MarketplaceSellerBilling;
use App\Services\Marketplace\MarketplaceServiceCheckout;
use App\Services\Marketplace\PaymentGatewayException;
use App\Support\Api\ApiHttpException;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A shop's Farmvest services: seller plan, listing allowance, promotions and the payments for them. Always inside a shop the caller belongs to (`404`
 * otherwise). `listing.view` (every role) reads plan, allowance and the catalogue; `billing.view` (owner, manager) reads payments and promotions;
 * `billing.manage` (owner, manager) starts checkouts and re-checks payments. These are payments to FARMVEST only: buyer-seller payments stay outside the platform.
 */
class MarketplaceShopBillingController extends Controller
{
    use Paginates;

    /**
     * Current plan
     *
     * Needs `listing.view`. The plan the shop is on RIGHT NOW (`free`, or a paid plan whose prepaid period is running), its `listing_limit`, `published_count`,
     * `remaining` and `over_limit_by`, the running period dates, any `upcoming_periods` and the `features` flags (`seller_plans`, `promotions`: whether buying
     * is switched on). A lapsed subscription reads as free automatically - no job is needed - and existing listings are never deleted or paused by it.
     *
     * @response array{data: array{plan: array{code: string, name: string, source: string}, listing_limit: int|null, unlimited: bool, published_count: int, remaining: int|null, over_limit_by: int, can_publish: bool, period: array{starts_at: string, ends_at: string}|null, upcoming_periods: array<int, object>, features: array{seller_plans: bool, promotions: bool}}, meta: object, message: string|null}
     */
    public function plan(Request $request, string $shop, MarketplaceSellerBilling $billing): JsonResponse
    {
        return ApiResponse::success($billing->plan($request->user(), $shop));
    }

    /**
     * Listing allowance
     *
     * Needs `listing.view`. Just the usage numbers behind the publish limit. Publishing a listing when `can_publish` is false answers `409 listing_limit_reached`.
     * Only PUBLISHED listings count; drafts, paused, archived and restricted listings do not.
     *
     * @response array{data: array{plan: array{code: string, name: string, source: string}, listing_limit: int|null, unlimited: bool, published_count: int, remaining: int|null, over_limit_by: int, can_publish: bool, period: array{starts_at: string, ends_at: string}|null}, meta: object, message: string|null}
     */
    public function allowance(Request $request, string $shop, MarketplaceSellerBilling $billing): JsonResponse
    {
        return ApiResponse::success($billing->allowance($request->user(), $shop));
    }

    /**
     * Available plans
     *
     * Needs `listing.view`. Active seller plans with their prices (naira, decimal strings) for 30 and 365 days. A paid plan appears only once an administrator
     * has set a limit and a price. `features.seller_plans` says whether buying is switched on.
     *
     * @response array{data: SellerPlanResource[], meta: array{features: array{seller_plans: bool, promotions: bool}}, message: string|null}
     */
    public function plans(Request $request, string $shop, MarketplaceSellerBilling $billing): JsonResponse
    {
        $c = $billing->catalogue($request->user(), $shop);

        return ApiResponse::success(SellerPlanResource::collection($c['plans'])->resolve($request), ['features' => $c['features']]);
    }

    /**
     * Available promotion packages
     *
     * Needs `listing.view`. Active fixed-price packages (`duration_days`, `amount`). `features.promotions` says whether buying is switched on.
     *
     * @response array{data: PromotionPackageResource[], meta: array{features: array{seller_plans: bool, promotions: bool}}, message: string|null}
     */
    public function packages(Request $request, string $shop, MarketplaceSellerBilling $billing): JsonResponse
    {
        $c = $billing->catalogue($request->user(), $shop);

        return ApiResponse::success(PromotionPackageResource::collection($c['packages'])->resolve($request), ['features' => $c['features']]);
    }

    /**
     * Buy a seller plan
     *
     * Needs `billing.manage` (owner, manager). Body: `plan_id`, `interval_days` (30 or 365). Creates a PENDING payment for the CURRENT configured price (frozen)
     * and returns it with `authorization_url`: send the buyer there (Paystack's hosted checkout). NOTHING is activated by this call, by the redirect back, or by
     * the client: the plan starts only after the server confirms the payment with Paystack (webhook, or `POST payments/{reference}/verify`). Prepaid, no
     * auto-renewal. Renewing the SAME plan extends the current period (the new one starts when it ends); a different paid plan can be bought once the current
     * one has ended. Repeating the call within 30 minutes returns the same pending payment (`200`; first is `201`).
     *
     * Errors: `409 monetisation_disabled` (seller plans switched off), `409 plan_not_purchasable`, `409 subscription_active` (a different paid plan is running),
     * `409 shop_not_active`, `403` (staff), `503 payments_unavailable`, `502 gateway_unavailable` (nothing charged; retry), `422` validation.
     */
    #[Response(status: 201, description: 'Created', type: 'array{data: \App\Http\Resources\Marketplace\ServicePaymentResource, meta: object, message: string|null}')]
    #[Response(status: 409, description: 'Conflict (see the endpoint description for the `code`)', type: 'array{message: string, code: string, request_id: string, details?: object}')]
    public function checkoutSubscription(CheckoutSubscriptionRequest $request, string $shop, MarketplaceServiceCheckout $checkout): JsonResponse
    {
        $v = $request->validated();
        [$payment, $created] = $checkout->subscription($request->user(), $shop, $v['plan_id'], (int) $v['interval_days']);

        return ApiResponse::success((new ServicePaymentResource($payment))->resolve($request), message: $created ? 'Continue to payment.' : 'You already have this checkout open.', status: $created ? 201 : 200);
    }

    /**
     * Promote a listing
     *
     * Needs `billing.manage`. Body: `package_id`. The listing must be PUBLISHED in this shop (`404` otherwise) and must not already be promoted. Same payment
     * flow as the plan checkout: a pending payment and an `authorization_url`; the promotion starts only after server-side confirmation and runs for the
     * package's `duration_days` from that moment. A promotion gives priority placement and a "Sponsored" label only; organic listings stay visible.
     *
     * Errors: `409 monetisation_disabled` (promotions switched off), `409 package_not_available`, `409 listing_not_promotable`, `409 promotion_active`,
     * `409 shop_not_active`, `403`, `503 payments_unavailable`, `502 gateway_unavailable`, `422`.
     */
    #[Response(status: 201, description: 'Created', type: 'array{data: \App\Http\Resources\Marketplace\ServicePaymentResource, meta: object, message: string|null}')]
    #[Response(status: 409, description: 'Conflict (see the endpoint description for the `code`)', type: 'array{message: string, code: string, request_id: string, details?: object}')]
    public function checkoutPromotion(CheckoutPromotionRequest $request, string $shop, string $listing, MarketplaceServiceCheckout $checkout): JsonResponse
    {
        [$payment, $created] = $checkout->promotion($request->user(), $shop, $listing, $request->validated('package_id'));

        return ApiResponse::success((new ServicePaymentResource($payment))->resolve($request), message: $created ? 'Continue to payment.' : 'You already have this checkout open.', status: $created ? 201 : 200);
    }

    /**
     * Payment history
     *
     * Needs `billing.view` (owner, manager). Newest first. Filters: `status` (pending | paid | failed | abandoned), `purpose` (subscription | promotion).
     * `benefit_granted` is true only after server-side confirmation and activation; `needs_attention` marks a payment that was confirmed but could not be applied
     * (for example the shop was suspended meanwhile) and is being followed up by Farmvest.
     *
     * @response array{data: ServicePaymentResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: string|null}
     */
    public function payments(ListServicePaymentsRequest $request, string $shop, MarketplaceSellerBilling $billing): JsonResponse
    {
        $page = $billing->payments($request->user(), $shop, $request->validated());

        return $this->page($page, ServicePaymentResource::collection($page->getCollection())->resolve($request));
    }

    /**
     * Check a payment
     *
     * Needs `billing.manage`. Call this when the buyer returns from Paystack (the `reference` is in the return URL). It asks Paystack - it never trusts the
     * redirect - and activates the benefit if and only if Paystack confirms a successful payment of exactly the frozen amount in NGN. Safe to call repeatedly:
     * a payment activates once. Returns the payment (`status`, `benefit_granted`). `404` for another shop's payment; `502 gateway_unavailable` when Paystack
     * cannot be reached (nothing is lost; the payment is retried automatically).
     */
    #[Response(status: 502, description: 'The payment provider is unreachable', type: 'array{message: string, code: string, request_id: string}')]
    public function verify(Request $request, string $shop, string $reference, MarketplaceSellerBilling $billing): JsonResponse
    {
        try {
            $payment = $billing->verify($request->user(), $shop, $reference);
        } catch (PaymentGatewayException) {
            throw new ApiHttpException(502, 'gateway_unavailable', 'We could not reach the payment provider. Nothing is lost; check again in a moment.');
        }

        return ApiResponse::success((new ServicePaymentResource($payment))->resolve($request));
    }

    /**
     * Promotion history
     *
     * Needs `billing.view`. The shop's promotions, newest first. Filter `state` = `running` | `ended`. Each has `state` (running | scheduled | expired | cancelled)
     * and `benefit_active` (false while the shop is suspended or the listing is not published/restricted, even if the paid window is still open).
     *
     * @response array{data: ShopPromotionResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: string|null}
     */
    public function promotions(ListShopPromotionsRequest $request, string $shop, MarketplaceSellerBilling $billing): JsonResponse
    {
        $page = $billing->promotions($request->user(), $shop, $request->validated());

        return $this->page($page, ShopPromotionResource::collection($page->getCollection())->resolve($request));
    }
}
