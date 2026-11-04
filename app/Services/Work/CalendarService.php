<?php

namespace App\Services\Work;

use App\Enums\BreedingStatus;
use App\Enums\CycleStatus;
use App\Enums\Permission;
use App\Http\Requests\Work\CalendarRequest;
use App\Models\BreedingProject;
use App\Models\HealthRecord;
use App\Models\ProductionCycle;
use App\Models\Schedule;
use App\Models\Task;
use App\Support\Access\FarmContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/**
 * The calendar is a READ MODEL: scheduled tasks plus milestones read live from the records that own them (cycle dates, breeding
 * expectation, health follow-ups). It stores and duplicates nothing, and a milestone is never a task or an operational record.
 */
class CalendarService
{
    public const MAX_DAYS = 92;

    public function __construct(private WorkSupport $support, private TaskService $tasks) {}

    public function range(FarmContext $ctx, array $input): array
    {
        $ctx->authorize(Permission::TaskView);
        $f = Validator::make($input, (new CalendarRequest)->rules())->validate();
        if (CarbonImmutable::parse($f['from'], 'UTC')->diffInDays(CarbonImmutable::parse($f['to'], 'UTC')) >= self::MAX_DAYS) {
            $this->support->invalid('to', 'The calendar range is limited to '.self::MAX_DAYS.' days.');
        }

        return $this->collect($ctx, $f);
    }

    /** The calendar for an already validated range of any length (the 92-day cap is applied by range(); the phone feed uses a longer window). */
    public function collect(FarmContext $ctx, array $f): array
    {
        $now = CarbonImmutable::now();
        $today = $this->support->today($ctx->farm, $now);

        $q = $this->support->visible(Task::where('farm_id', $ctx->farm->id)->with('assignee:id,name'), $ctx);
        $this->tasks->applyFilters($q, $ctx, array_intersect_key($f, array_flip(['from', 'to', 'production_cycle_id', 'breeding_project_id', 'category', 'assigned_to'])));
        $items = [];
        foreach ($q->orderBy('due_at')->orderBy('id')->get() as $task) {
            $items[] = ['kind' => 'task', 'date' => $task->due_date->toDateString(), 'end_date' => null, 'time' => $task->due_time !== null ? substr($task->due_time, 0, 5) : null, 'task' => $task, 'now' => $now, 'today' => $today];
        }
        if (($f['include_milestones'] ?? true) && ! isset($f['category']) && ! isset($f['assigned_to'])) {
            array_push($items, ...$this->milestones($ctx, $f));
        }
        if ($f['include_upcoming_repeats'] ?? true) {
            array_push($items, ...$this->upcomingRepeats($ctx, $f, $today));
        }
        usort($items, fn ($a, $b) => [$a['date'], $a['time'] ?? '', $a['kind']] <=> [$b['date'], $b['time'] ?? '', $b['kind']]);

        return ['items' => $items, 'meta' => ['from' => $f['from'], 'to' => $f['to'], 'timezone' => $ctx->farm->timezone, 'today' => $today]];
    }

    /**
     * Future occurrences of active schedules that have no task yet (beyond the generated horizon), computed from the rule and never stored. They respect
     * the same visibility and filters as tasks, skip dates a task already covers (including cancelled ones) and schedules whose batch is not open.
     *
     * @return list<array<string, mixed>>
     */
    private function upcomingRepeats(FarmContext $ctx, array $f, string $today): array
    {
        $from = max($f['from'], $today);
        if ($from > $f['to']) {
            return [];
        }
        $farm = $ctx->farm->id;
        $q = $this->support->visible(Schedule::where('farm_id', $farm)->where('status', 'active'), $ctx)
            ->where(fn ($w) => $w->whereNull('production_cycle_id')->orWhereIn('production_cycle_id', ProductionCycle::where('farm_id', $farm)->where('status', CycleStatus::Active->value)->select('id')))
            ->where(fn ($w) => $w->whereNull('breeding_project_id')->orWhereIn('breeding_project_id', BreedingProject::where('farm_id', $farm)->where('status', BreedingStatus::Active->value)->select('id')));
        foreach (['production_cycle_id', 'breeding_project_id', 'category'] as $filter) {
            if (isset($f[$filter])) {
                $q->where($filter, $f[$filter]);
            }
        }
        if (isset($f['assigned_to'])) {
            $q->where('assigned_user_id', $f['assigned_to'] === 'me' ? $ctx->membership->user_id : $f['assigned_to']);
        }
        $out = [];
        foreach ($q->orderBy('starts_on')->orderBy('id')->get() as $schedule) {
            $start = $schedule->starts_on->toDateString();
            $dates = Occurrences::between($schedule->recurrence, $schedule->interval_value, $schedule->weekdays, $start, $schedule->ends_on?->toDateString(), $schedule->occurrence_limit, max($from, $start), $f['to']);
            if ($dates === []) {
                continue;
            }
            $covered = Task::where('schedule_id', $schedule->id)->whereIn('occurrence_date', $dates)->pluck('occurrence_date')->map(fn ($d) => $d->toDateString())->all();
            foreach (array_diff($dates, $covered) as $date) {
                $out[] = [
                    'kind' => 'upcoming_repeat', 'date' => $date, 'end_date' => null, 'time' => $schedule->due_time !== null ? substr($schedule->due_time, 0, 5) : null,
                    'title' => $schedule->title, 'category' => $schedule->category->value, 'schedule_id' => $schedule->id,
                    'production_cycle_id' => $schedule->production_cycle_id, 'breeding_project_id' => $schedule->breeding_project_id,
                ];
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function milestones(FarmContext $ctx, array $f): array
    {
        $out = [];
        $from = $f['from'];
        $to = $f['to'];
        $add = function (string $code, string $title, string $date, ?string $end, string $type, string $id, ?string $reference) use (&$out, $from, $to) {
            $last = $end ?? $date;
            if ($last >= $from && $date <= $to) {
                $out[] = ['kind' => 'milestone', 'code' => $code, 'title' => $title, 'date' => $date, 'end_date' => $end, 'time' => null, 'source' => ['type' => $type, 'id' => $id, 'reference' => $reference]];
            }
        };
        $farm = $ctx->farm->id;
        if ($ctx->can(Permission::ProductionCycleView)) {
            $cycles = ProductionCycle::where('farm_id', $farm)->where('status', CycleStatus::Active->value)
                ->when($f['production_cycle_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->when($f['breeding_project_id'] ?? null, fn ($q) => $q->whereRaw('1 = 0'))->get();
            foreach ($cycles as $c) {
                $add('cycle_start', $c->name.' started', $c->start_date->toDateString(), null, 'production_cycle', $c->id, $c->reference);
                if ($c->expected_end_date) {
                    $add('cycle_expected_end', $c->name.' expected end', $c->expected_end_date->toDateString(), null, 'production_cycle', $c->id, $c->reference);
                }
            }
        }
        if ($ctx->can(Permission::BreedingView)) {
            $projects = BreedingProject::where('farm_id', $farm)->where('status', BreedingStatus::Active->value)
                ->when($f['production_cycle_id'] ?? null, fn ($q, $id) => $q->where('production_cycle_id', $id))->when($f['breeding_project_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->get();
            foreach ($projects as $p) {
                // Stored expectation only (exact date or window); the biological reference is never recalculated here.
                if ($p->expected_date) {
                    $add('breeding_expected', $p->reference.' expected', $p->expected_date->toDateString(), null, 'breeding_project', $p->id, $p->reference);
                } elseif ($p->expected_from) {
                    $add('breeding_expected_window', $p->reference.' expected window', $p->expected_from->toDateString(), $p->expected_to->toDateString(), 'breeding_project', $p->id, $p->reference);
                }
            }
        }
        if ($ctx->can(Permission::HealthView) && ! isset($f['breeding_project_id'])) {
            $records = HealthRecord::where('farm_id', $farm)->whereNotNull('follow_up_on')->whereNull('reverses_record_id')->whereBetween('follow_up_on', [$from, $to])
                ->whereDoesntHave('reversal')->when($f['production_cycle_id'] ?? null, fn ($q, $id) => $q->where('production_cycle_id', $id))->get();
            foreach ($records as $r) {
                $add('health_follow_up', 'Health follow-up ('.$r->type.')', $r->follow_up_on->toDateString(), null, 'health_record', $r->id, null);
            }
        }

        return $out;
    }
}
