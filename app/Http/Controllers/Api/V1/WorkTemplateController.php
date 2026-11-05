<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Work\ApplyWorkTemplateRequest;
use App\Http\Requests\Work\ListWorkTemplatesRequest;
use App\Http\Requests\Work\PreviewWorkTemplateRequest;
use App\Http\Requests\Work\SaveAsRoutineRequest;
use App\Http\Requests\Work\StoreWorkTemplateRequest;
use App\Http\Requests\Work\UpdateWorkTemplateRequest;
use App\Http\Resources\TemplateApplicationResource;
use App\Http\Resources\WorkTemplateResource;
use App\Services\Work\WorkTemplateService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkTemplateController extends Controller
{
    private function template($template, Request $request): array
    {
        return (new WorkTemplateResource($template))->resolve($request);
    }

    /**
     * List routines (work templates)
     *
     * Requires task.view. Ready-made routines (source=platform, read-only) and the current farm's own (source=farm). Filter by source, applies_to
     * (production_cycle, breeding_project, farm = general), is_active. Each row has step_count, scope (any_batch|specific|general) and scope_label;
     * pass production_cycle_id (or breeding_project_id) to also get suits_batch.
     *
     * @response array{data: WorkTemplateResource[], meta: object, message: null}
     */
    public function index(ListWorkTemplatesRequest $request, FarmContext $ctx, WorkTemplateService $service): JsonResponse
    {
        return ApiResponse::success(WorkTemplateResource::collection($service->list($ctx, $request->validated()))->resolve($request));
    }

    /**
     * Recommended templates for a cycle or breeding project
     *
     * Requires task.view. Pass exactly one of production_cycle_id / breeding_project_id (query). Returns the active templates whose scope fits it
     * (kind, operation, species/crop, breeding workflow); application_id is set when already applied. Recommending applies nothing: the user may
     * apply, clone and customise, or ignore a template.
     *
     * @response array{data: WorkTemplateResource[], meta: object, message: null}
     */
    public function recommended(ListWorkTemplatesRequest $request, FarmContext $ctx, WorkTemplateService $service): JsonResponse
    {
        return ApiResponse::success(WorkTemplateResource::collection($service->recommended($ctx, $request->validated()))->resolve($request));
    }

    /**
     * Create a farm routine
     *
     * Requires task.manage. applies_to: production_cycle (any batch, or limited by cycle_kind / operation_type_id / species_id / crop_type_id), breeding_project, or
     * farm (a general routine with no batch; no filters, no record links, no batch_end). Each step uses the task form's language: `repeat` {pattern: daily|weekly|monthly,
     * every, weekdays, ends:{type: days|date|batch_end|never, days, date}} and `reminders` (on_the_day|1_day|2_days|7_days); the older flat fields (recurrence,
     * interval_value, until_offset_days, occurrence_limit, reminder_offsets) still work. A step's date is anchor + offset_days; the new anchor start_date is the
     * date chosen when the routine is applied (offset 0 = day 1) and is the default. Older anchors: cycle_start, cycle_expected_end (cycle templates) or
     * breeding_start, breeding_expected, breeding_expected_to (breeding templates).
     * Template data is configuration, not veterinary or agronomic advice.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\WorkTemplateResource, meta: object, message:string}')]
    public function store(StoreWorkTemplateRequest $request, FarmContext $ctx, WorkTemplateService $service): JsonResponse
    {
        return ApiResponse::success($this->template($service->create($ctx, $request->validated()), $request), message: 'Template created.', status: 201);
    }

    /**
     * Show a work template
     *
     * Requires task.view. Platform or own-farm templates only; foreign farm templates return 404.
     *
     * @response array{data: WorkTemplateResource, meta: object, message: null}
     */
    public function show(Request $request, FarmContext $ctx, WorkTemplateService $service, string $template): JsonResponse
    {
        return ApiResponse::success($this->template($service->find($ctx, $template), $request));
    }

    /**
     * Update a farm template
     *
     * Requires task.manage. Farm templates only (403 platform_template_readonly: clone instead). Every edit increments version; a supplied
     * items list replaces the previous items. Schedules already applied keep their own copy and are never rewritten.
     */
    #[Response(status: 403, type: 'array{message:string, code:"platform_template_readonly"|"forbidden", request_id:string}')]
    public function update(UpdateWorkTemplateRequest $request, FarmContext $ctx, WorkTemplateService $service, string $template): JsonResponse
    {
        return ApiResponse::success($this->template($service->update($ctx, $template, $request->validated()), $request), message: 'Template updated.');
    }

    /**
     * Clone a template
     *
     * Requires task.manage. Creates an editable farm copy (version 1) of any visible template, optionally renamed. This is the "customise" step.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\WorkTemplateResource, meta: object, message:string}')]
    public function clone(Request $request, FarmContext $ctx, WorkTemplateService $service, string $template): JsonResponse
    {
        return ApiResponse::success($this->template($service->clone($ctx, $template, $request->only('name')), $request), message: 'Template cloned.', status: 201);
    }

    /**
     * Apply a routine
     *
     * Requires task.manage. Pass start_date (day 1 = offset 0 of start_date-anchored steps; default: the batch start date, or today for a general routine). A general
     * routine takes no batch. Applying the same routine to the same target again needs confirm_reapply=true, else 409 template_already_applied with details
     * {times_applied, last_applied_at, applications}. Materialises the template's items as independent schedules (and their tasks for today through the next 30 days)
     * relative to the target's own dates: cycle start / expected end, or the breeding start / stored expected date or window. Occurrences already in
     * the past are skipped (skipped_past); items whose anchor does not exist are skipped (skipped_no_anchor, e.g. no breeding expectation). No
     * operational record is ever created. The target must be open/active (409). The
     * required idempotency_key is farm-wide: an identical retry returns the original application. 422 when the template does not fit the target.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\TemplateApplicationResource, meta: object, message:string}')]
    #[Response(status: 409, type: 'array{message:string, code:"template_already_applied"|"template_inactive"|"cycle_closed"|"project_not_active"|"idempotency_conflict", request_id:string}')]
    public function apply(ApplyWorkTemplateRequest $request, FarmContext $ctx, WorkTemplateService $service, string $template): JsonResponse
    {
        return ApiResponse::success((new TemplateApplicationResource($service->apply($ctx, $template, $request->validated())))->resolve($request), message: 'Template applied.', status: 201);
    }

    /**
     * Preview a routine
     *
     * Requires task.manage. Saves nothing. Body: target (batch id, or leave out for a general routine), breeding_project_id (breeding routines), start_date. Returns the jobs
     * that WOULD be created in the first 30 days (the window applying materialises), a summary of repeats that continue after it (coming_repeats), counts, and
     * times_already_applied. Same checks as apply (404 / 409 / 422).
     */
    public function preview(PreviewWorkTemplateRequest $request, FarmContext $ctx, WorkTemplateService $service, string $template): JsonResponse
    {
        return ApiResponse::success($service->preview($ctx, $template, $request->validated()));
    }

    /**
     * Save a task as a routine
     *
     * Requires task.manage. Creates a one-step farm routine from the task, or from its whole series when it repeats (repeat, ends, reminders, category, instructions
     * copied; offset 0 from start_date). scope: any_batch (default for a batch task), same_kind (only batches of the same species / crop) or general (default for a
     * task without a batch; not possible for steps that end with the batch or link a record).
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\WorkTemplateResource, meta: object, message:string}')]
    public function saveAsRoutine(SaveAsRoutineRequest $request, FarmContext $ctx, WorkTemplateService $service, string $task): JsonResponse
    {
        return ApiResponse::success($this->template($service->saveAsRoutine($ctx, $task, $request->validated()), $request), message: 'Routine saved.', status: 201);
    }
}
