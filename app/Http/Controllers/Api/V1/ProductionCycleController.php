<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Production\CloseCycleRequest;
use App\Http\Requests\Production\CycleActivityRequest;
use App\Http\Requests\Production\ListCyclesRequest;
use App\Http\Requests\Production\ReopenCycleRequest;
use App\Http\Requests\Production\StoreCycleRequest;
use App\Http\Requests\Production\UpdateCycleRequest;
use App\Http\Resources\ProductionCycleEventResource;
use App\Http\Resources\ProductionCycleResource;
use App\Services\Production\CycleService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductionCycleController extends Controller
{
    /**
     * List livestock batches and crop projects
     *
     * Requires production_cycle.view. All statuses by default, filtered to the current farm. Newest start date first,
     * then UUID; page size 50, maximum 100. kind=livestock includes fishery; kind=crop lists planting projects.
     *
     * @response array{data: ProductionCycleResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: null}
     */
    public function index(ListCyclesRequest $request, FarmContext $ctx, CycleService $service): JsonResponse
    {
        $page = $service->listing($ctx->farm, $request->validated());

        return ApiResponse::success(ProductionCycleResource::collection($page->getCollection())->resolve($request), ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }

    /**
     * Show livestock batch or crop project
     *
     * Requires production_cycle.view. Includes closed cycles and inactive historical catalogue/location selections.
     *
     * @response array{data: ProductionCycleResource, meta: object, message: null}
     */
    public function show(Request $request, FarmContext $ctx, CycleService $service, string $cycle): JsonResponse
    {
        return ApiResponse::success((new ProductionCycleResource($service->find($ctx->farm, $cycle)))->resolve($request));
    }

    /**
     * Start livestock batch or crop project
     *
     * Requires production_cycle.create. The active_cycles entitlement applies transactionally (409 plan_limit_reached).
     * kind=livestock requires species_id, initial_population and start_date. kind=crop requires crop_type_id,
     * planting_material_type, planting_unit_type, initial_planting_units and planting_date. Optional production_area_id.
     * Counts are positive whole numbers, maximum 999999999999. Crop area is a separate positive AREA measurement.
     * All starting identity/baseline fields are immutable after creation. No material consumption is inferred.
     * Catalogue mismatches/inactive selection return 422; unknown or foreign UUIDs return 404.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\ProductionCycleResource, meta: object, message: string}')]
    #[Response(status: 404, type: 'array{message: string, code: "not_found", request_id: string}')]
    #[Response(status: 409, type: 'array{message: string, code: "duplicate_cycle_name"|"location_inactive"|"plan_limit_reached", request_id: string, details?: object}')]
    public function store(StoreCycleRequest $request, FarmContext $ctx, CycleService $service): JsonResponse
    {
        return ApiResponse::success((new ProductionCycleResource($service->create($ctx, $request->user(), $request->validated())))->resolve($request), message: 'Production cycle created.', status: 201);
    }

    /**
     * Edit active livestock batch or crop project
     *
     * Requires production_cycle.update. Editable: name, notes, production_area_id, expected_end_date; crops additionally
     * area and expected_germination_date. Null clears optional fields. Baseline fields return 409 baseline_locked.
     * Closed cycles return 409 cycle_closed. Current population, farm ownership and status cannot be edited.
     *
     * @response array{data: ProductionCycleResource, meta: object, message: string}
     */
    #[Response(status: 409, type: 'array{message: string, code: "baseline_locked"|"cycle_closed"|"duplicate_cycle_name"|"location_inactive", request_id: string}')]
    public function update(UpdateCycleRequest $request, FarmContext $ctx, CycleService $service, string $cycle): JsonResponse
    {
        return ApiResponse::success((new ProductionCycleResource($service->update($ctx, $request->user(), $cycle, $request->validated())))->resolve($request), message: 'Production cycle updated.');
    }

    /**
     * Close production cycle
     *
     * Requires production_cycle.close. active -> closed only. Checks baseline/initial movement reconciliation.
     * end_date must be between start/planting date and today in farm timezone. Reason required. Preserves population;
     * does not create a sale, harvest, exit, inventory or finance record. Locks ordinary edits and releases capacity.
     *
     * @response array{data: ProductionCycleResource, meta: object, message: string}
     */
    #[Response(status: 409, type: 'array{message: string, code: "invalid_status_transition"|"cycle_reconciliation_failed", request_id: string}')]
    public function close(CloseCycleRequest $request, FarmContext $ctx, CycleService $service, string $cycle): JsonResponse
    {
        return ApiResponse::success((new ProductionCycleResource($service->transition($ctx, $request->user(), $cycle, true, $request->validated())))->resolve($request), message: 'Production cycle closed.');
    }

    /**
     * Reopen production cycle
     *
     * Requires production_cycle.reopen. closed -> active only; checks active_cycles capacity, requires a reason,
     * clears current end_date but preserves closure history. The starting baseline remains immutable.
     *
     * @response array{data: ProductionCycleResource, meta: object, message: string}
     */
    #[Response(status: 409, type: 'array{message: string, code: "invalid_status_transition"|"plan_limit_reached", request_id: string, details?: object}')]
    public function reopen(ReopenCycleRequest $request, FarmContext $ctx, CycleService $service, string $cycle): JsonResponse
    {
        return ApiResponse::success((new ProductionCycleResource($service->transition($ctx, $request->user(), $cycle, false, $request->validated())))->resolve($request), message: 'Production cycle reopened.');
    }

    /**
     * Production baseline summary
     *
     * Requires production_cycle.view. Phase 7 summary is the detail resource: initial/current livestock population or
     * crop planting baseline and separate area. No invented survival, yield, mortality or financial metrics.
     *
     * @response array{data: ProductionCycleResource, meta: object, message: null}
     */
    public function summary(Request $request, FarmContext $ctx, CycleService $service, string $cycle): JsonResponse
    {
        return $this->show($request, $ctx, $service, $cycle);
    }

    /**
     * Cycle lifecycle activity
     *
     * Requires production_cycle.view. Creation, descriptive/area updates, close and reopen only; newest first.
     * Operational records will be introduced in Phase 8. page >=1, per_page 1–100 (default 50).
     *
     * @response array{data: ProductionCycleEventResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: null}
     */
    public function activity(CycleActivityRequest $request, FarmContext $ctx, CycleService $service, string $cycle): JsonResponse
    {
        $data = $request->validated();
        $page = $service->find($ctx->farm, $cycle)->events()->orderByDesc('id')->paginate($data['per_page'] ?? 50);

        return ApiResponse::success(ProductionCycleEventResource::collection($page->getCollection())->resolve($request), ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }
}
