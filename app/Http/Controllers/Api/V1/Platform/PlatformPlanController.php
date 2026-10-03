<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\Feature;
use App\Enums\Limit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\ListPlansRequest;
use App\Http\Requests\Platform\SetPlanEntitlementsRequest;
use App\Http\Requests\Platform\SetPlanPricesRequest;
use App\Http\Requests\Platform\StorePlanRequest;
use App\Http\Requests\Platform\UpdatePlanRequest;
use App\Http\Resources\Platform\PlatformPlanResource;
use App\Models\Plan;
use App\Services\Platform\PlatformPlanService;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Plan catalogue administration. Reads: any platform role. Writes: platform role `admin`. Every write is audited (`platform.plan_*`).
 * Plans are never deleted. The default plan is the free fallback: it cannot be deactivated or priced.
 */
class PlatformPlanController extends Controller
{
    use Paginates;

    /**
     * List plans
     *
     * Every plan including inactive and non-public ones, with all prices, features, limits and the number of farms currently subscribed.
     *
     * @response array{data: PlatformPlanResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: string|null}
     */
    public function index(ListPlansRequest $request, PlatformPlanService $plans): JsonResponse
    {
        $page = $plans->list($request->validated());

        return $this->page($page, PlatformPlanResource::collection($page->getCollection())->resolve($request));
    }

    /**
     * Show a plan
     *
     * @response array{data: PlatformPlanResource, meta: object, message: string|null}
     */
    public function show(Request $request, Plan $plan, PlatformPlanService $plans): JsonResponse
    {
        return ApiResponse::success((new PlatformPlanResource($plans->find($plan->id)))->resolve($request));
    }

    /**
     * Entitlement registry
     *
     * The valid feature and limit keys a plan can be configured with (the registries the application enforces).
     *
     * @response array{data: array{features: array<int, array{key: string, label: string}>, limits: array<int, array{key: string, label: string}>}, meta: object, message: string|null}
     */
    public function registry(): JsonResponse
    {
        return ApiResponse::success([
            'features' => array_map(fn (Feature $f) => ['key' => $f->value, 'label' => $f->label()], Feature::cases()),
            'limits' => array_map(fn (Limit $l) => ['key' => $l->value, 'label' => $l->label()], Limit::cases()),
        ]);
    }

    /**
     * Create a plan
     *
     * Creates an INACTIVE, non-default plan that grants nothing until prices/entitlements are configured and it is activated with PATCH.
     * `slug` is permanent. Audited as `platform.plan_created`.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\Platform\PlatformPlanResource, meta: object, message: string|null}')]
    public function store(StorePlanRequest $request, PlatformPlanService $plans): JsonResponse
    {
        return ApiResponse::success((new PlatformPlanResource($plans->create($request->user(), $request->validated())))->resolve($request), status: 201);
    }

    /**
     * Update a plan
     *
     * Name, description, visibility, ordering and activation. `slug`/`currency` cannot change. Deactivating the default plan fails with
     * `409 default_plan_protected`; deactivating a plan that farms are still on fails with `409 plan_in_use` (move them first).
     * Audited as `platform.plan_updated` with before/after.
     *
     * @response array{data: PlatformPlanResource, meta: object, message: string|null}
     */
    public function update(UpdatePlanRequest $request, Plan $plan, PlatformPlanService $plans): JsonResponse
    {
        return ApiResponse::success((new PlatformPlanResource($plans->update($request->user(), $plan, $request->validated())))->resolve($request));
    }

    /**
     * Make a plan the default
     *
     * The default plan is what every new farm starts on and what a lapsed subscription falls back to. It must be active and free (no active
     * prices). Idempotent. Existing farms stay on their current plan. Audited as `platform.plan_default_changed`.
     *
     * @response array{data: PlatformPlanResource, meta: object, message: string|null}
     */
    public function makeDefault(Request $request, Plan $plan, PlatformPlanService $plans): JsonResponse
    {
        return ApiResponse::success((new PlatformPlanResource($plans->makeDefault($request->user(), $plan)))->resolve($request));
    }

    /**
     * Set plan prices
     *
     * Upserts the monthly and/or annual price (integer kobo). Omitted intervals are untouched; send `is_active: false` to withdraw a price.
     * The default plan cannot have an active price (`422 default_plan_must_be_free`). Audited as `platform.plan_prices_updated`.
     *
     * @response array{data: PlatformPlanResource, meta: object, message: string|null}
     */
    public function prices(SetPlanPricesRequest $request, Plan $plan, PlatformPlanService $plans): JsonResponse
    {
        return ApiResponse::success((new PlatformPlanResource($plans->setPrices($request->user(), $plan, $request->validated('prices'))))->resolve($request));
    }

    /**
     * Set plan entitlements
     *
     * Sets feature flags (`features: {advanced_reports: true}`) and limits (`limits: {team_members: {limit: 5}}` or `{unlimited: true}`).
     * Unknown keys are rejected with `422`. Omitted keys are untouched. Lowering a limit never deletes data: farms already over the new limit
     * keep what they have and are only blocked from growing. Takes effect immediately for every farm on the plan. Audited as
     * `platform.plan_entitlements_updated` with the previous and new values.
     *
     * @response array{data: PlatformPlanResource, meta: object, message: string|null}
     */
    public function entitlements(SetPlanEntitlementsRequest $request, Plan $plan, PlatformPlanService $plans): JsonResponse
    {
        $data = $request->validated();
        $features = $data['features'] ?? [];
        $limits = $data['limits'] ?? [];
        if ($features === [] && $limits === []) {
            throw ValidationException::withMessages(['features' => 'Provide at least one feature or limit.']);
        }
        $this->assertKnown('features', array_keys($features), array_map(fn (Feature $f) => $f->value, Feature::cases()));
        $this->assertKnown('limits', array_keys($limits), array_map(fn (Limit $l) => $l->value, Limit::cases()));
        foreach ($limits as $key => $value) {
            if (! empty($value['unlimited']) && ($value['limit'] ?? null) !== null) {
                throw ValidationException::withMessages(["limits.$key" => 'An unlimited limit has no number.']);
            }
        }

        return ApiResponse::success((new PlatformPlanResource($plans->setEntitlements($request->user(), $plan, $features, $limits)))->resolve($request));
    }

    /** @param  list<string|int>  $given */
    private function assertKnown(string $field, array $given, array $valid): void
    {
        foreach ($given as $key) {
            if (! in_array($key, $valid, true)) {
                throw ValidationException::withMessages(["$field.$key" => "Unknown $field key."]);
            }
        }
    }
}
