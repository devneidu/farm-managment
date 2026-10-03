<?php

namespace App\Services\Dashboard;

use App\Enums\DueState;
use App\Enums\Permission;
use App\Services\Work\CalendarService;
use App\Support\Access\FarmContext;
use Carbon\CarbonImmutable;

/**
 * The compact calendar strip of the dashboard: a per-day summary built from the Phase 12 calendar read model (CalendarService), so task
 * visibility, milestone permissions and due-state rules are exactly the calendar's. Nothing is stored or duplicated.
 */
class DashboardCalendar
{
    public const DEFAULT_DAYS = 7;

    public const MAX_DAYS = 31;

    public function __construct(private CalendarService $calendar) {}

    /** @return array{from: string, to: string, timezone: string, today: string, days: list<array<string, mixed>>} */
    public function summary(FarmContext $ctx, DashboardClock $clock, ?string $from = null, int $days = self::DEFAULT_DAYS): array
    {
        $ctx->authorize(Permission::TaskView);
        $from ??= $clock->today;
        $days = max(1, min(self::MAX_DAYS, $days));
        $to = CarbonImmutable::parse($from, 'UTC')->addDays($days - 1)->toDateString();
        $range = $this->calendar->range($ctx, ['from' => $from, 'to' => $to, 'include_milestones' => true]);

        $byDay = [];
        for ($i = 0; $i < $days; $i++) {
            $date = CarbonImmutable::parse($from, 'UTC')->addDays($i)->toDateString();
            $byDay[$date] = ['date' => $date, 'is_today' => $date === $clock->today, 'tasks' => ['open' => 0, 'overdue' => 0, 'due_today' => 0, 'upcoming' => 0, 'completed' => 0], 'milestones' => []];
        }
        foreach ($range['items'] as $item) {
            if ($item['kind'] === 'task') {
                $state = $item['task']->dueState($item['now'], $item['today']);
                if ($state === DueState::Cancelled) {
                    continue;
                }
                $day = &$byDay[$item['date']]['tasks'];
                if ($state === DueState::Completed) {
                    $day['completed']++;
                } else {
                    $day['open']++;
                    $day[$state->value]++;
                }
                unset($day);

                continue;
            }
            foreach ($this->spread($item, $from, $to) as $date) {
                $byDay[$date]['milestones'][] = ['code' => $item['code'], 'title' => $item['title'], 'date' => $item['date'], 'end_date' => $item['end_date'], 'source' => $item['source']];
            }
        }

        return ['from' => $from, 'to' => $to, 'timezone' => $range['meta']['timezone'], 'today' => $range['meta']['today'], 'days' => array_values($byDay)];
    }

    /** @return list<string> the in-range days a milestone covers (a window milestone shows on every day of the window) */
    private function spread(array $item, string $from, string $to): array
    {
        $first = max($item['date'], $from);
        $last = min($item['end_date'] ?? $item['date'], $to);
        $dates = [];
        for ($d = CarbonImmutable::parse($first, 'UTC'); $d->toDateString() <= $last; $d = $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        return $dates;
    }
}
