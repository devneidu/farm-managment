<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Measurement\UpdateUnitPreferencesRequest;
use App\Http\Resources\UnitResource;
use App\Services\Measurement\UnitPreferenceService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The farm's preferred display/entry unit per dimension ("Units & Measurements" settings). Never alters stored quantities. */
class UnitPreferenceController extends Controller
{
    /**
     * Get unit preferences
     *
     * One entry per dimension that supports a preference (weight, volume, area, temperature): the farm's chosen unit,
     * or the default (`is_default: true`; Nigeria-first: kg, L, hectare, Celsius). Use the unit to pre-select the
     * dropdown and as the default entry unit. Requires `measurement.view`.
     *
     * @response array{data: array{preferences: array{dimension: array{code: string, name: string}, unit: UnitResource|null, is_default: bool}[]}, meta: object, message: string|null}
     */
    public function show(Request $request, FarmContext $ctx, UnitPreferenceService $service): JsonResponse
    {
        return ApiResponse::success($this->payload($request, $ctx, $service));
    }

    /**
     * Update unit preferences
     *
     * Send `preferences` as `{ "<dimension code>": "<unit code>" | null }`, for example
     * `{"weight": "kg", "volume": "l"}`. `null` resets that dimension to the default; dimensions you omit are left
     * alone. The unit must belong to the dimension and be selectable. The farm comes from your session, never the body.
     * Preferences only affect what units are pre-selected; they never change stored quantities. Requires
     * `measurement.manage`.
     *
     * Errors: `403`, `422` validation, `422 unknown_unit`, `422 unit_not_selectable`, `422 unit_dimension_mismatch`
     * (details: unit, required_dimension), `429`.
     *
     * @response array{data: array{preferences: array{dimension: array{code: string, name: string}, unit: UnitResource|null, is_default: bool}[]}, meta: object, message: string|null}
     */
    public function update(UpdateUnitPreferencesRequest $request, FarmContext $ctx, UnitPreferenceService $service): JsonResponse
    {
        $service->update($ctx->farm, $request->validated('preferences'));

        return ApiResponse::success($this->payload($request, $ctx, $service), message: 'Unit preferences updated.');
    }

    private function payload(Request $request, FarmContext $ctx, UnitPreferenceService $service): array
    {
        return ['preferences' => array_map(fn (array $row) => [
            'dimension' => ['code' => $row['dimension']->code, 'name' => $row['dimension']->name],
            'unit' => $row['unit'] ? (new UnitResource($row['unit']))->resolve($request) : null,
            'is_default' => $row['is_default'],
        ], $service->forFarm($ctx->farm))];
    }
}
