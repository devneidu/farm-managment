<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Measurement\ListPackageConversionsRequest;
use App\Http\Requests\Measurement\StorePackageConversionRequest;
use App\Http\Requests\Measurement\UpdatePackageConversionRequest;
use App\Http\Resources\PackageConversionResource;
use App\Models\PackageConversion;
use App\Services\Measurement\PackageConversionService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/**
 * This farm's package conversions ("1 crate of Eggs = 30 pieces", "1 bag of Maize = 50 kg"). A bag or crate means
 * nothing universally: every definition belongs to a context, and another farm's definitions are invisible (`404`).
 */
class PackageConversionController extends Controller
{
    /**
     * List package conversions
     *
     * This farm's definitions, ordered by context label. Filters: `context_type` (`crop_type` | `custom`), `context_id`,
     * `package_unit` (unit code), `include_inactive=true` (deactivated ones are hidden by default). Requires
     * `measurement.view`, so workers can see what a crate or bag holds while recording.
     *
     * @response array{data: PackageConversionResource[], meta: object, message: string|null}
     */
    public function index(ListPackageConversionsRequest $request, FarmContext $ctx): JsonResponse
    {
        $conversions = PackageConversion::query()
            ->with(['packageUnit.dimension', 'targetUnit.dimension', ...PackageConversion::CONTEXT_RELATIONS])
            ->where('farm_id', $ctx->farm->id)
            ->when($request->validated('context_type'), fn ($q, $v) => $q->where('context_type', $v))
            ->when($request->validated('context_id'), fn ($q, $v) => $q->where('context_id', $v))
            ->when($request->validated('package_unit'), fn ($q, $v) => $q->whereHas('packageUnit', fn ($u) => $u->where('code', $v)))
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->get()->sortBy(fn (PackageConversion $c) => [mb_strtolower($c->context_label), $c->id])->values();

        return ApiResponse::success(PackageConversionResource::collection($conversions)->resolve($request));
    }

    /**
     * Create a package conversion
     *
     * Defines what a package holds in a context. A context is always chosen by `context_type` + `context_id`: a crop
     * (`crop_type` + an id from `GET /master/crops`, e.g. Maize) or one of this farm's own measurement contexts
     * (`custom` + an id from `GET /settings/measurement-contexts`, e.g. "Feed Grower Mash", "Eggs"; create it first with
     * `POST /settings/measurement-contexts`). The id must resolve to an active crop or an active context of this farm,
     * otherwise `422` on `context_id`. `package_unit` is a `package` unit code (bag, crate, ...);
     * `target_unit` is a weight, volume, area or count unit code; `quantity_per_package` is an exact decimal above
     * zero (up to 12 digits + 6 decimals; whole when the target is a count such as `piece`). So Feed and Maize can
     * both have a `bag` with different contents. The farm comes from your session, never the body. Requires
     * `measurement.manage`.
     *
     * Errors: `403`, `409 conversion_exists` (details: existing_id, is_active), `422` validation,
     * `422 invalid_conversion_ratio`, `422 unknown_unit`, `422 unit_not_selectable`, `422 unit_dimension_mismatch`,
     * `422 incompatible_units` (a package cannot contain a package), `429`.
     */
    #[Response(status: 201, description: 'Package conversion created', type: 'array{data: \App\Http\Resources\PackageConversionResource, meta: object, message: string}')]
    #[Response(status: 409, description: 'A definition already exists for this package in this context', type: 'array{message: string, code: "conversion_exists", request_id: string, details?: array{existing_id: string, is_active: bool}}')]
    public function store(StorePackageConversionRequest $request, FarmContext $ctx, PackageConversionService $service): JsonResponse
    {
        $conversion = $service->create($ctx, $request->user(), $request->validated());

        return ApiResponse::success((new PackageConversionResource($conversion))->resolve($request), message: 'Package conversion created.', status: 201);
    }

    /**
     * Update / deactivate / reactivate a package conversion
     *
     * Change `quantity_per_package`, `target_unit` and/or `is_active`. The package unit and the context can never
     * change - create another definition instead (rename a context with `PATCH /settings/measurement-contexts/{id}`). A change to quantity, target
     * or active state increases `version`. Past normalized quantities are NOT affected: each keeps its own snapshot of
     * the definition it used. A deactivated definition stops resolving for new entries. Other farms' ids are `404`.
     * Requires `measurement.manage`.
     *
     * Errors: `403`, `404`, `422` validation, `422 invalid_conversion_ratio`, `422 unit_not_selectable`, `429`.
     *
     * @response array{data: PackageConversionResource, meta: object, message: string|null}
     */
    public function update(UpdatePackageConversionRequest $request, FarmContext $ctx, PackageConversionService $service, string $conversion): JsonResponse
    {
        $model = PackageConversion::query()->where('farm_id', $ctx->farm->id)->findOrFail($conversion);

        $updated = $service->update($ctx, $request->user(), $model, $request->validated());

        return ApiResponse::success((new PackageConversionResource($updated))->resolve($request), message: 'Package conversion updated.');
    }
}
