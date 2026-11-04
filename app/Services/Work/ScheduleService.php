<?php

namespace App\Services\Work;

use App\Enums\BreedingStatus;
use App\Enums\CycleStatus;
use App\Enums\Permission;
use App\Enums\Recurrence;
use App\Enums\TaskStatus;
use App\Http\Requests\Work\EditSeriesRequest;
use App\Http\Requests\Work\EndScheduleRequest;
use App\Http\Requests\Work\ListSchedulesRequest;
use App\Http\Requests\Work\StoreScheduleRequest;
use App\Http\Requests\Work\StoreTaskRequest;
use App\Models\BreedingProject;
use App\Models\Farm;
use App\Models\ProductionCycle;
use App\Models\Schedule;
use App\Models\Task;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use App\Support\Idempotency\RequestHash;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * A schedule is a recurrence rule that materialises TASKS only, a rolling HORIZON_DAYS ahead, never past dates and never an
 * operational record. Generation is idempotent: (schedule, occurrence date) is unique, so re-running the command or the
 * creating request can never duplicate a task.
 */
class ScheduleService
{
    public const HORIZON_DAYS = 30;

    public function __construct(private WorkSupport $support) {}

    public function find(FarmContext $ctx, string $id): Schedule
    {
        $ctx->authorize(Permission::TaskManage);

        return $this->counts(Schedule::where('farm_id', $ctx->farm->id))->findOrFail($id);
    }

    public function list(FarmContext $ctx, array $input): LengthAwarePaginator
    {
        $ctx->authorize(Permission::TaskManage);
        $f = Validator::make($input, (new ListSchedulesRequest)->rules())->validate();
        $q = $this->counts(Schedule::where('farm_id', $ctx->farm->id));
        foreach (['status', 'production_cycle_id', 'breeding_project_id'] as $filter) {
            if (isset($f[$filter])) {
                $q->where($filter, $f[$filter]);
            }
        }

        return $q->orderByDesc('created_at')->orderByDesc('id')->paginate($f['per_page'] ?? 50);
    }

    public function create(FarmContext $ctx, array $input): Schedule
    {
        $ctx->authorize(Permission::TaskManage);
        $data = Validator::make($input, (new StoreScheduleRequest)->rules())->validate();
        $this->assertRule($data);

        return DB::transaction(function () use ($ctx, $data) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $hash = RequestHash::of($data);
            $replay = Schedule::where('farm_id', $ctx->farm->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($replay) {
                if (! hash_equals((string) $replay->request_hash, $hash)) {
                    throw new ApiHttpException(409, 'idempotency_conflict', 'This idempotency key was already used for a different request.');
                }

                return $this->find($ctx, $replay->id);
            }
            [$cycle, $project] = $this->support->context($ctx->farm, $data['production_cycle_id'] ?? null, $data['breeding_project_id'] ?? null);
            $this->support->assertWritable($cycle, $project);

            return $this->persist($ctx, $data, $hash, $cycle, $project);
        }, 3);
    }

    /** Creates the schedule row and its first tasks. Call inside a transaction with the farm (and context) locked and the context checked writable. */
    private function persist(FarmContext $ctx, array $data, string $hash, ?ProductionCycle $cycle, ?BreedingProject $project): Schedule
    {
        if (($data['ends_type'] ?? null) === 'batch_end' && $cycle === null) {
            $this->support->invalid('ends_type', 'Until the batch ends needs a production cycle or breeding project.');
        }
        $this->support->assertLinked($data['linked_record_type'] ?? null, $cycle, $project);
        $this->support->assertAssignable($ctx->farm, $data['assigned_user_id'] ?? null);
        $schedule = Schedule::create($this->attributes($data) + [
            'farm_id' => $ctx->farm->id, 'production_cycle_id' => $cycle?->id, 'breeding_project_id' => $project?->id,
            'assigned_user_id' => $data['assigned_user_id'] ?? null, 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
            'created_by' => $ctx->membership->user_id, 'status' => 'active',
        ]);
        $this->materialize($ctx->farm, $schedule, $cycle, $project);

        return $this->find($ctx, $schedule->id);
    }

    /** The tasks a schedule has generated so far, in due order. */
    public function tasksOf(string $scheduleId): Collection
    {
        return Task::where('schedule_id', $scheduleId)->with('assignee:id,name')->orderBy('due_at')->orderBy('id')->get();
    }

    /** POST /tasks with a `repeat` block: the single "Add task" form creates a schedule (same validation and generation as POST /schedules). */
    public function createFromTask(FarmContext $ctx, array $input): Schedule
    {
        $ctx->authorize(Permission::TaskManage);
        $data = Validator::make($input, (new StoreTaskRequest)->rules())->validate();

        return $this->create($ctx, $this->scheduleInput($data));
    }

    /**
     * "Edit this and all future": ends the old schedule the day before this task's occurrence date, cancels its open tasks from that date on
     * (completed ones are never touched; open tasks already overdue are left for the user to finish) and starts a new schedule from this date
     * with the changes. Fields (and the repeat rule) that are not sent carry over from this task and the old schedule. One transaction;
     * the idempotency_key makes a retry return the same new schedule.
     */
    public function editSeries(FarmContext $ctx, string $taskId, array $input): Schedule
    {
        $ctx->authorize(Permission::TaskManage);
        $data = Validator::make($input, (new EditSeriesRequest)->rules())->validate();

        return DB::transaction(function () use ($ctx, $taskId, $data) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $hash = RequestHash::of(['edit_series_of' => $taskId] + $data);
            $replay = Schedule::where('farm_id', $ctx->farm->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($replay) {
                if (! hash_equals((string) $replay->request_hash, $hash)) {
                    throw new ApiHttpException(409, 'idempotency_conflict', 'This idempotency key was already used for a different request.');
                }

                return $this->find($ctx, $replay->id);
            }
            $task = Task::where('farm_id', $ctx->farm->id)->findOrFail($taskId);
            if ($task->schedule_id === null) {
                throw new ApiHttpException(409, 'task_not_in_series', 'This task does not repeat; edit it directly.');
            }
            [$cycle, $project] = $this->support->context($ctx->farm, $task->production_cycle_id, $task->breeding_project_id);
            $task = Task::where('farm_id', $ctx->farm->id)->lockForUpdate()->findOrFail($taskId);
            $old = Schedule::where('farm_id', $ctx->farm->id)->lockForUpdate()->findOrFail($task->schedule_id);
            if ($task->status !== TaskStatus::Open) {
                throw new ApiHttpException(409, 'task_not_open', 'Only an open task can be changed.');
            }
            $this->support->assertWritable($cycle, $project);

            $from = $task->occurrence_date->toDateString();
            $start = $data['due_date'] ?? $from;
            if ($start < $from) {
                $this->support->invalid('due_date', 'The series cannot start earlier than this task.');
            }
            $new = $this->seriesInput($task, $old, $data, $from, $start) + ['production_cycle_id' => $cycle?->id, 'breeding_project_id' => $project?->id, 'idempotency_key' => $data['idempotency_key']];
            $this->assertRule($new);

            $dayBefore = CarbonImmutable::parse($from, 'UTC')->subDay()->toDateString();
            $old->update(['status' => 'ended'] + ($dayBefore >= $old->starts_on->toDateString() ? ['ends_on' => $dayBefore] : []));
            Task::where('schedule_id', $old->id)->where('status', TaskStatus::Open->value)->where('occurrence_date', '>=', $from)
                ->where('due_date', '>=', $this->support->today($ctx->farm))
                ->update(['status' => TaskStatus::Cancelled->value, 'cancelled_at' => now(), 'cancel_reason' => 'Series edited']);

            return $this->persist($ctx, $new, $hash, $cycle, $project);
        }, 3);
    }

    /** Ends the batch-end schedules of a cycle that has just been closed and cancels their open future tasks. Call inside the close transaction. */
    public function endForClosedCycle(ProductionCycle $cycle): int
    {
        $farm = Farm::findOrFail($cycle->farm_id);
        $today = $this->support->today($farm);
        $ended = 0;
        foreach (Schedule::where('farm_id', $farm->id)->where('production_cycle_id', $cycle->id)->where('ends_type', 'batch_end')->where('status', 'active')->lockForUpdate()->get() as $schedule) {
            $schedule->update(['status' => 'ended']);
            Task::where('schedule_id', $schedule->id)->where('status', TaskStatus::Open->value)->where('due_date', '>', $today)
                ->update(['status' => TaskStatus::Cancelled->value, 'cancelled_at' => now(), 'cancel_reason' => 'Batch closed']);
            $ended++;
        }

        return $ended;
    }

    /** Maps a validated task payload carrying `repeat` onto schedule input. @return array<string, mixed> */
    private function scheduleInput(array $data): array
    {
        $repeat = $data['repeat'];
        $ends = $repeat['ends'] ?? ['type' => 'never'];
        $start = $data['due_date'];
        if (! empty($repeat['weekdays']) && $repeat['pattern'] !== 'weekly') {
            $this->support->invalid('repeat.weekdays', 'Weekdays apply to weekly repeats only.');
        }
        $out = array_diff_key($data, ['repeat' => 1, 'due_date' => 1]) + [
            'recurrence' => $repeat['pattern'], 'interval_value' => $repeat['every'] ?? 1, 'starts_on' => $start,
        ];
        if ($repeat['pattern'] === 'weekly' && ! empty($repeat['weekdays'])) {
            $out['weekdays'] = $repeat['weekdays'];
        }
        switch ($ends['type']) {
            case 'days':
                $out['ends_on'] = CarbonImmutable::parse($start, 'UTC')->addDays($ends['days'] - 1)->toDateString();
                break;
            case 'date':
                $out['ends_on'] = $ends['date'];
                break;
            case 'batch_end':
                if (empty($data['production_cycle_id']) && empty($data['breeding_project_id'])) {
                    $this->support->invalid('repeat.ends.type', 'Until the batch ends needs a batch (production_cycle_id or breeding_project_id).');
                }
                $out['ends_type'] = 'batch_end';
                break;
        }

        return $out;
    }

    /** Schedule input for the new series of an edit: this task's current values, overridden by what was sent; the rule from `repeat` or the old schedule. @return array<string, mixed> */
    private function seriesInput(Task $task, Schedule $old, array $data, string $from, string $start): array
    {
        $out = [
            'title' => $task->title, 'category' => $task->category->value, 'instructions' => $task->instructions, 'due_time' => $task->due_time,
            'reminders' => $task->reminder_presets, 'reminder_offsets' => $task->reminder_offsets, 'assigned_user_id' => $task->assigned_user_id,
            'assigned_role' => $task->assigned_role, 'linked_record_type' => $task->linked_record_type, 'requires_evidence' => $task->requires_evidence,
        ];
        foreach (array_keys($out) as $field) {
            if (array_key_exists($field, $data)) {
                $out[$field] = $data[$field];
            }
        }
        if (isset($data['repeat'])) {
            $rule = $this->scheduleInput($data + ['due_date' => $start, 'production_cycle_id' => $task->production_cycle_id, 'breeding_project_id' => $task->breeding_project_id]);

            return $out + array_intersect_key($rule, array_flip(['recurrence', 'interval_value', 'weekdays', 'starts_on', 'ends_on', 'ends_type']));
        }
        $out += ['recurrence' => $old->recurrence->value, 'interval_value' => $old->interval_value, 'weekdays' => $old->weekdays, 'starts_on' => $start, 'ends_on' => $old->ends_on?->toDateString(), 'ends_type' => $old->ends_type];
        if ($old->occurrence_limit !== null) {
            $before = count(Occurrences::between($old->recurrence, $old->interval_value, $old->weekdays, $old->starts_on->toDateString(), $old->ends_on?->toDateString(), $old->occurrence_limit, $old->starts_on->toDateString(), CarbonImmutable::parse($from, 'UTC')->subDay()->toDateString()));
            $out['occurrence_limit'] = max(1, $old->occurrence_limit - $before);
        }
        if ($out['ends_on'] !== null && $out['ends_on'] < $start) {
            $this->support->invalid('due_date', 'The series would start after its end date.');
        }

        return $out;
    }

    /** Stops generation. Existing tasks stay (history); optionally the open future ones are cancelled. */
    public function end(FarmContext $ctx, string $id, array $input): Schedule
    {
        $ctx->authorize(Permission::TaskManage);
        $data = Validator::make($input, (new EndScheduleRequest)->rules())->validate();

        return DB::transaction(function () use ($ctx, $id, $data) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $schedule = Schedule::where('farm_id', $ctx->farm->id)->lockForUpdate()->findOrFail($id);
            if ($schedule->status !== 'ended') {
                $schedule->update(['status' => 'ended']);
            }
            if (! empty($data['cancel_future_tasks'])) {
                Task::where('schedule_id', $schedule->id)->where('status', TaskStatus::Open->value)->where('due_date', '>', $this->support->today($ctx->farm))
                    ->update(['status' => TaskStatus::Cancelled->value, 'cancelled_at' => now(), 'cancel_reason' => 'Schedule ended']);
            }

            return $this->find($ctx, $schedule->id);
        }, 3);
    }

    /**
     * Creates the missing tasks for [today, min(ends_on, today + horizon)], skipping dates already in the past and dates that already
     * have a task. Nothing is generated while the cycle is closed or the breeding project is not active. Call with the farm row locked.
     *
     * @return array{created: int, skipped_past: int}
     */
    public function materialize(Farm $farm, Schedule $schedule, ?ProductionCycle $cycle, ?BreedingProject $project): array
    {
        $result = ['created' => 0, 'skipped_past' => 0];
        if ($schedule->status !== 'active') {
            return $result;
        }
        if (($cycle && $cycle->status !== CycleStatus::Active) || ($project && $project->status !== BreedingStatus::Active)) {
            return $result;
        }
        $today = $this->support->today($farm);
        $horizon = CarbonImmutable::parse($today, 'UTC')->addDays(self::HORIZON_DAYS)->toDateString();
        $ends = $schedule->ends_on?->toDateString();
        $bound = $ends !== null && $ends < $horizon ? $ends : $horizon;
        $dates = Occurrences::between($schedule->recurrence, $schedule->interval_value, $schedule->weekdays, $schedule->starts_on->toDateString(), $ends, $schedule->occurrence_limit, $schedule->starts_on->toDateString(), $bound);
        $existing = Task::where('schedule_id', $schedule->id)->pluck('occurrence_date')->map(fn ($d) => $d->toDateString())->all();
        foreach ($dates as $date) {
            if ($date < $today) {
                $result['skipped_past']++;
            } elseif (! in_array($date, $existing, true)) {
                $this->support->build($farm, [
                    'schedule_id' => $schedule->id, 'occurrence_date' => $date, 'production_cycle_id' => $schedule->production_cycle_id, 'breeding_project_id' => $schedule->breeding_project_id,
                    'title' => $schedule->title, 'category' => $schedule->category->value, 'instructions' => $schedule->instructions, 'due_date' => $date, 'due_time' => $schedule->due_time,
                    'reminder_offsets' => $schedule->reminder_offsets, 'reminder_presets' => $schedule->reminder_presets, 'assigned_user_id' => $schedule->assigned_user_id, 'assigned_role' => $schedule->assigned_role,
                    'linked_record_type' => $schedule->linked_record_type, 'requires_evidence' => $schedule->requires_evidence, 'created_by' => $schedule->created_by,
                ]);
                $result['created']++;
            }
        }
        if ($this->exhausted($schedule, $bound)) {
            $schedule->update(['status' => 'ended']);
        }

        return $result;
    }

    /** Rolling generation for every active schedule (run daily by `work:generate-tasks`); returns the number of tasks created. */
    public function generateDue(): int
    {
        $created = 0;
        Schedule::where('status', 'active')->orderBy('id')->chunkById(100, function ($chunk) use (&$created) {
            foreach ($chunk as $row) {
                $created += DB::transaction(function () use ($row) {
                    $farm = Farm::whereKey($row->farm_id)->lockForUpdate()->firstOrFail();
                    $schedule = Schedule::lockForUpdate()->find($row->id);
                    if (! $schedule || $schedule->status !== 'active') {
                        return 0;
                    }
                    $cycle = $schedule->production_cycle_id ? ProductionCycle::find($schedule->production_cycle_id) : null;
                    $project = $schedule->breeding_project_id ? BreedingProject::find($schedule->breeding_project_id) : null;

                    return $this->materialize($farm, $schedule, $cycle, $project)['created'];
                }, 3);
            }
        });

        return $created;
    }

    /** True when no occurrence can exist after $bound (one-off, past its end date, or its count is used up). */
    private function exhausted(Schedule $schedule, string $bound): bool
    {
        if ($schedule->recurrence !== Recurrence::None && $schedule->ends_on === null && $schedule->occurrence_limit === null) {
            return false;
        }
        $start = $schedule->starts_on->toDateString();
        $all = Occurrences::between($schedule->recurrence, $schedule->interval_value, $schedule->weekdays, $start, $schedule->ends_on?->toDateString(), $schedule->occurrence_limit, $start, CarbonImmutable::parse($start, 'UTC')->addDays(3700)->toDateString());

        return $all === [] || end($all) <= $bound;
    }

    /** @return array<string, mixed> the rule + work columns of a schedule */
    public function attributes(array $data): array
    {
        $weekly = ($data['recurrence'] ?? null) === Recurrence::Weekly->value;
        $dueTime = isset($data['due_time']) ? substr($data['due_time'], 0, 5) : null;

        return [
            'title' => $data['title'], 'category' => $data['category'], 'instructions' => $data['instructions'] ?? null, 'recurrence' => $data['recurrence'],
            'interval_value' => $data['interval_value'] ?? 1, 'weekdays' => $weekly ? array_values($data['weekdays'] ?? []) ?: null : null,
            'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'] ?? null, 'occurrence_limit' => $data['occurrence_limit'] ?? null, 'due_time' => $dueTime,
            'reminder_offsets' => $data['reminder_offsets'] ?? null, 'reminder_presets' => $data['reminders'] ?? null, 'ends_type' => $data['ends_type'] ?? null, 'assigned_role' => $data['assigned_role'] ?? null,
            'linked_record_type' => $data['linked_record_type'] ?? null, 'requires_evidence' => $data['requires_evidence'] ?? false,
        ];
    }

    public function assertRule(array $data): void
    {
        $recurrence = $data['recurrence'] ?? 'none';
        if ($recurrence === 'none' && (isset($data['ends_on']) || isset($data['occurrence_limit']) || ! empty($data['weekdays']))) {
            $this->support->invalid('recurrence', 'A one-off schedule has no end date, occurrence limit or weekdays.');
        }
        if (($data['ends_type'] ?? null) === 'batch_end' && ($recurrence === 'none' || isset($data['ends_on']) || isset($data['occurrence_limit']))) {
            $this->support->invalid('ends_type', 'Until the batch ends needs a repeating schedule with no end date or occurrence limit.');
        }
        if ($recurrence !== 'weekly' && ! empty($data['weekdays'])) {
            $this->support->invalid('weekdays', 'Weekdays apply to weekly schedules only.');
        }
    }

    private function counts($query)
    {
        return $query->withCount(['tasks', 'tasks as open_tasks_count' => fn ($q) => $q->where('status', TaskStatus::Open->value)]);
    }
}
