<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\ListPlatformCustomRecordTypesRequest;
use App\Http\Resources\Platform\CustomRecordTypeAdminResource;
use App\Services\Platform\PlatformCustomRecordTypeService;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Record types made by farms. Reads: any platform role. Writes: role `admin`, audited as `platform.custom_record_type_*`.
 * The platform sees definitions and usage counts only - never a farm's records.
 */
class PlatformCustomRecordTypeController extends Controller
{
    use Paginates;

    /**
     * List farm-made record types
     *
     * `source=farm` (default) lists what farms made; `source=public` lists the platform's public types. Each row carries the fields, the farm that
     * made it, `records_count` and `farms_using` (counts, not records), `copies_count` and `public_copy_id`.
     *
     * @response array{data: CustomRecordTypeAdminResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: string|null}
     */
    public function index(ListPlatformCustomRecordTypesRequest $request, PlatformCustomRecordTypeService $types): JsonResponse
    {
        $page = $types->list($request->validated());

        return $this->page($page, CustomRecordTypeAdminResource::collection($page->getCollection())->resolve($request));
    }

    /**
     * Show a record type
     *
     * @response array{data: CustomRecordTypeAdminResource, meta: object, message: string|null}
     */
    public function show(Request $request, string $type, PlatformCustomRecordTypeService $types): JsonResponse
    {
        return ApiResponse::success((new CustomRecordTypeAdminResource($types->find($type)))->resolve($request));
    }

    /**
     * Make a farm's record type public
     *
     * Creates a PUBLIC copy (hidden fields dropped, version 1) that every farm sees and can use or copy. The farm's original stays private and
     * unchanged. `409 type_archived`, `409 already_public` (details.existing_id), `409 duplicate_name` when a public type has this name.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\Platform\CustomRecordTypeAdminResource, meta: object, message: string|null}')]
    public function makePublic(Request $request, string $type, PlatformCustomRecordTypeService $types): JsonResponse
    {
        return ApiResponse::success((new CustomRecordTypeAdminResource($types->makePublic($request->user(), $type)))->resolve($request), status: 201);
    }

    /**
     * Archive a public record type
     *
     * Farms can no longer record with it; records already made stay readable and farm copies are unaffected. Farm-made types are `404` here
     * (the farm archives its own). `409 type_already_archived`.
     *
     * @response array{data: CustomRecordTypeAdminResource, meta: object, message: string|null}
     */
    public function archive(Request $request, string $type, PlatformCustomRecordTypeService $types): JsonResponse
    {
        return ApiResponse::success((new CustomRecordTypeAdminResource($types->archive($request->user(), $type)))->resolve($request));
    }

    /**
     * Unarchive a public record type
     *
     * `409 type_not_archived` when it is not archived.
     *
     * @response array{data: CustomRecordTypeAdminResource, meta: object, message: string|null}
     */
    public function unarchive(Request $request, string $type, PlatformCustomRecordTypeService $types): JsonResponse
    {
        return ApiResponse::success((new CustomRecordTypeAdminResource($types->unarchive($request->user(), $type)))->resolve($request));
    }
}
