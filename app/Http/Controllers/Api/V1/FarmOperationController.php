<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\UpdateFarmOperationsRequest;
use App\Http\Resources\OperationTypeResource;
use App\Models\OperationType;
use App\Services\MasterData\FarmOperationService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The farm's optional operation selection. Never part of onboarding; an empty selection blocks nothing. */
class FarmOperationController extends Controller
{
    /**
     * Get the farm's operations
     *
     * The operations this farm has selected (may be empty) and `configured` (false = nothing selected, so every
     * operation is treated as available). Requires `farm.view`.
     *
     * @response array{data: array{configured: bool, operations: OperationTypeResource[]}, meta: object, message: string|null}
     */
    public function show(Request $request, FarmContext $ctx, FarmOperationService $service): JsonResponse
    {
        return ApiResponse::success($this->payload($request, $ctx, $service));
    }

    /**
     * Set the farm's operations
     *
     * Replaces the selection with `operation_ids` (active operation ids from `GET /master/farm-operations`). An empty
     * array clears it. This never deletes data and no existing access depends on it. Requires `farm.update`.
     *
     * Errors: `403`, `422` (unknown/inactive/duplicate id).
     *
     * @response array{data: array{configured: bool, operations: OperationTypeResource[]}, meta: object, message: string|null}
     */
    public function update(UpdateFarmOperationsRequest $request, FarmContext $ctx, FarmOperationService $service): JsonResponse
    {
        $service->sync($ctx->farm, $request->validated('operation_ids'));

        return ApiResponse::success($this->payload($request, $ctx, $service), message: 'Farm operations updated.');
    }

    private function payload(Request $request, FarmContext $ctx, FarmOperationService $service): array
    {
        $selected = $service->selectedIds($ctx->farm);

        $operations = OperationType::query()->ordered()->whereIn('id', $selected)->get()->each(function ($operation) {
            $operation->setAttribute('selected', true);
            $operation->setAttribute('available', true);
        });

        return [
            'configured' => $selected !== [],
            'operations' => OperationTypeResource::collection($operations)->resolve($request),
        ];
    }
}
