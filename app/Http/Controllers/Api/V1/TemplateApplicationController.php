<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Work\ListTemplateApplicationsRequest;
use App\Http\Resources\TemplateApplicationResource;
use App\Services\Work\WorkTemplateService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TemplateApplicationController extends Controller
{
    /**
     * Applied routines
     *
     * Requires task.view. Routines applied on this farm, newest first. Filter by production_cycle_id, breeding_project_id, target=general, work_template_id and
     * status (active by default, removed, all). Each row has the routine, the start date, applied_at and task_counts {open, completed, cancelled}.
     *
     * @response array{data: TemplateApplicationResource[], meta: object, message: null}
     */
    public function index(ListTemplateApplicationsRequest $request, FarmContext $ctx, WorkTemplateService $service): JsonResponse
    {
        return ApiResponse::success(TemplateApplicationResource::collection($service->applications($ctx, $request->validated()))->resolve($request));
    }

    /**
     * Remove a routine from its batch
     *
     * Requires task.manage. Ends every schedule the application created and cancels their open tasks due today or later (cancel reason "Routine removed").
     * Completed tasks and open tasks already overdue are untouched. One transaction; removing again returns the same result (idempotent). 404 for another farm's application.
     */
    public function remove(Request $request, FarmContext $ctx, WorkTemplateService $service, string $application): JsonResponse
    {
        return ApiResponse::success((new TemplateApplicationResource($service->remove($ctx, $application)))->resolve($request), message: 'Routine removed.');
    }
}
