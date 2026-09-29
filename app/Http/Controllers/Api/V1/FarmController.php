<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Farm\UpdateFarmRequest;
use App\Http\Resources\FarmResource;
use App\Services\Farm\FarmSettingsService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class FarmController extends Controller
{
    /**
     * Get the current farm
     *
     * The farm the request operates on (your oldest active membership, or the one named by the
     * optional `X-Farm-Id` header) plus what YOU may do on it: `membership.role` and
     * `membership.permissions`. Use the permission list to show/hide UI; the backend still enforces it.
     *
     * @response array{data: FarmResource, meta: object, message: string|null}
     */
    public function show(FarmContext $ctx): JsonResponse
    {
        return ApiResponse::success((new FarmResource($ctx->farm))->resolve());
    }

    /**
     * Update farm settings
     *
     * Only the farm name is editable. Country, currency, timezone and language keep the platform
     * defaults; any other field is ignored.
     *
     * Errors: `403 forbidden` (missing `farm.update`), `422`.
     *
     * @response array{data: FarmResource, meta: object, message: string|null}
     */
    public function update(UpdateFarmRequest $request, FarmContext $ctx, FarmSettingsService $settings): JsonResponse
    {
        $farm = $settings->update($ctx->farm, $request->user(), $request->validated());

        return ApiResponse::success((new FarmResource($farm))->resolve(), message: 'Farm updated.');
    }
}
