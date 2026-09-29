<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    /**
     * List plans
     *
     * The public plan catalogue for pricing/comparison pages: active, public plans in display order
     * (`sort_order`, then slug). Each plan lists every known feature and limit, so a comparison table needs
     * no hard-coded plan knowledge. Prices are integer minor units (kobo) - use `amount_minor`, not `formatted`.
     * Prices and limits are provisional and configurable; do not hard-code them in the frontend.
     *
     * @unauthenticated
     *
     * @response array{data: PlanResource[], meta: object, message: string|null}
     */
    public function index(Request $request): JsonResponse
    {
        $plans = Plan::listed()->with('prices')->get();

        return ApiResponse::success(PlanResource::collection($plans)->resolve($request));
    }
}
