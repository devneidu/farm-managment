<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\MasterDataListRequest;
use App\Http\Resources\BreedResource;
use App\Http\Resources\CropTypeResource;
use App\Http\Resources\CropVarietyResource;
use App\Http\Resources\OperationTypeResource;
use App\Http\Resources\SpeciesCapabilitiesResource;
use App\Http\Resources\SpeciesResource;
use App\Models\CropType;
use App\Models\ReferenceValue;
use App\Models\Species;
use App\Services\MasterData\FarmOperationService;
use App\Services\MasterData\MasterDataCatalogue;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only agricultural master data for building selectors. Every route requires `master_data.view` (all roles);
 * none is plan-gated. Custom items of OTHER farms are never included.
 */
class MasterDataController extends Controller
{
    /**
     * List farm operations (production types)
     *
     * Livestock, aquaculture and crop operation types in display order. Use `category` / `tracking_model`
     * (`population` for livestock and fish, `planting_units` for crops) to drive forms; never match on `name`.
     * `selected` is what the farm chose via `PUT /farm/operations`; `available` is what it may use now
     * (everything, while the farm has selected nothing). `meta.farm_operations_configured` says whether a selection
     * exists. Filters: `category` (livestock|aquaculture|crop), `available=true` (only usable operations),
     * `include_inactive=true`.
     *
     * @response array{data: OperationTypeResource[], meta: array{farm_operations_configured: bool}, message: string|null}
     */
    public function operations(MasterDataListRequest $request, FarmContext $ctx, MasterDataCatalogue $catalogue, FarmOperationService $farmOperations): JsonResponse
    {
        $selected = $farmOperations->selectedIds($ctx->farm);
        $configured = $selected !== [];

        $operations = $catalogue->operationTypes($ctx->farm, $request->filters())->each(function ($operation) use ($selected, $configured) {
            $operation->setAttribute('selected', in_array($operation->id, $selected, true));
            $operation->setAttribute('available', ! $configured || in_array($operation->id, $selected, true));
        });

        return ApiResponse::success(OperationTypeResource::collection($operations)->resolve($request), ['farm_operations_configured' => $configured]);
    }

    /**
     * List species
     *
     * Livestock and fish species with their operation and enabled capability codes. Filters: `operation`
     * (operation code, e.g. `poultry`), `category`, `available=true` (only species of operations the farm may use),
     * `include_inactive=true`.
     *
     * @response array{data: SpeciesResource[], meta: object, message: string|null}
     */
    public function species(MasterDataListRequest $request, FarmContext $ctx, MasterDataCatalogue $catalogue): JsonResponse
    {
        return ApiResponse::success(SpeciesResource::collection($catalogue->species($ctx->farm, $request->filters()))->resolve($request));
    }

    /**
     * Get species capabilities
     *
     * All known capabilities for the species with `enabled` and biological `reference` defaults (for example
     * incubation or gestation days). Reference values are configurable starting points - not guarantees, farm
     * targets or observed outcomes; an unknown value is `null`/absent. Drive dynamic forms from `enabled`.
     *
     * Errors: `404` unknown species.
     *
     * @response array{data: SpeciesCapabilitiesResource, meta: object, message: string|null}
     */
    public function capabilities(Request $request, string $species): JsonResponse
    {
        $model = Species::with(['operationType', 'speciesCapabilities.capability'])->findOrFail($species);

        return ApiResponse::success((new SpeciesCapabilitiesResource($model))->resolve($request));
    }

    /**
     * List breeds of a species
     *
     * Active system breeds plus THIS farm's active custom breeds (`source` = `system` | `farm`); use it for the
     * breed dropdown with an "add custom breed" option (`POST /custom-breeds`). `include_inactive=true` also returns
     * inactive ones (to display historical values).
     *
     * Errors: `404` unknown species.
     *
     * @response array{data: BreedResource[], meta: object, message: string|null}
     */
    public function breeds(MasterDataListRequest $request, FarmContext $ctx, MasterDataCatalogue $catalogue, string $species): JsonResponse
    {
        $model = Species::findOrFail($species);

        return ApiResponse::success(BreedResource::collection($catalogue->breeds($ctx->farm, $model, $request->filters()['include_inactive']))->resolve($request));
    }

    /**
     * Livestock batch reference for one selected animal
     *
     * Requires master_data.view. System and own-farm breeds, species-specific purposes and biological starting stages.
     * include_inactive=true includes inactive historical options; these cannot be used for a new batch.
     * breed_field_label is backend-managed. No individual tracking or population-unit changes.
     *
     * @response array{data: array{species: SpeciesResource, breed_field_label: string, breeds: BreedResource[], purposes: list<array{code: string, name: string, is_active: bool}>, growth_stages: list<array{code: string, name: string, is_active: bool}>}, meta: object, message: null}
     */
    public function batchReference(MasterDataListRequest $request, FarmContext $ctx, MasterDataCatalogue $catalogue, string $species): JsonResponse
    {
        $model = Species::with(['operationType', 'speciesCapabilities.capability'])->findOrFail($species);
        $includeInactive = $request->filters()['include_inactive'];
        $options = fn ($kind) => $catalogue->batchOptions($model, $kind, $includeInactive)
            ->map(fn ($row) => $row->only(['code', 'name', 'is_active']))->all();

        return ApiResponse::success([
            'species' => (new SpeciesResource($model))->resolve($request),
            'breed_field_label' => $model->breed_field_label ?? 'Breed / Strain',
            'breeds' => BreedResource::collection($catalogue->breeds($ctx->farm, $model, $includeInactive))->resolve($request),
            'purposes' => $options('purpose'), 'growth_stages' => $options('stage'),
        ]);
    }

    /**
     * List crops
     *
     * Crop types. Filters: `available=true` (only crops of operations the farm may use), `include_inactive=true`.
     *
     * @response array{data: CropTypeResource[], meta: object, message: string|null}
     */
    public function crops(MasterDataListRequest $request, FarmContext $ctx, MasterDataCatalogue $catalogue): JsonResponse
    {
        return ApiResponse::success(CropTypeResource::collection($catalogue->crops($ctx->farm, $request->filters()))->resolve($request));
    }

    /**
     * List varieties of a crop
     *
     * Active system varieties plus THIS farm's active custom varieties (`source` = `system` | `farm`). A variety is
     * optional: the list may be empty and a crop project never requires one. `include_inactive=true` also returns
     * inactive ones.
     *
     * Errors: `404` unknown crop.
     *
     * @response array{data: CropVarietyResource[], meta: object, message: string|null}
     */
    public function varieties(MasterDataListRequest $request, FarmContext $ctx, MasterDataCatalogue $catalogue, string $crop): JsonResponse
    {
        $model = CropType::findOrFail($crop);

        return ApiResponse::success(CropVarietyResource::collection($catalogue->varieties($ctx->farm, $model, $request->filters()['include_inactive']))->resolve($request));
    }

    /**
     * Planting reference lists
     *
     * The two SEPARATE lists a crop project will use: `material_types` (what is planted: seed, seedling, stem
     * cutting, tuber, sucker, other) and `unit_types` (how planting is counted: heap, hole, stand). Number of planting
     * units is NOT the quantity of planting material.
     *
     * @response array{data: array{material_types: array{code: string, name: string}[], unit_types: array{code: string, name: string}[]}, meta: object, message: string|null}
     */
    public function plantingReference(): JsonResponse
    {
        $list = fn (string $name) => ReferenceValue::where('list', $name)->where('is_active', true)
            ->orderBy('sort_order')->get()->map(fn ($v) => ['code' => $v->code, 'name' => $v->name])->all();

        return ApiResponse::success([
            'material_types' => $list(ReferenceValue::PLANTING_MATERIAL_TYPE),
            'unit_types' => $list(ReferenceValue::PLANTING_UNIT_TYPE),
        ]);
    }
}
