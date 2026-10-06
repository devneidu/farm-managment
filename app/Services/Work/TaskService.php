<?php

namespace App\Services\Work;

use App\Enums\BreedingStatus;
use App\Enums\CycleStatus;
use App\Enums\DueState;
use App\Enums\EvidenceType;
use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Http\Requests\Work\CancelTaskRequest;
use App\Http\Requests\Work\CompleteTaskRequest;
use App\Http\Requests\Work\ListTasksRequest;
use App\Http\Requests\Work\StoreTaskRequest;
use App\Http\Requests\Work\UpdateTaskRequest;
use App\Models\BreedingCheck;
use App\Models\BreedingOutcome;
use App\Models\BreedingProject;
use App\Models\Farm;
use App\Models\HealthRecord;
use App\Models\OperationalRecord;
use App\Models\ProductionCycle;
use App\Models\Task;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use App\Support\Api\ApiRoute;
use App\Support\Idempotency\RequestHash;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Tasks are work that SHOULD happen. This service never creates an operational, health, breeding or other actual record:
 * completing a task either just closes it, or links one ALREADY saved record as evidence. Overdue / due-today / upcoming are
 * derived from due_at and the farm-local day, never stored. Lock order matches earlier phases: farm row, cycle, project, task.
 */
class TaskService
{
    public function __construct(private WorkSupport $support) {}

    public function find(FarmContext $ctx, string $id): Task
    {
        $ctx->authorize(Permission::TaskView);

        return $this->support->visible(Task::where('farm_id', $ctx->farm->id)->with('assignee:id,name'), $ctx)->findOrFail($id);
    }

    public function list(FarmContext $ctx, array $input): LengthAwarePaginator
    {
        $ctx->authorize(Permission::TaskView);
        $f = Validator::make($input, (new ListTasksRequest)->rules())->validate();
        $q = $this->support->visible(Task::where('farm_id', $ctx->farm->id)->with('assignee:id,name'), $ctx);
        $this->applyFilters($q, $ctx, $f);

        return $q->orderBy('due_at')->orderBy('id')->paginate($f['per_page'] ?? 50);
    }

    /** Shared by the task list and the calendar. */
    public function applyFilters(Builder $q, FarmContext $ctx, array $f): void
    {
        foreach (['status', 'production_cycle_id', 'breeding_project_id', 'schedule_id', 'category'] as $filter) {
            if (isset($f[$filter])) {
                $q->where($filter, $f[$filter]);
            }
        }
        if (isset($f['assigned_to'])) {
            $q->where('assigned_user_id', $f['assigned_to'] === 'me' ? $ctx->membership->user_id : $f['assigned_to']);
        }
        if (isset($f['from'])) {
            $q->where('due_date', '>=', $f['from']);
        }
        if (isset($f['to'])) {
            $q->where('due_date', '<=', $f['to']);
        }
        if (isset($f['due_state'])) {
            $now = CarbonImmutable::now()->utc()->format('Y-m-d H:i:s');
            $today = $this->support->today($ctx->farm);
            match (DueState::from($f['due_state'])) {
                DueState::Completed => $q->where('status', TaskStatus::Completed->value),
                DueState::Cancelled => $q->where('status', TaskStatus::Cancelled->value),
                DueState::Overdue => $q->where('status', TaskStatus::Open->value)->where('due_at', '<=', $now),
                DueState::DueToday => $q->where('status', TaskStatus::Open->value)->where('due_at', '>', $now)->where('due_date', '<=', $today),
                DueState::Upcoming => $q->where('status', TaskStatus::Open->value)->where('due_date', '>', $today),
            };
        }
    }

    public function create(FarmContext $ctx, array $input): Task
    {
        $ctx->authorize(Permission::TaskManage);
        $data = Validator::make($input, (new StoreTaskRequest)->rules())->validate();

        return DB::transaction(function () use ($ctx, $data) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $hash = RequestHash::of($data);
            $replay = Task::where('farm_id', $ctx->farm->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($replay) {
                $this->assertSameRequest($replay->request_hash, $hash);

                return $replay->load('assignee:id,name');
            }
            [$cycle, $project] = $this->support->context($ctx->farm, $data['production_cycle_id'] ?? null, $data['breeding_project_id'] ?? null);
            $this->support->assertWritable($cycle, $project);
            $this->support->assertLinked($data['linked_record_type'] ?? null, $cycle, $project);
            $this->support->assertAssignable($ctx->farm, $data['assigned_user_id'] ?? null);
            $task = $this->support->build($ctx->farm, [
                'production_cycle_id' => $cycle?->id, 'breeding_project_id' => $project?->id, 'title' => $data['title'], 'category' => $data['category'],
                'instructions' => $data['instructions'] ?? null, 'due_date' => $data['due_date'], 'due_time' => $data['due_time'] ?? null,
                'reminder_offsets' => $data['reminder_offsets'] ?? null, 'assigned_user_id' => $data['assigned_user_id'] ?? null, 'assigned_role' => $data['assigned_role'] ?? null,
                'linked_record_type' => $data['linked_record_type'] ?? null, 'requires_evidence' => $data['requires_evidence'] ?? false,
                'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash, 'created_by' => $ctx->membership->user_id,
            ]);

            return $task->load('assignee:id,name');
        }, 3);
    }

    /** Open tasks only; the context (cycle / project / schedule occurrence) never changes. */
    public function update(FarmContext $ctx, string $id, array $input): Task
    {
        $ctx->authorize(Permission::TaskManage);
        $data = Validator::make($input, (new UpdateTaskRequest)->rules())->validate();

        return DB::transaction(function () use ($ctx, $id, $data) {
            [$task, $cycle, $project] = $this->lock($ctx, $id);
            $this->assertOpen($task);
            $this->support->assertWritable($cycle, $project);
            $changes = array_intersect_key($data, array_flip(['title', 'category', 'instructions', 'due_date', 'due_time', 'reminder_offsets', 'assigned_user_id', 'assigned_role', 'linked_record_type', 'requires_evidence']));
            if (array_key_exists('linked_record_type', $changes)) {
                $this->support->assertLinked($changes['linked_record_type'], $cycle, $project);
            }
            if (isset($changes['assigned_user_id'])) {
                $this->support->assertAssignable($ctx->farm, $changes['assigned_user_id']);
            }
            if (array_key_exists('due_time', $changes)) {
                $changes['due_time'] = $changes['due_time'] !== null ? substr($changes['due_time'], 0, 5) : null;
            }
            if (array_key_exists('due_date', $changes) || array_key_exists('due_time', $changes)) {
                $date = $changes['due_date'] ?? $task->due_date->toDateString();
                $time = array_key_exists('due_time', $changes) ? $changes['due_time'] : ($task->due_time !== null ? substr($task->due_time, 0, 5) : null);
                $changes['due_at'] = $this->support->dueAt($ctx->farm, $date, $time);
            }
            $task->update($changes);

            return $task->load('assignee:id,name');
        }, 3);
    }

    public function cancel(FarmContext $ctx, string $id, array $input): Task
    {
        $ctx->authorize(Permission::TaskManage);
        $data = Validator::make($input, (new CancelTaskRequest)->rules())->validate();

        return DB::transaction(function () use ($ctx, $id, $data) {
            [$task] = $this->lock($ctx, $id);
            $this->assertOpen($task);
            $task->update(['status' => TaskStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => $data['reason']]);

            return $task->load('assignee:id,name');
        }, 3);
    }

    /**
     * Closes a task. Optionally links an actual record that was ALREADY saved through its own endpoint; no record is created or
     * changed here. Repeating a completion with the same evidence returns the original; a different one is 409.
     */
    public function complete(FarmContext $ctx, string $id, array $input): Task
    {
        $ctx->authorize(Permission::TaskComplete);
        $data = Validator::make($input, (new CompleteTaskRequest)->rules())->validate();
        $evidence = isset($data['evidence']) ? [$data['evidence']['type'], $data['evidence']['id']] : null;

        return DB::transaction(function () use ($ctx, $id, $data, $evidence) {
            [$task, $cycle, $project] = $this->lock($ctx, $id, visible: true);
            if ($task->status === TaskStatus::Completed) {
                if (($task->evidence_type !== null ? [$task->evidence_type, $task->evidence_id] : null) !== $evidence) {
                    throw new ApiHttpException(409, 'task_already_completed', 'This task was already completed with different evidence.');
                }

                return $task->load('assignee:id,name');
            }
            $this->assertOpen($task);
            if ($cycle !== null && $cycle->status !== CycleStatus::Active) {
                throw new ApiHttpException(409, 'cycle_closed', 'Reopen the cycle to complete its work, or cancel the task.');
            }
            if ($project !== null && $project->status === BreedingStatus::Cancelled) {
                throw new ApiHttpException(409, 'project_not_active', 'The breeding project was cancelled; cancel the task instead.');
            }
            if ($evidence === null && $task->requires_evidence) {
                $this->support->invalid('evidence', 'This task requires a saved record as evidence.');
            }
            if ($evidence !== null) {
                $this->assertEvidence($ctx, $task, EvidenceType::from($evidence[0]), $evidence[1]);
            }
            $completedAt = isset($data['completed_at']) ? CarbonImmutable::parse($data['completed_at'])->utc() : CarbonImmutable::now();
            if ($completedAt->isFuture()) {
                $this->support->invalid('completed_at', 'A completion time cannot be in the future.');
            }
            $task->update([
                'status' => TaskStatus::Completed, 'completed_at' => $completedAt, 'completed_by' => $ctx->membership->user_id, 'completion_note' => $data['note'] ?? null,
                'evidence_type' => $evidence[0] ?? null, 'evidence_id' => $evidence[1] ?? null,
            ]);

            return $task->load('assignee:id,name');
        }, 3);
    }

    /** What the frontend needs to open the right record form for this task. Read-only: nothing is saved. */
    public function recordPrefill(FarmContext $ctx, string $id): array
    {
        $task = $this->find($ctx, $id);
        if ($task->linked_record_type === null) {
            throw new ApiHttpException(409, 'task_has_no_linked_record', 'This task is not linked to a record type.');
        }
        $linked = $task->linked_record_type;
        $evidence = LinkedRecords::evidenceType($linked);
        $now = CarbonImmutable::now();
        $endpoint = LinkedRecords::endpoint($linked, $task->breeding_project_id);

        return [
            'task_id' => $task->id, 'evidence_type' => $evidence->value, 'method' => 'POST', 'endpoint' => $endpoint,
            'url' => ApiRoute::url($endpoint), 'path' => ApiRoute::path($endpoint),
            'prefill' => array_filter([
                'production_cycle_id' => $task->production_cycle_id, 'breeding_project_id' => $task->breeding_project_id,
                'type' => $evidence === EvidenceType::OperationalRecord ? $linked : null,
                'recorded_at' => $now->toISOString(), 'date' => $this->support->today($ctx->farm, $now),
            ], fn ($v) => $v !== null),
            'instructions' => 'Nothing has been saved. Submit the actual record to the endpoint, then complete this task with {evidence: {type, id}} using the saved record.',
        ];
    }

    /** @return array{0: Task, 1: ?ProductionCycle, 2: ?BreedingProject} */
    private function lock(FarmContext $ctx, string $id, bool $visible = false): array
    {
        Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
        $query = Task::where('farm_id', $ctx->farm->id);
        $row = ($visible ? $this->support->visible($query, $ctx) : $query)->findOrFail($id);
        [$cycle, $project] = $this->support->context($ctx->farm, $row->production_cycle_id, $row->breeding_project_id);

        return [Task::where('farm_id', $ctx->farm->id)->lockForUpdate()->findOrFail($id), $cycle, $project];
    }

    private function assertOpen(Task $task): void
    {
        if ($task->status !== TaskStatus::Open) {
            throw new ApiHttpException(409, 'task_not_open', 'Only an open task can be changed.');
        }
    }

    private function assertSameRequest(?string $stored, string $hash): void
    {
        if ($stored === null || ! hash_equals($stored, $hash)) {
            throw new ApiHttpException(409, 'idempotency_conflict', 'This idempotency key was already used for a different request.');
        }
    }

    /** The evidence must be a real, un-reversed record of this farm that fits the task's context and linked type, and may evidence only one task. */
    private function assertEvidence(FarmContext $ctx, Task $task, EvidenceType $type, string $id): void
    {
        $farm = $ctx->farm->id;
        $missing = fn () => $this->support->invalid('evidence.id', 'No such record on this farm.');
        $permission = match ($type) {
            EvidenceType::OperationalRecord => Permission::RecordView, EvidenceType::HealthRecord => Permission::HealthView,
            EvidenceType::BreedingCheck, EvidenceType::BreedingOutcome => Permission::BreedingView,
        };
        $ctx->authorize($permission);
        if ($task->linked_record_type !== null && LinkedRecords::evidenceType($task->linked_record_type) !== $type) {
            $this->support->invalid('evidence.type', 'This task expects a '.LinkedRecords::evidenceType($task->linked_record_type)->value.' as evidence.');
        }
        $cycleId = null;
        $projectId = null;
        switch ($type) {
            case EvidenceType::OperationalRecord:
                $record = OperationalRecord::where('farm_id', $farm)->find($id) ?? $missing();
                if ($record->reverses_record_id !== null || $record->reversal()->exists()) {
                    $this->support->invalid('evidence.id', 'A reversed record cannot be evidence.');
                }
                if ($task->linked_record_type !== null && $record->type !== $task->linked_record_type) {
                    $this->support->invalid('evidence.id', 'The record type does not match the task ('.$task->linked_record_type.').');
                }
                $cycleId = $record->production_cycle_id;
                break;
            case EvidenceType::HealthRecord:
                $record = HealthRecord::where('farm_id', $farm)->find($id) ?? $missing();
                if ($record->reverses_record_id !== null || $record->isReversed()) {
                    $this->support->invalid('evidence.id', 'A reversed record cannot be evidence.');
                }
                $cycleId = $record->production_cycle_id;
                break;
            case EvidenceType::BreedingCheck:
                $record = BreedingCheck::where('farm_id', $farm)->find($id) ?? $missing();
                $projectId = $record->breeding_project_id;
                break;
            case EvidenceType::BreedingOutcome:
                $record = BreedingOutcome::where('farm_id', $farm)->find($id) ?? $missing();
                if ($record->kind !== BreedingOutcome::OUTCOME || $record->reversal()->exists()) {
                    $this->support->invalid('evidence.id', 'Only an effective (un-reversed) outcome can be evidence.');
                }
                $projectId = $record->breeding_project_id;
                $cycleId = $record->production_cycle_id;
                break;
        }
        if ($task->production_cycle_id !== null && $cycleId !== null && $cycleId !== $task->production_cycle_id) {
            $this->support->invalid('evidence.id', 'The record belongs to a different production cycle than the task.');
        }
        if ($task->breeding_project_id !== null && $projectId !== null && $projectId !== $task->breeding_project_id) {
            $this->support->invalid('evidence.id', 'The record belongs to a different breeding project than the task.');
        }
        if (Task::where('farm_id', $farm)->where('evidence_type', $type->value)->where('evidence_id', $id)->where('id', '!=', $task->id)->exists()) {
            throw new ApiHttpException(409, 'evidence_already_linked', 'This record already evidences another task.');
        }
    }
}
