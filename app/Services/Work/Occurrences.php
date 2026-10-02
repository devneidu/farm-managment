<?php

namespace App\Services\Work;

use App\Enums\Recurrence;
use Carbon\CarbonImmutable;

/**
 * The whole recurrence engine: none (one date), daily (every N days) or weekly (every N weeks on chosen ISO weekdays),
 * optionally bounded by an end date and/or an occurrence count (counted from the schedule start, including past dates).
 * Pure date arithmetic on calendar dates; time-of-day and timezone are applied later when a task is built.
 */
final class Occurrences
{
    private const MAX_DAYS = 3700;

    /** @return list<string> Y-m-d dates inside [from, to] */
    public static function between(Recurrence $recurrence, int $interval, ?array $weekdays, string $startsOn, ?string $endsOn, ?int $limit, string $from, string $to): array
    {
        $start = CarbonImmutable::parse($startsOn, 'UTC')->startOfDay();
        $last = CarbonImmutable::parse($endsOn !== null && $endsOn < $to ? $endsOn : $to, 'UTC')->startOfDay();
        $cap = $start->addDays(self::MAX_DAYS);
        if ($last->greaterThan($cap)) {
            $last = $cap;
        }
        if ($recurrence === Recurrence::None) {
            return $startsOn >= $from && $startsOn <= $to ? [$startsOn] : [];
        }
        $interval = max(1, $interval);
        $days = $weekdays ?: [$start->isoWeekday()];
        $firstMonday = $start->startOfWeek();
        $out = [];
        $count = 0;
        for ($d = $start; $d->lessThanOrEqualTo($last); $d = $d->addDay()) {
            $offset = (int) $start->diffInDays($d);
            $matches = $recurrence === Recurrence::Daily
                ? $offset % $interval === 0
                : in_array($d->isoWeekday(), $days, true) && ((int) floor($firstMonday->diffInDays($d) / 7)) % $interval === 0;
            if (! $matches) {
                continue;
            }
            if ($limit !== null && ++$count > $limit) {
                break;
            }
            $date = $d->toDateString();
            if ($date >= $from && $date <= $to) {
                $out[] = $date;
            }
        }

        return $out;
    }
}
