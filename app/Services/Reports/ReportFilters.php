<?php

namespace App\Services\Reports;

use App\Services\Dashboard\DashboardClock;
use Carbon\CarbonImmutable;

/**
 * The resolved filters of one report run. Dates are farm-local calendar days; the UTC instants used to compare them with stored
 * timestamps come from the farm clock, never from UTC days. An omitted period defaults to the last DEFAULT_DAYS farm-local days
 * (including today) and an omitted as_of to today, so an export always records the exact filters it was produced with.
 */
final class ReportFilters
{
    public const DEFAULT_DAYS = 30;

    public function __construct(
        public readonly DashboardClock $clock,
        public readonly string $from,
        public readonly string $to,
        public readonly string $asOf,
        public readonly ?string $productionCycleId = null,
        public readonly ?string $kind = null,
        public readonly ?string $status = null,
    ) {}

    /** @param  array<string, mixed>  $input validated input (already shaped by ReportRequest) */
    public static function resolve(DashboardClock $clock, array $input): self
    {
        $to = $input['to'] ?? $clock->today;
        $from = $input['from'] ?? CarbonImmutable::parse($to, 'UTC')->subDays(self::DEFAULT_DAYS - 1)->toDateString();

        return new self($clock, $from, $to, $input['as_of'] ?? $clock->today, $input['production_cycle_id'] ?? null, $input['kind'] ?? null, $input['status'] ?? null);
    }

    /** UTC database text of the start of the first day of the period. */
    public function fromTs(): string
    {
        return $this->clock->dayStartSql($this->from);
    }

    /** UTC database text of the instant after the last day of the period (exclusive upper bound). */
    public function toTs(): string
    {
        return $this->clock->dayStartSql($this->clock->addDays($this->to, 1));
    }

    /** UTC database text of the instant after the as_of day (exclusive upper bound for "balance as of"). */
    public function asOfTs(): string
    {
        return $this->clock->dayStartSql($this->clock->addDays($this->asOf, 1));
    }

    /**
     * The filters a report actually used, as stored with an export and echoed to clients.
     *
     * @return array<string, string>
     */
    public function applied(ReportDefinition $d): array
    {
        $out = [];
        foreach ($d->filters as $name) {
            $value = match ($name) {
                'from' => $this->from, 'to' => $this->to, 'as_of' => $this->asOf, 'production_cycle_id' => $this->productionCycleId,
                'kind' => $this->kind, 'status' => $this->status, default => null,
            };
            if ($value !== null) {
                $out[$name] = $value;
            }
        }

        return $out;
    }
}
