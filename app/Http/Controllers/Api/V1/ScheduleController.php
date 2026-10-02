<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Work\EndScheduleRequest;
use App\Http\Requests\Work\ListSchedulesRequest;
use App\Http\Requests\Work\StoreScheduleRequest;
use App\Http\Resources\ScheduleResource;
use App\Services\Work\ScheduleService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    private function schedule($schedule, Request $request): array
    {
        return (new ScheduleResource($schedule))->resolve($request);
    }

    /**
     * List schedules
     *
     * Requires task.manage. Filter by status (active|ended), production_cycle_id, breeding_project_id.
     *
     * @response array{data: ScheduleResource[], meta: array{current_page:int,per_page:int,last_page:int,total:int}, message:null}
     */
    public function index(ListSchedulesRequest $request, FarmContext $ctx, ScheduleService $service): JsonResponse
    {
        $page = $service->list($ctx, $request->validated());

        return ApiResponse::success(ScheduleResource::collection($page->getCollection())->resolve($request), ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }

    /**
     * Create a schedule
     *
     * Requires task.manage. A recurrence rule that generates TASKS (never operational records): recurrence none (one task on starts_on),
     * daily (every interval_value days) or weekly (every interval_value weeks on weekdays, default the weekday of starts_on), bounded by
     * ends_on and/or occurrence_limit. Tasks are generated for today through the next 30 days (past dates are not generated) and topped up
     * daily by the work:generate-tasks command; (schedule, date) is unique so nothing is ever duplicated. Open cycle / active project
     * required (409). idempotency_key is farm-wide (replay 201, changed payload 409).
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\ScheduleResource, meta: object, message:string}')]
    #[Response(status: 409, type: 'array{message:string, code:"cycle_closed"|"project_not_active"|"idempotency_conflict", request_id:string}')]
    public function store(StoreScheduleRequest $request, FarmContext $ctx, ScheduleService $service): JsonResponse
    {
        return ApiResponse::success($this->schedule($service->create($ctx, $request->validated()), $request), message: 'Schedule created.', status: 201);
    }

    /**
     * Show a schedule
     *
     * Requires task.manage. Includes tasks_count and open_tasks_count.
     *
     * @response array{data: ScheduleResource, meta: object, message: null}
     */
    public function show(Request $request, FarmContext $ctx, ScheduleService $service, string $schedule): JsonResponse
    {
        return ApiResponse::success($this->schedule($service->find($ctx, $schedule), $request));
    }

    /**
     * End a schedule
     *
     * Requires task.manage. Stops generating tasks (idempotent). Existing tasks are kept; set cancel_future_tasks to also cancel open tasks due after today.
     */
    public function end(EndScheduleRequest $request, FarmContext $ctx, ScheduleService $service, string $schedule): JsonResponse
    {
        return ApiResponse::success($this->schedule($service->end($ctx, $schedule, $request->validated()), $request), message: 'Schedule ended.');
    }
}
