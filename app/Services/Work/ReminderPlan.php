<?php

namespace App\Services\Work;

use Carbon\CarbonImmutable;

/**
 * When a task's reminders fire. Pure and shared by the notification decider and the phone-calendar alarms, so both always agree.
 *
 * Presets are farm-local and day based: every preset fires at 07:00 farm-local on its reminder day (the due day minus 0, 1, 2 or 7 days).
 * "On the day" never fires later than one hour before the due time (never earlier than the start of that day). Legacy `reminder_offsets`
 * are minutes before the due instant and keep their old meaning.
 */
final class ReminderPlan
{
    public const PRESETS = ['on_the_day' => 0, '1_day' => 1, '2_days' => 2, '7_days' => 7];

    public const FIRE_HOUR = 7;

    /** @return array<string, CarbonImmutable> key (preset name or "m<minutes>") => UTC fire instant */
    public static function fireTimes(string $timezone, string $dueDate, ?string $dueTime, ?array $presets, ?array $offsets, CarbonImmutable $dueAt): array
    {
        $out = [];
        $time = $dueTime !== null ? substr($dueTime, 0, 5) : null;
        foreach ((array) $presets as $preset) {
            if (! isset(self::PRESETS[$preset])) {
                continue;
            }
            $day = CarbonImmutable::parse($dueDate, $timezone)->startOfDay()->subDays(self::PRESETS[$preset]);
            $fire = $day->setTime(self::FIRE_HOUR, 0);
            if ($preset === 'on_the_day' && $time !== null) {
                $limit = CarbonImmutable::parse($dueDate.' '.$time, $timezone)->subHour();
                if ($limit->lessThan($fire)) {
                    $fire = $limit->lessThan($day) ? $day : $limit;
                }
            }
            $out[$preset] = $fire->utc();
        }
        foreach ((array) $offsets as $offset) {
            $out['m'.(int) $offset] = $dueAt->subMinutes((int) $offset)->utc();
        }

        return $out;
    }
}
