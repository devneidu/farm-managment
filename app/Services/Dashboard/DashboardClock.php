<?php

namespace App\Services\Dashboard;

use App\Models\Farm;
use Carbon\CarbonImmutable;

/**
 * One instant per request and every farm-local day boundary derived from it. Day-based windows ("today", "last 7 days", "this month")
 * are farm-local calendar days converted to UTC instants for comparison with stored timestamps, never UTC days.
 */
final class DashboardClock
{
    public readonly CarbonImmutable $now;

    public readonly string $today;

    public readonly string $timezone;

    public function __construct(Farm $farm, ?CarbonImmutable $now = null)
    {
        $this->now = ($now ?? CarbonImmutable::now())->utc();
        $this->timezone = $farm->timezone;
        $this->today = $this->now->setTimezone($this->timezone)->toDateString();
    }

    public function addDays(string $date, int $days): string
    {
        return CarbonImmutable::parse($date, 'UTC')->addDays($days)->toDateString();
    }

    /** UTC instant at which the farm-local $date starts. */
    public function dayStart(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, $this->timezone)->startOfDay()->utc();
    }

    /** UTC database text of the start of a farm-local day. */
    public function dayStartSql(string $date): string
    {
        return $this->dayStart($date)->format('Y-m-d H:i:s');
    }

    public function nowSql(): string
    {
        return $this->now->format('Y-m-d H:i:s');
    }

    public function monthStart(): string
    {
        return CarbonImmutable::parse($this->today, 'UTC')->startOfMonth()->toDateString();
    }

    public function nextMonthStart(): string
    {
        return CarbonImmutable::parse($this->today, 'UTC')->startOfMonth()->addMonth()->toDateString();
    }
}
