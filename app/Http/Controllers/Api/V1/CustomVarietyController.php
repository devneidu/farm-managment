<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\ListCustomRecordsRequest;
use App\Http\Requests\MasterData\StoreCustomVarietyRequest;
use App\Http\Requests\MasterData\UpdateCustomVarietyRequest;
use App\Http\Resources\CropVarietyResource;
use App\Models\CropType;
use App\Models\CropVariety;
use App\Services\MasterData\CustomMasterDataService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/** This farm's custom crop varieties. System varieties are never listed here and can never be changed through these routes. */
class CustomVarietyController extends Controller
{
    /**
     * List custom crop varieties
     *
     * Only THIS farm's custom varieties (for dropdowns use `GET /master/crops/{crop}/varieties`). Filters:
     * `crop_type_id`, `include_inactive=true`. Requires `master_data.view`.
     *
     * @response array{data: CropVarietyResource[], meta: object, message: string|null}
     */
    public function index(ListCustomRecordsRequest $request, FarmContext $ctx): JsonResponse
    {
        $varieties = CropVariety::query()->customOf($ctx->farm)
            ->when($request->validated('crop_type_id'), fn ($q, $id) => $q->where('crop_type_id', $id))
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
            ->orderBy('name')->get();

        return ApiResponse::success(CropVarietyResource::collection($varieties)->resolve($request));
    }

    /**
     * Create a custom crop variety
     *
     * Adds a variety for this farm only. The farm comes from your session; `farm_id` and `code` are rejected.
     * Requires `master_data.manage`.
     *
     * Errors: `403`, `409 duplicate_name`, `422` (unknown or inactive crop, invalid name), `429`.
     */
    #[Response(status: 201, description: 'Custom variety created', type: 'array{data: \App\Http\Resources\CropVarietyResource, meta: object, message: string}')]
    #[Response(status: 409, description: 'A variety with this name already exists', type: 'array{message: string, code: "duplicate_name", request_id: string, details?: array{existing_id: string, source: "system"|"farm", is_active: bool}}')]
    public function store(StoreCustomVarietyRequest $request, FarmContext $ctx, CustomMasterDataService $service): JsonResponse
    {
        $variety = $service->createVariety($ctx, $request->user(), CropType::findOrFail($request->validated('crop_type_id')), $request->validated('name'));

        return ApiResponse::success((new CropVarietyResource($variety))->resolve($request), message: 'Custom variety created.', status: 201);
    }

    /**
     * Update / deactivate / reactivate a custom variety
     *
     * Rename and/or toggle `is_active`; crop, farm and code can never change. System varieties and other farms'
     * varieties are `404`. Requires `master_data.manage`.
     *
     * Errors: `403`, `404`, `409 duplicate_name`, `422`.
     *
     * @response array{data: CropVarietyResource, meta: object, message: string|null}
     */
    #[Response(status: 409, description: 'A variety with this name already exists', type: 'array{message: string, code: "duplicate_name", request_id: string}')]
    public function update(UpdateCustomVarietyRequest $request, FarmContext $ctx, CustomMasterDataService $service, string $variety): JsonResponse
    {
        $model = CropVariety::query()->customOf($ctx->farm)->findOrFail($variety);

        $updated = $service->update($ctx, $request->user(), $model, $request->safe()->only(['name', 'is_active']));

        return ApiResponse::success((new CropVarietyResource($updated))->resolve($request), message: 'Custom variety updated.');
    }
}
