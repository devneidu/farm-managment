<?php

namespace App\Services\Work;

use App\Enums\BreedingStatus;
use App\Enums\CycleKind;
use App\Enums\CycleStatus;
use App\Enums\EvidenceType;
use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Models\BreedingProject;
use App\Models\Farm;
use App\Models\FarmMembership;
use App\Models\ProductionCycle;
use App\Models\Task;
use App\Services\Records\RecordTypeRegistry;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Rules shared by tasks, schedules and template application: farm-local day/time handling, context (cycle / breeding project)
 * resolution with the project -> cycle lock order of Phases 7-11, assignment and linked-record checks, task row construction
 * and per-role visibility. Nothing in the work module writes operational data.
 */
class WorkSupport
{
    public function __construct(private RecordTypeRegistry $types) {}

    /** The farm's current calendar date (Africa/Lagos by default). */
    public function today(Farm $farm, ?CarbonImmutable $now = null): string
    {
        return ($now ?? CarbonImmutable::now())->setTimezone($farm->timezone)->toDateString();
    }

    /** UTC instant after which an open task is overdue: the farm-local due time, or the end of the farm-local due day. */
    public function dueAt(Farm $farm, string $date, ?string $time): CarbonImmutable
    {
        $local = CarbonImmutable::parse($date, $farm->timezone);

        return ($time !== null ? CarbonImmutable::parse($date.' '.substr($time, 0, 5), $farm->timezone) : $local->addDay()->startOfDay())->utc();
    }

    /** @return array{0: ?ProductionCycle, 1: ?BreedingProject} locked cycle, then project (project implies its cycle). */
    public function context(Farm $farm, ?string $cycleId, ?string $projectId): array
    {
        $project = null;
        if ($projectId !== null) {
            $project = BreedingProject::where('farm_id', $farm->id)->findOrFail($projectId);
            if ($cycleId !== null && $cycleId !== $project->production_cycle_id) {
                $this->invalid('production_cycle_id', 'The breeding project belongs to a different cycle.');
            }
            $cycleId = $project->production_cycle_id;
        }
        $cycle = $cycleId !== null ? ProductionCycle::ofFarm($farm)->with(['livestock', 'crop'])->lockForUpdate()->findOrFail($cycleId) : null;
        if ($project !== null) {
            $project = BreedingProject::where('farm_id', $farm->id)->lockForUpdate()->findOrFail($project->id);
        }

        return [$cycle, $project];
    }

    /** New work needs an open cycle and an active breeding project (409 otherwise). */
    public function assertWritable(?ProductionCycle $cycle, ?BreedingProject $project): void
    {
        if ($cycle !== null && $cycle->status !== CycleStatus::Active) {
            throw new ApiHttpException(409, 'cycle_closed', 'Reopen the cycle before creating or changing its work.');
        }
        if ($project !== null && $project->status !== BreedingStatus::Active) {
            throw new ApiHttpException(409, 'project_not_active', 'Only an active breeding project can receive new work.');
        }
    }

    public function assertAssignable(Farm $farm, ?string $userId): void
    {
        if ($userId === null) {
            return;
        }
        $member = FarmMembership::active()->where('farm_id', $farm->id)->where('user_id', $userId)->first();
        if (! $member || ! $member->can(Permission::TaskView)) {
            $this->invalid('assigned_user_id', 'The assignee must be an active member of this farm.');
        }
    }

    public function assertLinked(?string $linked, ?ProductionCycle $cycle, ?BreedingProject $project): void
    {
        if ($linked === null) {
            return;
        }
        $evidence = LinkedRecords::evidenceType($linked);
        if (in_array($evidence, [EvidenceType::BreedingCheck, EvidenceType::BreedingOutcome], true)) {
            if ($project === null) {
                $this->invalid('linked_record_type', 'A breeding record link needs a breeding project.');
            }

            return;
        }
        if ($cycle === null) {
            $this->invalid('linked_record_type', 'A record link needs a production cycle.');
        }
        $kind = $evidence === EvidenceType::OperationalRecord ? ($this->types->definitions()[$linked]['kind'] ?? null) : CycleKind::Livestock->value;
        if ($kind !== null && $kind !== $cycle->kind->value) {
            $this->invalid('linked_record_type', 'This record type does not apply to this kind of cycle.');
        }
    }

    /** Creates the row; call with the farm row locked so references stay unique. */
    public function build(Farm $farm, array $attributes): Task
    {
        $number = Task::where('farm_id', $farm->id)->count() + 1;
        $dueTime = isset($attributes['due_time']) ? substr($attributes['due_time'], 0, 5) : null;
        $attributes['due_time'] = $dueTime;

        return Task::create($attributes + [
            'farm_id' => $farm->id, 'reference' => 'TSK-'.now()->format('Y').'-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
            'due_at' => $this->dueAt($farm, $attributes['due_date'], $dueTime), 'status' => TaskStatus::Open,
        ]);
    }

    /** Roles with task.manage see every task; others see tasks assigned to them or their role, ones they created and their role's relevant categories. */
    public function visible(Builder $query, FarmContext $ctx): Builder
    {
        if ($ctx->can(Permission::TaskManage)) {
            return $query;
        }
        $user = $ctx->membership->user_id;
        $categories = array_map(fn ($c) => $c->value, $ctx->membership->role->relevantTaskCategories());

        return $query->where(function ($w) use ($user, $ctx, $categories) {
            $w->where('assigned_user_id', $user)->orWhere('assigned_role', $ctx->membership->role->value)->orWhere('created_by', $user);
            if ($categories) {
                $w->orWhereIn('category', $categories);
            }
        });
    }

    public function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
