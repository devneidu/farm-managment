<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PlaceKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Locations\ListPlacesRequest;
use App\Http\Requests\Locations\StoreStorageLocationRequest;
use App\Http\Requests\Locations\UpdateStorageLocationRequest;
use App\Http\Resources\PlaceResource;
use App\Services\Locations\PlaceService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorageLocationController extends Controller
{
    /**
     * List storage locations
     *
     * Active by default. Name/id ordering, pagination (50 by default, max 100), complete path on each row.
     * Parent IDs always refer to locations of this farm. No operation filter. Requires location.view.
     *
     * @response array{data: PlaceResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: null}
     */
    #[Response(status: 404, description: 'Parent not found in this farm', type: 'array{message: string, code: "not_found", request_id: string}')]
    public function index(ListPlacesRequest $request, FarmContext $ctx, PlaceService $service): JsonResponse
    {
        $page = $service->listing($ctx->farm, PlaceKind::StorageLocation, $request->validated());

        return ApiResponse::success(PlaceResource::collection($page->getCollection())->resolve($request), [
            'current_page' => $page->currentPage(), 'per_page' => $page->perPage(),
            'last_page' => $page->lastPage(), 'total' => $page->total(),
        ]);
    }

    /**
     * Show storage location
     *
     * Includes inactive places for history. Foreign-farm UUIDs return 404. Requires location.view.
     *
     * @response array{data: PlaceResource, meta: object, message: null}
     */
    public function show(Request $request, FarmContext $ctx, PlaceService $service, string $place): JsonResponse
    {
        return ApiResponse::success((new PlaceResource($service->find($ctx->farm, PlaceKind::StorageLocation, $place)))->resolve($request));
    }

    /**
     * Create storage location
     *
     * Parent is optional; zero places is valid. Names unique per kind/farm/parent (case and whitespace normalized),
     * including inactive rows. At most seven ancestors; parent must be active. Requires location.manage.
     * Errors: 404 unknown/foreign parent; 409 duplicate_location, location_inactive, location_depth_exceeded.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\PlaceResource, meta: object, message: string}')]
    #[Response(status: 404, type: 'array{message: string, code: "not_found", request_id: string}')]
    #[Response(status: 409, type: 'array{message: string, code: "duplicate_location"|"location_inactive"|"location_depth_exceeded", request_id: string}')]
    public function store(StoreStorageLocationRequest $request, FarmContext $ctx, PlaceService $service): JsonResponse
    {
        $place = $service->save($ctx, $request->user(), PlaceKind::StorageLocation, $request->validated());

        return ApiResponse::success((new PlaceResource($place))->resolve($request), message: 'Place created.', status: 201);
    }

    /**
     * Edit / deactivate / reactivate storage location
     *
     * PATCH preserves omitted fields; parent_id=null moves to farm root. UUID stays unchanged. No DELETE.
     * Active descendants must be deactivated/moved first. Reparenting checks the whole subtree's depth.
     * Inactive places remain readable and reserve their names. Requires location.manage.
     *
     * @response array{data: PlaceResource, meta: object, message: string}
     */
    #[Response(status: 409, type: 'array{message: string, code: "duplicate_location"|"location_cycle"|"location_inactive"|"location_depth_exceeded"|"location_has_active_children", request_id: string}')]
    public function update(UpdateStorageLocationRequest $request, FarmContext $ctx, PlaceService $service, string $place): JsonResponse
    {
        $updated = $service->save($ctx, $request->user(), PlaceKind::StorageLocation, $request->validated(), $place);

        return ApiResponse::success((new PlaceResource($updated))->resolve($request), message: 'Place updated.');
    }
}
