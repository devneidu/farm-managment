<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Records\CopyCustomRecordTypeRequest;
use App\Http\Requests\Records\ListCustomRecordTypesRequest;
use App\Http\Requests\Records\StoreCustomRecordTypeRequest;
use App\Http\Requests\Records\UpdateCustomRecordTypeRequest;
use App\Http\Resources\CustomRecordTypeResource;
use App\Services\Records\CustomRecordTypeService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Farm-made record types. Field kinds: `number` (optional `unit`, `min`, `max`), `whole_number`, `short_text` (200), `long_text` (2000), `date`,
 * `yes_no`, `choose_one` (`options`). At most 15 fields. Recording with a type is `POST /records` with `type: "custom"`.
 */
class CustomRecordTypeController extends Controller
{
    /**
     * List record types made by this farm and public ones
     *
     * The farm's own types (`source: "farm"`) and the platform's public ones (`source: "public"`), A-Z. Archived types only with
     * `include_archived=true`. `records_count` counts THIS farm's records. Requires `master_data.view`.
     *
     * @response array{data: CustomRecordTypeResource[], meta: object, message: null}
     */
    public function index(ListCustomRecordTypesRequest $request, FarmContext $ctx, CustomRecordTypeService $service): JsonResponse
    {
        return ApiResponse::success(CustomRecordTypeResource::collection($service->list($ctx, $request->safe()->all()))->resolve($request));
    }

    /**
     * Show a record type
     *
     * The farm's own or a public type; another farm's private type is `404`. Requires `master_data.view`.
     *
     * @response array{data: CustomRecordTypeResource, meta: object, message: null}
     */
    public function show(Request $request, FarmContext $ctx, CustomRecordTypeService $service, string $type): JsonResponse
    {
        return ApiResponse::success((new CustomRecordTypeResource($service->find($ctx, $type)))->resolve($request));
    }

    /**
     * Make a record type
     *
     * Requires `master_data.manage` (Owner, Manager; the same permission as custom breeds and varieties). The farm comes from your session.
     * Each field: `label`, `kind`, optional `required`, `hidden`, and `unit`/`min`/`max` (number) or `options` (choose_one). The `key` is made from the label.
     *
     * Errors: `403`, `409 duplicate_name`, `422` (invalid field, a built-in record type's name, more than 15 fields).
     */
    #[Response(status: 201, description: 'Record type made', type: 'array{data: \App\Http\Resources\CustomRecordTypeResource, meta: object, message: string}')]
    #[Response(status: 409, description: 'A record type with this name already exists', type: 'array{message: string, code: "duplicate_name", request_id: string, details?: array{existing_id: string, source: string, status: string}}')]
    public function store(StoreCustomRecordTypeRequest $request, FarmContext $ctx, CustomRecordTypeService $service): JsonResponse
    {
        return ApiResponse::success((new CustomRecordTypeResource($service->create($ctx, $request->user(), $request->validated())))->resolve($request), message: 'Record type created.', status: 201);
    }

    /**
     * Edit a record type
     *
     * Send the complete `fields` list (existing fields keep their `key`). Once records use the type: you may add fields, rename labels, add choices,
     * make a required field optional, reorder, hide fields and widen number limits. You may NOT delete a field, change its kind, remove a choice,
     * make an optional field required, change a unit, narrow limits or change who the type applies to: `409 custom_type_in_use` with a clear message.
     * `version` goes up when the fields change; records keep the version they were captured with. Public types are `404` here. Requires `master_data.manage`.
     *
     * @response array{data: CustomRecordTypeResource, meta: object, message: string}
     */
    #[Response(status: 409, description: 'The edit would change what existing records mean', type: 'array{message: string, code: "custom_type_in_use"|"duplicate_name", request_id: string, details?: array{field_key?: string, rule: string}}')]
    public function update(UpdateCustomRecordTypeRequest $request, FarmContext $ctx, CustomRecordTypeService $service, string $type): JsonResponse
    {
        return ApiResponse::success((new CustomRecordTypeResource($service->update($ctx, $request->user(), $type, $request->validated())))->resolve($request), message: 'Record type updated.');
    }

    /**
     * Archive a record type
     *
     * It disappears from the record forms; its existing records stay readable. `409 type_already_archived`. Requires `master_data.manage`.
     *
     * @response array{data: CustomRecordTypeResource, meta: object, message: string}
     */
    public function archive(Request $request, FarmContext $ctx, CustomRecordTypeService $service, string $type): JsonResponse
    {
        return ApiResponse::success((new CustomRecordTypeResource($service->archive($ctx, $request->user(), $type)))->resolve($request), message: 'Record type archived.');
    }

    /**
     * Unarchive a record type
     *
     * `409 type_not_archived` when it is not archived. Requires `master_data.manage`.
     *
     * @response array{data: CustomRecordTypeResource, meta: object, message: string}
     */
    public function unarchive(Request $request, FarmContext $ctx, CustomRecordTypeService $service, string $type): JsonResponse
    {
        return ApiResponse::success((new CustomRecordTypeResource($service->unarchive($ctx, $request->user(), $type)))->resolve($request), message: 'Record type restored.');
    }

    /**
     * Copy a public record type into this farm
     *
     * Makes a private, editable copy (version 1) with the same fields; optional `name` (default: the public name). Only public active types can be
     * copied (`404` otherwise). While the farm has an active copy, the public original no longer shows in its record forms. Requires `master_data.manage`.
     *
     * @response array{data: CustomRecordTypeResource, meta: object, message: string}
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\CustomRecordTypeResource, meta: object, message: string}')]
    public function copy(CopyCustomRecordTypeRequest $request, FarmContext $ctx, CustomRecordTypeService $service, string $type): JsonResponse
    {
        return ApiResponse::success((new CustomRecordTypeResource($service->copy($ctx, $request->user(), $type, $request->validated('name'))))->resolve($request), message: 'Record type copied.', status: 201);
    }
}
