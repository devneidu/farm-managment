<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\Capability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\ListMasterRecordsRequest;
use App\Http\Requests\Platform\SetCapabilityRequest;
use App\Http\Requests\Platform\StoreMasterRecordRequest;
use App\Http\Requests\Platform\UpdateMasterRecordRequest;
use App\Http\Resources\Platform\MasterRecordResource;
use App\Models\Species;
use App\Services\Platform\PlatformMasterDataService;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reference data administration. `{kind}` is one of `operation-types`, `species`, `crop-types`, `breeds`, `varieties`, `reference-values`.
 * Reads: any platform role. Writes: role `admin`, audited as `platform.master_*` / `platform.species_capability_updated`.
 * There is no delete: deactivate instead, so farm records that reference a value are never broken. `breeds` and `varieties` here are the
 * SYSTEM catalogue only; a farm's own custom breeds/varieties are not visible or editable from the platform.
 */
class PlatformMasterDataController extends Controller
{
    use Paginates;

    /**
     * List reference records
     *
     * Includes inactive records. Filter with `q` (name/code), `is_active`, `parent_id`, `category` (operation types) or `list` (reference values).
     *
     * @response array{data: MasterRecordResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: string|null}
     */
    public function index(ListMasterRecordsRequest $request, string $kind, PlatformMasterDataService $master): JsonResponse
    {
        $page = $master->list($kind, $request->validated());

        return $this->page($page, MasterRecordResource::collection($page->getCollection())->resolve($request));
    }

    /**
     * Show a reference record
     *
     * @response array{data: MasterRecordResource, meta: object, message: string|null}
     */
    public function show(Request $request, string $kind, string $id, PlatformMasterDataService $master): JsonResponse
    {
        return ApiResponse::success((new MasterRecordResource($master->find($kind, $id)))->resolve($request));
    }

    /**
     * Create a reference record
     *
     * Fields depend on `{kind}`. `code` is the permanent machine identity (lowercase letters, digits, underscore) and must be unique.
     * A species/crop type must sit under an active operation type of the right category (`422` otherwise).
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\Platform\MasterRecordResource, meta: object, message: string|null}')]
    public function store(StoreMasterRecordRequest $request, string $kind, PlatformMasterDataService $master): JsonResponse
    {
        return ApiResponse::success((new MasterRecordResource($master->create($request->user(), $kind, $request->validated())))->resolve($request), status: 201);
    }

    /**
     * Update a reference record
     *
     * Name, ordering, activation (and a species' `livestock_group`). `code`, category, tracking model and parent cannot change.
     * Activating a record under an inactive parent fails with `422 parent_inactive`; deactivating an operation type that still has active
     * species or crop types fails with `409 has_active_children`. Deactivation hides the value from new selections; existing farm records keep it.
     *
     * @response array{data: MasterRecordResource, meta: object, message: string|null}
     */
    public function update(UpdateMasterRecordRequest $request, string $kind, string $id, PlatformMasterDataService $master): JsonResponse
    {
        return ApiResponse::success((new MasterRecordResource($master->update($request->user(), $kind, $id, $request->validated())))->resolve($request));
    }

    /**
     * Capability schemas
     *
     * Every species capability and the ONLY keys/rules its `reference_config` accepts (biological reference defaults, never behaviour).
     *
     * @response array{data: array<int, array{code: string, label: string, config_schema: array<string, string>}>, meta: object, message: string|null}
     */
    public function capabilitySchemas(PlatformMasterDataService $master): JsonResponse
    {
        return ApiResponse::success($master->capabilitySchemas());
    }

    /**
     * Species capabilities
     *
     * All capabilities for a species with their enabled state and reference configuration (unconfigured ones are `enabled: false`).
     *
     * @response array{data: array<int, array{code: string, label: string, enabled: bool, reference_config: object|null}>, meta: object, message: string|null}
     */
    public function speciesCapabilities(Species $species, PlatformMasterDataService $master): JsonResponse
    {
        return ApiResponse::success($master->speciesCapabilities($species));
    }

    /**
     * Set a species capability
     *
     * Replaces the capability's enabled state and `reference_config`. The config is validated against the capability's schema (unknown keys,
     * out-of-range values and inconsistent ranges are `422`). Compatibility rules: incubation/pregnancy need `supports_breeding`
     * (`422 capability_dependency`), `supports_breeding` cannot be disabled while a workflow capability is on (`409 capability_dependency`), and
     * a capability cannot be disabled while active breeding projects of that species rely on it (`409 capability_in_use`). Breeding projects
     * already created keep their stored reference snapshot. Audited as `platform.species_capability_updated` with before/after.
     *
     * @response array{data: array<int, array{code: string, label: string, enabled: bool, reference_config: object|null}>, meta: object, message: string|null}
     */
    public function setCapability(SetCapabilityRequest $request, Species $species, string $capability, PlatformMasterDataService $master): JsonResponse
    {
        $capability = Capability::tryFrom($capability) ?? abort(404);
        $data = $request->validated();

        return ApiResponse::success($master->setCapability($request->user(), $species, $capability, $data['enabled'], $data['reference_config'] ?? null));
    }
}
