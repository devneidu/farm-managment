<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\ListCustomRecordsRequest;
use App\Http\Requests\MasterData\StoreCustomBreedRequest;
use App\Http\Requests\MasterData\UpdateCustomBreedRequest;
use App\Http\Resources\BreedResource;
use App\Models\Breed;
use App\Models\Species;
use App\Services\MasterData\CustomMasterDataService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/** This farm's custom breeds. System breeds are never listed here and can never be changed through these routes. */
class CustomBreedController extends Controller
{
    /**
     * List custom breeds
     *
     * Only THIS farm's custom breeds (for a settings/management view; for dropdowns use
     * `GET /master/species/{species}/breeds`). Filters: `species_id`, `include_inactive=true`. Requires `master_data.view`.
     *
     * @response array{data: BreedResource[], meta: object, message: string|null}
     */
    public function index(ListCustomRecordsRequest $request, FarmContext $ctx): JsonResponse
    {
        $breeds = Breed::query()->customOf($ctx->farm)
            ->when($request->validated('species_id'), fn ($q, $id) => $q->where('species_id', $id))
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
            ->orderBy('name')->get();

        return ApiResponse::success(BreedResource::collection($breeds)->resolve($request));
    }

    /**
     * Create a custom breed
     *
     * Adds a breed for this farm only (it never becomes a platform breed). The farm comes from your session, not the
     * body; `farm_id` and `code` are rejected. Names are trimmed and compared case-insensitively against system
     * breeds and this farm's breeds of the same species. Requires `master_data.manage`.
     *
     * Errors: `403`, `409 duplicate_name` (details: existing_id, source, is_active), `422` (unknown or inactive species,
     * invalid name), `429`.
     */
    #[Response(status: 201, description: 'Custom breed created', type: 'array{data: \App\Http\Resources\BreedResource, meta: object, message: string}')]
    #[Response(status: 409, description: 'A breed with this name already exists', type: 'array{message: string, code: "duplicate_name", request_id: string, details?: array{existing_id: string, source: "system"|"farm", is_active: bool}}')]
    public function store(StoreCustomBreedRequest $request, FarmContext $ctx, CustomMasterDataService $service): JsonResponse
    {
        $breed = $service->createBreed($ctx, $request->user(), Species::findOrFail($request->validated('species_id')), $request->validated('name'));

        return ApiResponse::success((new BreedResource($breed))->resolve($request), message: 'Custom breed created.', status: 201);
    }

    /**
     * Update / deactivate / reactivate a custom breed
     *
     * Rename (`name`) and/or toggle `is_active`. Deactivate instead of deleting: inactive breeds disappear from
     * selectors but stay valid for history. Species, farm and code can never change. System breeds and other farms'
     * breeds are `404`. Requires `master_data.manage`.
     *
     * Errors: `403`, `404`, `409 duplicate_name`, `422`.
     *
     * @response array{data: BreedResource, meta: object, message: string|null}
     */
    #[Response(status: 409, description: 'A breed with this name already exists', type: 'array{message: string, code: "duplicate_name", request_id: string}')]
    public function update(UpdateCustomBreedRequest $request, FarmContext $ctx, CustomMasterDataService $service, string $breed): JsonResponse
    {
        $model = Breed::query()->customOf($ctx->farm)->findOrFail($breed);

        $updated = $service->update($ctx, $request->user(), $model, $request->safe()->only(['name', 'is_active']));

        return ApiResponse::success((new BreedResource($updated))->resolve($request), message: 'Custom breed updated.');
    }
}
