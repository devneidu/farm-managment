<?php

namespace App\Services\Notifications;

use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Models\ProductionCycle;
use App\Models\Task;
use App\Services\Dashboard\DashboardClock;
use App\Services\Dashboard\DashboardFacts;
use App\Services\Dashboard\InsightService;
use App\Services\Work\ReminderPlan;
use App\Services\Work\WorkSupport;
use App\Support\Access\FarmContext;
use Carbon\CarbonImmutable;

/**
 * DECISION step: given one member of one farm and the current moment, which conditions deserve a notification? It only reads - it never
 * stores anything and never touches the thing it is about. The conditions are the Phase 16 insights (already filtered by what this member
 * may see) plus task reminders for work assigned to this member.
 *
 * Deduplication windows: critical and warning conditions may be announced once per farm-local day while they persist; informational
 * conditions once per farm-local ISO week. Task reminders are announced once per task and reminder time.
 */
class NotificationDecider
{
    /** Furthest ahead (minutes) a task reminder can be configured: 30 days. */
    private const MAX_REMINDER_MINUTES = 43200;

    public function __construct(private InsightService $insights, private WorkSupport $work) {}

    /** @return list<NotificationIntent> */
    public function decide(FarmContext $ctx, DashboardClock $clock): array
    {
        $facts = new DashboardFacts($ctx, $clock, $this->work);
        $week = CarbonImmutable::parse($clock->today, 'UTC')->format('o-\WW');
        $out = [];

        foreach ($this->insights->all($facts) as $insight) {
            $period = $insight['severity'] === 'info' ? $week : $clock->today;
            $subject = $insight['subject'] ?? null;
            $out[] = new NotificationIntent(
                $insight['code'], $insight['severity'], $insight['title'], $insight['message'], 'insight:'.$insight['id'].':'.$period,
                $subject ? ['type' => $subject['type'], 'id' => $this->uuid($subject['id'] ?? null), 'reference' => $subject['reference'] ?? $subject['name'] ?? null] : null,
                ['why' => $insight['why'], 'insight_id' => $insight['id'], 'period' => $period],
            );
        }

        return [...$out, ...$this->taskReminders($ctx, $clock)];
    }

    /**
     * For each open task for this member with reminders, the tightest reminder time that has passed while the task is not yet due. A task is for
     * this member when it is assigned to them (directly or by role) or, when it has no assignee and no role, when they created it. Overdue work is
     * covered by the overdue_tasks insight instead.
     *
     * Reminder times come from ReminderPlan: day-based presets (07:00 farm-local, on_the_day never later than 1 hour before the due time) and the
     * older minute offsets. Presets never fire for a time that was already in the past when the task was created, so a new repeating series does
     * not announce every task of its first week at once. The dedupe key carries the due date, so a task that is moved is reminded again.
     *
     * @return list<NotificationIntent>
     */
    private function taskReminders(FarmContext $ctx, DashboardClock $clock): array
    {
        if (! $ctx->can(Permission::TaskView)) {
            return [];
        }
        $now = $clock->now;
        $user = $ctx->membership->user_id;
        $tasks = Task::where('farm_id', $ctx->farm->id)->where('status', TaskStatus::Open->value)
            ->where(fn ($q) => $q->whereNotNull('reminder_offsets')->orWhereNotNull('reminder_presets'))
            ->where('due_at', '>', $clock->nowSql())->where('due_at', '<=', $now->addMinutes(self::MAX_REMINDER_MINUTES)->format('Y-m-d H:i:s'))
            ->where(fn ($q) => $q->where('assigned_user_id', $user)->orWhere('assigned_role', $ctx->membership->role->value)
                ->orWhere(fn ($w) => $w->whereNull('assigned_user_id')->whereNull('assigned_role')->where('created_by', $user)))
            ->orderBy('due_at')->orderBy('id')->get();
        $batches = ProductionCycle::whereIn('id', $tasks->pluck('production_cycle_id')->filter()->unique()->all())->pluck('name', 'id');

        $out = [];
        foreach ($tasks as $task) {
            $times = ReminderPlan::fireTimes($clock->timezone, $task->due_date->toDateString(), $task->due_time, $task->reminder_presets, $task->reminder_offsets, $task->due_at);
            $crossed = array_filter($times, fn ($at, $key) => $at->lessThanOrEqualTo($now) && (str_starts_with($key, 'm') || $at->greaterThanOrEqualTo($task->created_at)), ARRAY_FILTER_USE_BOTH);
            if ($crossed === []) {
                continue;
            }
            uasort($crossed, fn ($a, $b) => $b <=> $a);
            $key = array_key_first($crossed);
            $local = $task->due_at->setTimezone($clock->timezone);
            $when = $task->due_time !== null ? $local->format('D j M, H:i').' ('.$clock->timezone.')' : CarbonImmutable::parse($task->due_date->toDateString(), 'UTC')->format('D j M').' (all day)';
            $batch = $task->production_cycle_id ? ($batches[$task->production_cycle_id] ?? null) : null;
            $out[] = new NotificationIntent(
                NotificationCatalogue::TASK_REMINDER, 'info', 'Reminder: '.$task->title, "{$task->title} is due {$when}".($batch ? ". Batch: {$batch}" : '').'.',
                'task:'.$task->id.':'.$task->due_date->toDateString().':remind:'.$key,
                ['type' => 'task', 'id' => $task->id, 'reference' => $task->reference],
                ['due_at' => $task->due_at->toISOString(), 'due_date' => $task->due_date->toDateString(), 'due_time' => $task->due_time !== null ? substr($task->due_time, 0, 5) : null,
                    'timezone' => $clock->timezone, 'batch' => $batch, 'reminder' => $key, 'reminder_minutes_before' => (int) round($crossed[$key]->diffInMinutes($task->due_at))],
            );
        }

        return $out;
    }
    private function uuid(mixed $id): ?string
    {
        return is_string($id) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) ? $id : null;
    }
}
