<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TaskCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Work\CancelTaskRequest;
use App\Http\Requests\Work\CompleteTaskRequest;
use App\Http\Requests\Work\EditSeriesRequest;
use App\Http\Requests\Work\ListTasksRequest;
use App\Http\Requests\Work\StoreTaskRequest;
use App\Http\Requests\Work\UpdateTaskRequest;
use App\Http\Resources\ScheduleResource;
use App\Http\Resources\TaskResource;
use App\Services\Work\ScheduleService;
use App\Services\Work\TaskService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    /** {schedule, tasks}: the schedule and the tasks it has generated so far. */
    private function series($schedule, ScheduleService $schedules, Request $request, string $message, array $extra = []): JsonResponse
    {
        return ApiResponse::success([
            'schedule' => (new ScheduleResource($schedule))->resolve($request),
            'tasks' => TaskResource::collection($schedules->tasksOf($schedule->id))->resolve($request),
        ] + $extra, message: $message, status: 201);
    }

    private function task($task, Request $request): array
    {
        return (new TaskResource($task))->resolve($request);
    }

    /**
     * Task categories
     *
     * Requires task.view. The closed category vocabulary for tasks, schedules and templates.
     *
     * @response array{data: array<int, array{code: string, label: string, description: string, color_group: 'feeding_eggs_growth'|'health_vaccines'|'breeding'|'cleaning_repairs_moves'|'crops'|'orders_records_other'}>, meta: object, message: null}
     */
    public function categories(): JsonResponse
    {
        return ApiResponse::success(array_map(fn (TaskCategory $c) => ['code' => $c->value, 'label' => $c->label(), 'description' => $c->description(), 'color_group' => $c->colorGroup()], TaskCategory::cases()));
    }

    /**
     * List tasks
     *
     * Requires task.view. Owners and managers see every task of the current farm; other roles see tasks assigned to them or their role,
     * tasks they created and their role's relevant categories (others return 404). Ordered by due time. due_state is derived (upcoming,
     * due_today, overdue, completed, cancelled) and also works as a filter; the farm-local day decides "today".
     *
     * @response array{data: TaskResource[], meta: array{current_page:int,per_page:int,last_page:int,total:int}, message:null}
     */
    public function index(ListTasksRequest $request, FarmContext $ctx, TaskService $service): JsonResponse
    {
        $page = $service->list($ctx, $request->validated());

        return ApiResponse::success(TaskResource::collection($page->getCollection())->resolve($request), ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }

    /**
     * Create a task
     *
     * Requires task.manage. Standalone, or for a production cycle / breeding project (a project implies its cycle). Work is only ever
     * created on an open cycle (409 cycle_closed) and an active project (409 project_not_active). Creating a task never creates an operational
     * record. linked_record_type says which actual record will evidence it. The required idempotency_key is farm-wide: an identical retry
     * returns the original task (201), a changed payload is 409 idempotency_conflict.
     *
     * With a `repeat` block ({pattern: daily|weekly|monthly, every, weekdays, ends: {type: days|date|batch_end|never, days, date}}) this creates a repeating
     * schedule instead and answers 201 {schedule, tasks}: the schedule and the tasks generated so far (today through the next 30 days). batch_end needs a
     * production cycle or breeding project. `reminders` (on_the_day, 1_day, 2_days, 7_days) are accepted beside the older `reminder_offsets` (minutes).
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\TaskResource, meta: object, message:string}')]
    #[Response(status: 409, type: 'array{message:string, code:"cycle_closed"|"project_not_active"|"idempotency_conflict", request_id:string}')]
    public function store(StoreTaskRequest $request, FarmContext $ctx, TaskService $service, ScheduleService $schedules): JsonResponse
    {
        if ($request->validated('repeat') !== null) {
            return $this->series($schedules->createFromTask($ctx, $request->validated()), $schedules, $request, 'Repeating task created.');
        }

        return ApiResponse::success($this->task($service->create($ctx, $request->validated()), $request), message: 'Task created.', status: 201);
    }

    /**
     * Show a task
     *
     * Requires task.view (same visibility as the list).
     *
     * @response array{data: TaskResource, meta: object, message: null}
     */
    public function show(Request $request, FarmContext $ctx, TaskService $service, string $task): JsonResponse
    {
        return ApiResponse::success($this->task($service->find($ctx, $task), $request));
    }

    /**
     * Update an open task
     *
     * Requires task.manage. Reschedule, retitle or reassign (assigned_user_id must be an active member; assigned_role is a role name). Completed
     * and cancelled tasks are immutable (409 task_not_open); the cycle/project context never changes and must still be open.
     */
    #[Response(status: 409, type: 'array{message:string, code:"task_not_open"|"cycle_closed"|"project_not_active", request_id:string}')]
    public function update(UpdateTaskRequest $request, FarmContext $ctx, TaskService $service, string $task): JsonResponse
    {
        return ApiResponse::success($this->task($service->update($ctx, $task, $request->validated()), $request), message: 'Task updated.');
    }

    /**
     * Complete a task
     *
     * Requires task.complete (and visibility of the task). Completing records only that the work was done: it NEVER creates a mortality,
     * vaccination, feed, income or any other operational record. To evidence the task, first save the actual record through its own endpoint
     * (see record-prefill), then send evidence {type, id}; the record must be of this farm, not reversed, match the task's linked type and
     * context, and can evidence only one task (409 evidence_already_linked). Tasks with requires_evidence need it (422). An identical
     * repeat returns the completed task; different evidence is 409 task_already_completed. Closed cycles: 409 cycle_closed.
     */
    #[Response(status: 409, type: 'array{message:string, code:"task_not_open"|"task_already_completed"|"cycle_closed"|"project_not_active"|"evidence_already_linked", request_id:string}')]
    public function complete(CompleteTaskRequest $request, FarmContext $ctx, TaskService $service, string $task): JsonResponse
    {
        return ApiResponse::success($this->task($service->complete($ctx, $task, $request->validated()), $request), message: 'Task completed.');
    }

    /**
     * Cancel a task
     *
     * Requires task.manage. Open tasks only (409 task_not_open); the task is kept with its reason. Allowed on a closed cycle.
     */
    #[Response(status: 409, type: 'array{message:string, code:"task_not_open", request_id:string}')]
    public function cancel(CancelTaskRequest $request, FarmContext $ctx, TaskService $service, string $task): JsonResponse
    {
        return ApiResponse::success($this->task($service->cancel($ctx, $task, $request->validated()), $request), message: 'Task cancelled.');
    }

    /**
     * Record form prefill for a task
     *
     * Requires task.view. Read-only: returns the endpoint (`endpoint` = `url`, absolute /api/v1/...; `path` = the same route relative to /api/v1) and the cycle/project/date context for the actual record this task is linked to.
     * Nothing is saved. Flow: GET this -> POST the record to the returned endpoint -> POST /tasks/{id}/complete with the saved record as evidence.
     * 409 task_has_no_linked_record when the task has no linked_record_type.
     *
     * @response array{data: array{task_id: string, evidence_type: string, method: string, endpoint: string, url: string, path: string, prefill: array<string, mixed>, instructions: string}, meta: object, message: null}
     */
    public function recordPrefill(FarmContext $ctx, TaskService $service, string $task): JsonResponse
    {
        return ApiResponse::success($service->recordPrefill($ctx, $task));
    }

    /**
     * Edit this and all future tasks
     *
     * Requires task.manage. For a task that belongs to a repeating series: ends the old schedule the day before this task's occurrence date, cancels its open
     * tasks from this date on (completed tasks are never touched), and creates a new schedule from this date with the changes. Fields not sent carry over from
     * this task; `repeat` not sent keeps the old rule. 201 {schedule, tasks, previous_schedule_id}. 409 task_not_in_series / task_not_open / cycle_closed /
     * idempotency_conflict. A replay with the same idempotency_key returns the same new schedule. To change only this one task use PATCH /tasks/{id}.
     */
    #[Response(status: 409, type: 'array{message:string, code:"task_not_in_series"|"task_not_open"|"cycle_closed"|"project_not_active"|"idempotency_conflict", request_id:string}')]
    public function editSeries(EditSeriesRequest $request, FarmContext $ctx, ScheduleService $schedules, string $task): JsonResponse
    {
        $previous = \App\Models\Task::where('farm_id', $ctx->farm->id)->whereKey($task)->value('schedule_id');

        return $this->series($schedules->editSeries($ctx, $task, $request->validated()), $schedules, $request, 'Series updated.', ['previous_schedule_id' => $previous]);
    }
}
