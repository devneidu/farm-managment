<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Measurement\ListUnitsRequest;
use App\Http\Resources\MeasurementDimensionResource;
use App\Http\Resources\UnitResource;
use App\Services\Measurement\UnitCatalogue;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Standard measurement reference data for building selectors. Requires `measurement.view` (every role); not plan-gated.
 * Units are always requested per dimension - there is deliberately no "all units" listing.
 */
class MeasurementCatalogueController extends Controller
{
    /**
     * List measurement dimensions
     *
     * The kinds of measurement the system understands: `weight`, `volume`, `area`, `count`, `temperature` and
     * `package`. A form field declares which dimension it needs (water = `volume`, live weight = `weight`, land
     * area = `area`) and then loads only that dimension's units with `GET /master/units?dimension={code}`.
     * `supports_preference` says whether the farm may pick a default unit for it (`/settings/units`);
     * `canonical_unit` is the unit quantities are normalized to internally.
     *
     * @response array{data: MeasurementDimensionResource[], meta: object, message: string|null}
     */
    public function dimensions(Request $request, UnitCatalogue $catalogue): JsonResponse
    {
        return ApiResponse::success(MeasurementDimensionResource::collection($catalogue->dimensions())->resolve($request));
    }

    /**
     * List units of one dimension
     *
     * `dimension` is REQUIRED and returns only that dimension's units: `volume` -> ml, cl, L (never kg, acre or
     * crate); `weight` -> mg, g, kg, tonne, lb; `area` -> m², hectare, acre. Use it directly as the options of a unit
     * dropdown. Optional `family` narrows a dimension further (`count` units each form their own family: `head`,
     * `egg`, `piece`, `planting_unit` - 50 heads are never 50 eggs). The `package` dimension lists container types
     * (bag, crate, ...) which carry NO quantity: what they hold is a farm/context setting
     * (`/settings/package-conversions`). Inactive units are hidden unless `include_inactive=true`.
     *
     * Errors: `422` unknown or missing `dimension`.
     *
     * @response array{data: UnitResource[], meta: array{dimension: string}, message: string|null}
     */
    public function units(ListUnitsRequest $request, UnitCatalogue $catalogue): JsonResponse
    {
        $units = $catalogue->units($request->validated('dimension'), $request->validated('family'), $request->boolean('include_inactive'));

        return ApiResponse::success(UnitResource::collection($units)->resolve($request), ['dimension' => $request->validated('dimension')]);
    }
}
