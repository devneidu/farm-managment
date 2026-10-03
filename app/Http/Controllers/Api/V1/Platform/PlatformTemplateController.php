<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\ListPlatformTemplatesRequest;
use App\Http\Requests\Platform\StorePlatformTemplateRequest;
use App\Http\Requests\Platform\UpdatePlatformTemplateRequest;
use App\Http\Resources\Platform\PlatformTemplateResource;
use App\Services\Platform\PlatformTemplateService;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform work templates. Lifecycle: `draft` -> `published` -> `archived` (-> `published`). Farms only ever see published platform templates.
 * Reads: any platform role. Writes: role `admin`, audited as `platform.template_*`. Templates are never deleted.
 */
class PlatformTemplateController extends Controller
{
    use Paginates;

    /**
     * List platform templates
     *
     * @response array{data: PlatformTemplateResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: string|null}
     */
    public function index(ListPlatformTemplatesRequest $request, PlatformTemplateService $templates): JsonResponse
    {
        $page = $templates->list($request->validated());

        return $this->page($page, PlatformTemplateResource::collection($page->getCollection())->resolve($request));
    }

    /**
     * Show a platform template
     *
     * @response array{data: PlatformTemplateResource, meta: object, message: string|null}
     */
    public function show(Request $request, string $template, PlatformTemplateService $templates): JsonResponse
    {
        return ApiResponse::success((new PlatformTemplateResource($templates->find($template)))->resolve($request));
    }

    /**
     * Create a draft template
     *
     * Same content shape as a farm template plus a permanent unique `code`. It is created as a DRAFT (invisible to farms). Items are
     * validated like farm templates (anchors must match `applies_to`, recurrence rules, linked record types).
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\Platform\PlatformTemplateResource, meta: object, message: string|null}')]
    public function store(StorePlatformTemplateRequest $request, PlatformTemplateService $templates): JsonResponse
    {
        return ApiResponse::success((new PlatformTemplateResource($templates->create($request->user(), $request->validated())))->resolve($request), status: 201);
    }

    /**
     * Update a template
     *
     * Edits a draft or published template (`409 template_archived` for an archived one). A supplied `items` list replaces the previous one.
     * Editing a PUBLISHED template is re-validated for publication and raises `version`; schedules farms already created from it are unaffected.
     *
     * @response array{data: PlatformTemplateResource, meta: object, message: string|null}
     */
    public function update(UpdatePlatformTemplateRequest $request, string $template, PlatformTemplateService $templates): JsonResponse
    {
        return ApiResponse::success((new PlatformTemplateResource($templates->update($request->user(), $template, $request->validated())))->resolve($request));
    }

    /**
     * Publish a template
     *
     * draft or archived -> published. Re-validates the whole template: at least one item, referenced operation type/species/crop type active,
     * and a breeding template pinned to a species needs that species to support its workflow (`422`). `409 template_already_published`
     * when it already is.
     *
     * @response array{data: PlatformTemplateResource, meta: object, message: string|null}
     */
    public function publish(Request $request, string $template, PlatformTemplateService $templates): JsonResponse
    {
        return ApiResponse::success((new PlatformTemplateResource($templates->publish($request->user(), $template)))->resolve($request));
    }

    /**
     * Archive a template
     *
     * published -> archived. It stops being recommended or applied; schedules already created from it are untouched.
     * `409 template_not_published` otherwise.
     *
     * @response array{data: PlatformTemplateResource, meta: object, message: string|null}
     */
    public function archive(Request $request, string $template, PlatformTemplateService $templates): JsonResponse
    {
        return ApiResponse::success((new PlatformTemplateResource($templates->archive($request->user(), $template)))->resolve($request));
    }
}
