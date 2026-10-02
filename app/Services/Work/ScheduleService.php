<?php

namespace App\Services\Work;

use App\Enums\BreedingStatus;
use App\Enums\CycleStatus;
use App\Enums\Permission;
use App\Enums\Recurrence;
use App\Enums\TaskStatus;
use App\Http\Requests\Work\EndScheduleRequest;
use App\Http\Requests\Work\ListSchedulesRequest;
use App\Http\Requests\Work\StoreScheduleRequest;
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
            $this->support->assertLinked($data['linked_record_type'] ?? null, $cycle, $project);
            $this->support->assertAssignable($ctx->farm, $data['assigned_user_id'] ?? null);
            $schedule = Schedule::create($this->attributes($data) + [
                'farm_id' => $ctx->farm->id, 'production_cycle_id' => $cycle?->id, 'breeding_project_id' => $project?->id,
                'assigned_user_id' => $data['assigned_user_id'] ?? null, 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
                'created_by' => $ctx->membership->user_id, 'status' => 'active',
            ]);
            $this->materialize($ctx->farm, $schedule, $cycle, $project);

            return $this->find($ctx, $schedule->id);
        }, 3);
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
                    'reminder_offsets' => $schedule->reminder_offsets, 'assigned_user_id' => $schedule->assigned_user_id, 'assigned_role' => $schedule->assigned_role,
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
            'reminder_offsets' => $data['reminder_offsets'] ?? null, 'assigned_role' => $data['assigned_role'] ?? null,
            'linked_record_type' => $data['linked_record_type'] ?? null, 'requires_evidence' => $data['requires_evidence'] ?? false,
        ];
    }

    public function assertRule(array $data): void
    {
        $recurrence = $data['recurrence'] ?? 'none';
        if ($recurrence === 'none' && (isset($data['ends_on']) || isset($data['occurrence_limit']) || ! empty($data['weekdays']))) {
            $this->support->invalid('recurrence', 'A one-off schedule has no end date, occurrence limit or weekdays.');
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
