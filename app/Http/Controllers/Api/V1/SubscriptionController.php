<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Feature;
use App\Enums\Limit;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Services\Subscription\EntitlementService;
use App\Services\Subscription\SubscriptionService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Subscription endpoints act on the ACTIVE farm (no farm id in the URL). RBAC permissions control who may
 * see/manage billing; the plan's entitlements (below) describe what the farm may do. They are separate systems.
 */
class SubscriptionController extends Controller
{
    /**
     * Current subscription
     *
     * The farm's subscription and plan. Every farm always has one (new farms start on the default plan).
     * `effective_plan` is the plan whose entitlements apply now; when `subscription_inactive` is true the
     * subscription has lapsed and the default plan applies. Requires `subscription.view`.
     *
     * @response array{data: SubscriptionResource, meta: object, message: string|null}
     */
    public function show(Request $request, FarmContext $ctx, SubscriptionService $subscriptions): JsonResponse
    {
        $subscription = $ctx->farm->subscription ?? $subscriptions->startDefault($ctx->farm);

        return ApiResponse::success((new SubscriptionResource($subscription))->resolve($request));
    }

    /**
     * Current entitlements
     *
     * What the farm's plan allows right now: boolean `features` and numeric `limits` (`unlimited: true` with
     * `limit: null` means no cap). Use this to enable/disable UI; the API enforces the same rules and answers
     * denied actions with `feature_not_available`, `subscription_inactive` or `plan_limit_reached`.
     * Any farm member may read this (`farm.view`); it does not grant any RBAC permission.
     *
     * @response array{data: array{plan: array{id: string, slug: string, name: string}|null, subscription_inactive: bool, features: array<string, bool>, limits: array<string, array{limit: int|null, unlimited: bool}>}, meta: object, message: string|null}
     */
    public function entitlements(FarmContext $ctx, EntitlementService $entitlements): JsonResponse
    {
        $set = $entitlements->for($ctx->farm);

        $features = [];
        foreach (Feature::cases() as $feature) {
            $features[$feature->value] = $set->allows($feature);
        }

        $limits = [];
        foreach (Limit::cases() as $limit) {
            $value = $set->limit($limit);
            $limits[$limit->value] = ['limit' => $value->value, 'unlimited' => $value->unlimited];
        }

        return ApiResponse::success([
            'plan' => $set->plan ? ['id' => $set->plan->id, 'slug' => $set->plan->slug, 'name' => $set->plan->name] : null,
            'subscription_inactive' => $set->subscriptionInactive,
            'features' => $features,
            'limits' => $limits,
        ]);
    }

    /**
     * Current usage
     *
     * Usage against each numeric limit. `remaining` never goes below 0 and is null when unlimited;
     * `exceeded` is true when a downgrade left the farm above its limit (existing data is kept; only growth is
     * blocked). Team members count active members (Owner included) plus pending invitations. Requires `subscription.view`.
     *
     * @response array{data: array{limits: array<string, array{label: string, limit: int|null, unlimited: bool, usage: int, remaining: int|null, exceeded: bool}>}, meta: object, message: string|null}
     */
    public function usage(FarmContext $ctx, EntitlementService $entitlements): JsonResponse
    {
        $set = $entitlements->for($ctx->farm);

        $limits = [];
        foreach (Limit::cases() as $limit) {
            $value = $set->limit($limit);
            $usage = $entitlements->usage($ctx->farm, $limit);

            $limits[$limit->value] = [
                'label' => $limit->label(),
                'limit' => $value->value,
                'unlimited' => $value->unlimited,
                'usage' => $usage,
                'remaining' => $value->remaining($usage),
                'exceeded' => ! $value->unlimited && $usage > $value->value,
            ];
        }

        return ApiResponse::success(['limits' => $limits]);
    }

    /**
     * Cancel subscription
     *
     * Schedules the paid subscription to end at the close of the current period; access continues until
     * then. Requires `subscription.manage`. Errors: `409 subscription_not_cancellable` (default/free plan or
     * lapsed subscription), `409 subscription_already_cancelled`.
     * Payment checkout is not available yet (no billing provider is configured).
     */
    #[Response(status: 409, description: 'Nothing to cancel, or already cancelled', type: 'array{message: string, code: "subscription_not_cancellable"|"subscription_already_cancelled", request_id: string}')]
    #[Response(status: 200, type: 'array{data: \App\Http\Resources\SubscriptionResource, meta: object, message: string}')]
    public function cancel(Request $request, FarmContext $ctx, SubscriptionService $subscriptions): JsonResponse
    {
        $subscription = $subscriptions->cancel($ctx->farm, $request->user());

        return ApiResponse::success((new SubscriptionResource($subscription))->resolve($request), message: 'Subscription will end at the close of the current period.');
    }

    /**
     * Resume subscription
     *
     * Withdraws a scheduled cancellation while the paid period is still running. Requires `subscription.manage`.
     * Errors: `409 subscription_not_cancelled`.
     */
    #[Response(status: 409, description: 'No scheduled cancellation', type: 'array{message: string, code: "subscription_not_cancelled", request_id: string}')]
    #[Response(status: 200, type: 'array{data: \App\Http\Resources\SubscriptionResource, meta: object, message: string}')]
    public function resume(Request $request, FarmContext $ctx, SubscriptionService $subscriptions): JsonResponse
    {
        $subscription = $subscriptions->resume($ctx->farm, $request->user());

        return ApiResponse::success((new SubscriptionResource($subscription))->resolve($request), message: 'Subscription resumed.');
    }
}
