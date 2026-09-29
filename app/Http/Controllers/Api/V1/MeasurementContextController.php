<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Measurement\ListMeasurementContextsRequest;
use App\Http\Requests\Measurement\StoreMeasurementContextRequest;
use App\Http\Requests\Measurement\UpdateMeasurementContextRequest;
use App\Http\Resources\MeasurementContextResource;
use App\Models\MeasurementContext;
use App\Services\Measurement\MeasurementContextService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/**
 * This farm's own measurement contexts ("Feed Grower Mash", "Eggs"): what a package conversion is about when it is not a
 * crop. Each has a stable `id`; the `name` is display only. Another farm's contexts are invisible (`404`).
 */
class MeasurementContextController extends Controller
{
    /**
     * List measurement contexts
     *
     * This farm's contexts ordered by name; `include_inactive=true` also returns deactivated ones. Requires
     * `measurement.view`.
     *
     * @response array{data: MeasurementContextResource[], meta: object, message: string|null}
     */
    public function index(ListMeasurementContextsRequest $request, FarmContext $ctx): JsonResponse
    {
        $contexts = MeasurementContext::ofFarm($ctx->farm)
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('normalized_name')->orderBy('id')->get();

        return ApiResponse::success(MeasurementContextResource::collection($contexts)->resolve($request));
    }

    /**
     * Create a measurement context
     *
     * `name` (2-100 characters) must be unique in this farm ignoring case and extra spaces. Returns the context whose
     * `id` is then used as `context_id` (`context_type=custom`) when defining package conversions. The farm comes from
     * your session. Requires `measurement.manage`.
     *
     * Errors: `403`, `409 measurement_context_exists` (details: existing_id, is_active), `422` validation, `429`.
     */
    #[Response(status: 201, description: 'Measurement context created', type: 'array{data: \App\Http\Resources\MeasurementContextResource, meta: object, message: string}')]
    #[Response(status: 409, description: 'A context with this name already exists', type: 'array{message: string, code: "measurement_context_exists", request_id: string, details?: array{existing_id: string, is_active: bool}}')]
    public function store(StoreMeasurementContextRequest $request, FarmContext $ctx, MeasurementContextService $service): JsonResponse
    {
        $context = $service->create($ctx, $request->validated('name'));

        return ApiResponse::success((new MeasurementContextResource($context))->resolve($request), message: 'Measurement context created.', status: 201);
    }

    /**
     * Rename / deactivate / reactivate a measurement context
     *
     * Renaming changes only what is displayed: the `id`, the package conversions attached to it and every stored
     * snapshot are unaffected. A deactivated context stops being usable for new package conversions and new entries;
     * past records are untouched. Other farms' ids are `404`. Requires `measurement.manage`.
     *
     * Errors: `403`, `404`, `409 measurement_context_exists`, `422` validation, `429`.
     *
     * @response array{data: MeasurementContextResource, meta: object, message: string|null}
     */
    public function update(UpdateMeasurementContextRequest $request, FarmContext $ctx, MeasurementContextService $service, string $context): JsonResponse
    {
        $model = MeasurementContext::ofFarm($ctx->farm)->findOrFail($context);

        $updated = $service->update($ctx, $model, $request->validated());

        return ApiResponse::success((new MeasurementContextResource($updated))->resolve($request), message: 'Measurement context updated.');
    }
}
