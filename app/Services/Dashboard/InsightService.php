<?php

namespace App\Services\Dashboard;

use App\Enums\Permission;
use App\Models\ProductionCycle;
use App\Services\Inventory\StockLedger;
use App\Support\Finance\Money;
use App\Support\Measurement\Decimal;
use Carbon\CarbonImmutable;

/**
 * Deterministic, rule-based insights: existing farm data -> a fixed rule -> an explainable insight. No model, no scoring, no stored
 * state. Every insight carries a `why` block with the rule name, the measured values, the thresholds and the window, so the user can
 * see exactly why it appeared. An insight is only produced when the viewer holds the permission to see the data behind it.
 */
class InsightService
{
    /** Mortality rule: at least this many deaths in the window AND a rate at or above a threshold (percent of the opening population). */
    public const MORTALITY_MIN_DEATHS = 2;

    public const MORTALITY_WARNING_PERCENT = '2';

    public const MORTALITY_CRITICAL_PERCENT = '5';

    /** An open-task backlog this large is critical rather than a warning. */
    public const OVERDUE_CRITICAL = 5;

    /** Most items one list-style rule (low stock, expiring lots, breeding, cycles) contributes. */
    public const PER_RULE_LIMIT = 5;

    private const RANK = ['critical' => 0, 'warning' => 1, 'info' => 2];

    public function __construct(private StockLedger $ledger) {}

    /**
     * Every insight the viewer may see, most severe first (then rule, then subject - a stable order).
     *
     * @return list<array<string, mixed>>
     */
    public function all(DashboardFacts $f): array
    {
        $out = [];
        foreach ([$this->mortality($f), $this->lowStock($f), $this->expiry($f), $this->overdueTasks($f), $this->overdueInvoices($f), $this->withdrawals($f), $this->breeding($f), $this->cyclesPastEnd($f)] as $group) {
            array_push($out, ...$group);
        }
        usort($out, fn ($a, $b) => [self::RANK[$a['severity']], $a['code'], $a['id']] <=> [self::RANK[$b['severity']], $b['code'], $b['id']]);

        return $out;
    }

    /** @return array<string, mixed> */
    private function make(string $code, string $subjectId, string $severity, string $title, string $message, array $why, ?array $subject = null, array $data = []): array
    {
        return ['id' => $code.':'.$subjectId, 'code' => $code, 'severity' => $severity, 'title' => $title, 'message' => $message, 'why' => ['rule' => $code] + $why, 'subject' => $subject, 'data' => (object) $data];
    }

    private function cycleSubject(ProductionCycle $c): array
    {
        return ['type' => 'production_cycle', 'id' => $c->id, 'reference' => $c->reference, 'name' => $c->name];
    }

    /** @return list<array<string, mixed>> */
    private function mortality(DashboardFacts $f): array
    {
        if (! $f->ctx->can(Permission::RecordView)) {
            return [];
        }
        $out = [];
        foreach ($f->livestockCycles() as $cycle) {
            $m = $f->mortality()[$cycle->id];
            $rate = $f->mortalityRate($cycle);
            if ($m['deaths'] < self::MORTALITY_MIN_DEATHS || Decimal::cmp($rate, self::MORTALITY_WARNING_PERCENT) < 0) {
                continue;
            }
            $critical = Decimal::cmp($rate, self::MORTALITY_CRITICAL_PERCENT) >= 0;
            $out[] = $this->make('mortality_threshold', $cycle->id, $critical ? 'critical' : 'warning', 'Mortality is above the threshold',
                "{$cycle->name}: {$m['deaths']} deaths in the last ".DashboardFacts::MORTALITY_DAYS." days ({$rate}% of the opening population).",
                ['window_days' => DashboardFacts::MORTALITY_DAYS, 'from' => $f->mortalityFrom(), 'to' => $f->clock->today, 'deaths' => $m['deaths'], 'opening_population' => $m['population_at_start'] > 0 ? $m['population_at_start'] : (int) $cycle->current_population + $m['deaths'],
                    'rate_percent' => $rate, 'thresholds' => ['min_deaths' => self::MORTALITY_MIN_DEATHS, 'warning_percent' => self::MORTALITY_WARNING_PERCENT, 'critical_percent' => self::MORTALITY_CRITICAL_PERCENT]],
                $this->cycleSubject($cycle), ['deaths' => $m['deaths'], 'rate_percent' => $rate]);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function lowStock(DashboardFacts $f): array
    {
        if (! $f->ctx->can(Permission::InventoryView)) {
            return [];
        }
        $out = [];
        foreach ($f->lowStock()->take(self::PER_RULE_LIMIT) as $item) {
            $stock = Decimal::trim((string) $item->stock_total);
            $threshold = Decimal::trim((string) $item->low_stock_threshold);
            $empty = Decimal::cmp($stock, '0') <= 0;
            $out[] = $this->make('low_stock', $item->id, $empty ? 'critical' : 'warning', $empty ? 'Out of stock' : 'Low stock',
                $item->name.($empty ? ' is out of stock.' : ' is at or below its low-stock threshold.'),
                ['stock' => $this->ledger->display($item, $stock), 'threshold' => $this->ledger->display($item, $threshold)],
                ['type' => 'inventory_item', 'id' => $item->id, 'reference' => null, 'name' => $item->name]);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function expiry(DashboardFacts $f): array
    {
        if (! $f->ctx->can(Permission::InventoryView)) {
            return [];
        }
        $out = [];
        foreach ($f->expiringLots()->take(self::PER_RULE_LIMIT) as $lot) {
            $expires = $lot->expires_on->toDateString();
            $expired = $expires < $f->clock->today;
            $days = (int) CarbonImmutable::parse($f->clock->today, 'UTC')->diffInDays(CarbonImmutable::parse($expires, 'UTC'), false);
            $out[] = $this->make('lot_expiry', $lot->id, $expired ? 'critical' : 'warning', $expired ? 'Expired stock on hand' : 'Stock expiring soon',
                "{$lot->item->name} lot {$lot->code} ".($expired ? "expired on {$expires}" : "expires on {$expires}").' and still has stock.',
                ['expires_on' => $expires, 'days_left' => $days, 'window_days' => DashboardFacts::EXPIRY_DAYS, 'stock' => $this->ledger->display($lot->item, Decimal::trim((string) $lot->stock_total))],
                ['type' => 'inventory_lot', 'id' => $lot->id, 'reference' => $lot->code, 'name' => $lot->item->name]);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function overdueTasks(DashboardFacts $f): array
    {
        if (! $f->ctx->can(Permission::TaskView)) {
            return [];
        }
        $counts = $f->taskCounts();
        if ($counts['overdue'] < 1) {
            return [];
        }

        return [$this->make('overdue_tasks', 'farm', $counts['overdue'] >= self::OVERDUE_CRITICAL ? 'critical' : 'warning', 'Overdue work',
            $counts['overdue'].($counts['overdue'] === 1 ? ' task is' : ' tasks are').' past due.',
            ['overdue' => $counts['overdue'], 'due_today' => $counts['due_today'], 'critical_at' => self::OVERDUE_CRITICAL], null, ['overdue' => $counts['overdue']])];
    }

    /** @return list<array<string, mixed>> */
    private function overdueInvoices(DashboardFacts $f): array
    {
        if (! $f->ctx->can(Permission::InvoiceView)) {
            return [];
        }
        $r = $f->receivables();
        if ($r['overdue_invoices'] < 1) {
            return [];
        }

        return [$this->make('overdue_invoices', 'farm', 'warning', 'Overdue invoices',
            $r['overdue_invoices'].($r['overdue_invoices'] === 1 ? ' invoice is' : ' invoices are').' past due, with '.Money::format($r['overdue_outstanding']).' '.$f->ctx->farm->currency.' outstanding.',
            ['overdue_invoices' => $r['overdue_invoices'], 'overdue_outstanding' => $r['overdue_outstanding'], 'currency' => $f->ctx->farm->currency, 'as_of' => $f->clock->today], null, ['overdue_invoices' => $r['overdue_invoices']])];
    }

    /** @return list<array<string, mixed>> */
    private function withdrawals(DashboardFacts $f): array
    {
        if (! $f->ctx->can(Permission::HealthView)) {
            return [];
        }
        $lines = $f->withdrawals();
        if ($lines->isEmpty()) {
            return [];
        }
        $ends = $lines->max(fn ($l) => $l->withdrawal_ends_at->toISOString());

        return [$this->make('medicine_withdrawal', 'farm', 'info', 'Medicine withdrawal in effect',
            $lines->count().' medicine '.($lines->count() === 1 ? 'line is' : 'lines are').' still inside the withdrawal period; check before selling eggs, milk or meat.',
            ['lines' => $lines->count(), 'cycles' => $lines->pluck('record.production_cycle_id')->unique()->count(), 'latest_ends_at' => $ends], null, ['latest_ends_at' => $ends])];
    }

    /** @return list<array<string, mixed>> */
    private function breeding(DashboardFacts $f): array
    {
        if (! $f->ctx->can(Permission::BreedingView)) {
            return [];
        }
        $today = $f->clock->today;
        $soon = $f->clock->addDays($today, DashboardFacts::BREEDING_DAYS);
        $out = [];
        foreach ($f->activeBreeding() as $p) {
            $start = ($p->expected_date ?? $p->expected_from)?->toDateString();
            $end = ($p->expected_date ?? $p->expected_to)?->toDateString();
            if ($start === null) {
                continue;
            }
            if ($end < $today) {
                $out[] = $this->make('breeding_overdue', $p->id, 'warning', 'Breeding outcome overdue', "{$p->reference} was expected by {$end} and has no outcome yet.",
                    ['expected_from' => $start, 'expected_to' => $end, 'as_of' => $today], ['type' => 'breeding_project', 'id' => $p->id, 'reference' => $p->reference, 'name' => null]);
            } elseif ($start <= $soon) {
                $out[] = $this->make('breeding_due_soon', $p->id, 'info', 'Breeding outcome due soon', "{$p->reference} is expected between {$start} and {$end}.",
                    ['expected_from' => $start, 'expected_to' => $end, 'window_days' => DashboardFacts::BREEDING_DAYS], ['type' => 'breeding_project', 'id' => $p->id, 'reference' => $p->reference, 'name' => null]);
            }
        }

        return array_slice($out, 0, self::PER_RULE_LIMIT);
    }

    /** @return list<array<string, mixed>> */
    private function cyclesPastEnd(DashboardFacts $f): array
    {
        if (! $f->ctx->can(Permission::ProductionCycleView)) {
            return [];
        }
        $out = [];
        foreach ($f->activeCycles()->filter(fn ($c) => $c->expected_end_date !== null && $c->expected_end_date->toDateString() < $f->clock->today)->take(self::PER_RULE_LIMIT) as $c) {
            $out[] = $this->make('cycle_past_expected_end', $c->id, 'info', 'Past its expected end', "{$c->name} was expected to end on {$c->expected_end_date->toDateString()} and is still active.",
                ['expected_end_date' => $c->expected_end_date->toDateString(), 'as_of' => $f->clock->today], $this->cycleSubject($c));
        }

        return $out;
    }
}
