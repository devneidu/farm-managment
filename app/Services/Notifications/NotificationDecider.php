<?php

namespace App\Services\Notifications;

use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Services\Dashboard\DashboardClock;
use App\Services\Dashboard\DashboardFacts;
use App\Services\Dashboard\InsightService;
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
     * For each open task assigned to this member (directly or by role) with reminder offsets, the tightest reminder time that has passed
     * while the task is not yet due. Overdue work is covered by the overdue_tasks insight instead.
     *
     * @return list<NotificationIntent>
     */
    private function taskReminders(FarmContext $ctx, DashboardClock $clock): array
    {
        if (! $ctx->can(Permission::TaskView)) {
            return [];
        }
        $now = $clock->now;
        $tasks = Task::where('farm_id', $ctx->farm->id)->where('status', TaskStatus::Open->value)->whereNotNull('reminder_offsets')
            ->where('due_at', '>', $clock->nowSql())->where('due_at', '<=', $now->addMinutes(self::MAX_REMINDER_MINUTES)->format('Y-m-d H:i:s'))
            ->where(fn ($q) => $q->where('assigned_user_id', $ctx->membership->user_id)->orWhere('assigned_role', $ctx->membership->role->value))
            ->orderBy('due_at')->orderBy('id')->get();

        $out = [];
        foreach ($tasks as $task) {
            $crossed = array_filter(array_map('intval', (array) $task->reminder_offsets), fn ($offset) => $task->due_at->subMinutes($offset)->lessThanOrEqualTo($now));
            if ($crossed === []) {
                continue;
            }
            $offset = min($crossed);
            $due = $task->due_at->setTimezone($clock->timezone)->format('D j M, H:i');
            $out[] = new NotificationIntent(NotificationCatalogue::TASK_REMINDER, 'info', 'Task due soon', "{$task->title} is due {$due}.", 'task:'.$task->id.':remind:'.$offset,
                ['type' => 'task', 'id' => $task->id, 'reference' => $task->reference], ['due_at' => $task->due_at->toISOString(), 'reminder_minutes_before' => $offset]);
        }

        return $out;
    }

    private function uuid(mixed $id): ?string
    {
        return is_string($id) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) ? $id : null;
    }
}
