<?php

namespace App\Services\Dashboard;

use App\Enums\BreedingStatus;
use App\Enums\CycleKind;
use App\Enums\CycleStatus;
use App\Enums\OperationCategory;
use App\Enums\TaskStatus;
use App\Models\BreedingProject;
use App\Models\FarmOperation;
use App\Models\HealthRecordMedicine;
use App\Models\InventoryItem;
use App\Models\InventoryLot;
use App\Models\Invoice;
use App\Models\OperationalRecord;
use App\Models\OperationType;
use App\Models\Payment;
use App\Models\PopulationMovement;
use App\Models\ProductionCycle;
use App\Models\Sale;
use App\Models\Task;
use App\Services\Inventory\InventoryQueries;
use App\Services\Work\WorkSupport;
use App\Support\Access\FarmContext;
use App\Support\Finance\Money;
use App\Support\Measurement\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The farm facts the dashboard and the insight rules are built from. Every fact is a read over an authoritative module (cycles, the
 * population ledger, operational records, the stock ledger, health, breeding, tasks, invoices/payments); nothing is stored, and each fact
 * is computed at most once per request so the dashboard and the insights never repeat a query. Facts ignore the viewer's permissions -
 * callers decide what the viewer may see.
 */
class DashboardFacts
{
    /** Trailing farm-local days (including today) used for mortality. */
    public const MORTALITY_DAYS = 7;

    /** Trailing farm-local days (including today) used for harvest output. */
    public const HARVEST_DAYS = 30;

    /** Lots expiring within this many farm-local days (or already expired, with stock left) are flagged. */
    public const EXPIRY_DAYS = 14;

    /** Breeding projects expected within this many farm-local days are "due soon". */
    public const BREEDING_DAYS = 7;

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(public readonly FarmContext $ctx, public readonly DashboardClock $clock, private readonly WorkSupport $work) {}

    private function once(string $key, callable $compute): mixed
    {
        return array_key_exists($key, $this->memo) ? $this->memo[$key] : $this->memo[$key] = $compute();
    }

    private function farmId(): string
    {
        return $this->ctx->farm->id;
    }

    // ------------------------------------------------------------------ operations and relevance

    /** @return Collection<int, OperationType> the operations the farm selected (empty = not configured) */
    public function operations(): Collection
    {
        return $this->once('operations', fn () => OperationType::whereIn('id', FarmOperation::where('farm_id', $this->farmId())->select('operation_type_id'))->orderBy('sort_order')->orderBy('code')->get());
    }

    /** @return Collection<int, ProductionCycle> active cycles with their details and the ledger population */
    public function activeCycles(): Collection
    {
        return $this->once('cycles', fn () => ProductionCycle::ofFarm($this->ctx->farm)->where('status', CycleStatus::Active->value)
            ->with(['operation', 'livestock.species', 'crop.cropType', 'productionArea'])->withSum('movements as current_population', 'quantity')
            ->orderByDesc('start_date')->orderBy('id')->get());
    }

    /** True when livestock/aquaculture (population-tracked) content is relevant: it is a selected operation or an active cycle exists. */
    public function livestockRelevant(): bool
    {
        return $this->once('rel.livestock', fn () => $this->operations()->contains(fn ($o) => in_array($o->category, [OperationCategory::Livestock, OperationCategory::Aquaculture], true))
            || $this->activeCycles()->contains(fn ($c) => $c->kind === CycleKind::Livestock));
    }

    public function cropRelevant(): bool
    {
        return $this->once('rel.crop', fn () => $this->operations()->contains(fn ($o) => $o->category === OperationCategory::Crop)
            || $this->activeCycles()->contains(fn ($c) => $c->kind === CycleKind::Crop));
    }

    /** @return list<ProductionCycle> */
    public function livestockCycles(): array
    {
        return $this->activeCycles()->filter(fn ($c) => $c->kind === CycleKind::Livestock)->values()->all();
    }

    /** @return array<string, list<string>> enabled capability codes by species id, for the species of the active livestock cycles */
    public function speciesCapabilities(): array
    {
        return $this->once('caps', function () {
            $species = collect($this->livestockCycles())->map(fn ($c) => $c->livestock?->species_id)->filter()->unique()->values()->all();
            if ($species === []) {
                return [];
            }
            $out = [];
            foreach (DB::table('species_capabilities as sc')->join('capabilities as c', 'c.id', '=', 'sc.capability_id')->whereIn('sc.species_id', $species)->where('sc.enabled', true)->select('sc.species_id', 'c.code')->get() as $row) {
                $out[$row->species_id][] = $row->code;
            }

            return $out;
        });
    }

    /** True when at least one active livestock cycle has a species with the capability enabled. */
    public function anyCycleHas(string $capability): bool
    {
        foreach ($this->livestockCycles() as $cycle) {
            if (in_array($capability, $this->speciesCapabilities()[$cycle->livestock?->species_id] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ livestock

    /** First farm-local day of the trailing mortality window. */
    public function mortalityFrom(): string
    {
        return $this->clock->addDays($this->clock->today, -(self::MORTALITY_DAYS - 1));
    }

    /**
     * Per active livestock cycle: deaths in the window (mortality records that were not reversed) and the ledger population when the
     * window opened. The rate denominator is that population, falling back to current + deaths for a cycle that started inside the window.
     *
     * @return array<string, array{deaths: int, population_at_start: int}>
     */
    public function mortality(): array
    {
        return $this->once('mortality', function () {
            $ids = array_map(fn ($c) => $c->id, $this->livestockCycles());
            if ($ids === []) {
                return [];
            }
            $start = $this->clock->dayStartSql($this->mortalityFrom());
            $deaths = OperationalRecord::where('farm_id', $this->farmId())->whereIn('production_cycle_id', $ids)->where('type', 'mortality')
                ->where('recorded_at', '>=', $start)->where('recorded_at', '<=', $this->clock->nowSql())->whereDoesntHave('reversal')
                ->toBase()->selectRaw('production_cycle_id, -SUM(population_delta) as deaths')->groupBy('production_cycle_id')->pluck('deaths', 'production_cycle_id');
            $before = PopulationMovement::where('farm_id', $this->farmId())->whereIn('production_cycle_id', $ids)->where('recorded_at', '<', $start)
                ->toBase()->selectRaw('production_cycle_id, SUM(quantity) as population')->groupBy('production_cycle_id')->pluck('population', 'production_cycle_id');
            $out = [];
            foreach ($ids as $id) {
                $out[$id] = ['deaths' => (int) ($deaths[$id] ?? 0), 'population_at_start' => (int) ($before[$id] ?? 0)];
            }

            return $out;
        });
    }

    /** Percentage (two decimals at most) of deaths over the opening population (current + deaths when the cycle is younger than the window). */
    public function mortalityRate(ProductionCycle $cycle): string
    {
        $m = $this->mortality()[$cycle->id] ?? ['deaths' => 0, 'population_at_start' => 0];
        $base = $m['population_at_start'] > 0 ? $m['population_at_start'] : (int) $cycle->current_population + $m['deaths'];

        return $base > 0 ? Decimal::trim(Decimal::round(Decimal::div(Decimal::mul((string) $m['deaths'], '100'), (string) $base), 2)) : '0';
    }

    /**
     * Normalised quantities of today's egg collections by unit (never mixed across units).
     *
     * @return array<string, string> unit => quantity
     */
    public function eggsToday(): array
    {
        return $this->once('eggs', function () {
            $rows = OperationalRecord::where('farm_id', $this->farmId())->where('type', 'egg_collection')->where('recorded_at', '>=', $this->clock->dayStartSql($this->clock->today))
                ->where('recorded_at', '<=', $this->clock->nowSql())->whereDoesntHave('reversal')->get(['id', 'measurement']);

            return $this->sumNormalised($rows->map(fn ($r) => $r->measurement));
        });
    }

    // ------------------------------------------------------------------ crops

    /**
     * Harvest output of the trailing window, from non-reversed crop_harvest records, in total and by cycle. Units stay separate.
     *
     * @return array{total: array<string, string>, by_cycle: array<string, array<string, string>>}
     */
    public function harvest(): array
    {
        return $this->once('harvest', function () {
            $from = $this->clock->dayStartSql($this->clock->addDays($this->clock->today, -(self::HARVEST_DAYS - 1)));
            $rows = OperationalRecord::where('farm_id', $this->farmId())->where('type', 'crop_harvest')->where('recorded_at', '>=', $from)->where('recorded_at', '<=', $this->clock->nowSql())
                ->whereDoesntHave('reversal')->get(['id', 'production_cycle_id', 'measurement']);
            $by = [];
            foreach ($rows->groupBy('production_cycle_id') as $cycleId => $group) {
                $by[$cycleId] = $this->sumNormalised($group->map(fn ($r) => $r->measurement));
            }

            return ['total' => $this->sumNormalised($rows->map(fn ($r) => $r->measurement)), 'by_cycle' => $by];
        });
    }

    /** @return array<string, string> */
    private function sumNormalised(iterable $measurements): array
    {
        $sums = [];
        foreach ($measurements as $m) {
            $n = $m['normalized'] ?? null;
            if ($n !== null) {
                $sums[$n['unit']] = Decimal::add($sums[$n['unit']] ?? '0', (string) $n['quantity']);
            }
        }
        ksort($sums);

        return array_map(fn ($v) => Decimal::trim($v), $sums);
    }

    // ------------------------------------------------------------------ inventory

    /** @return Collection<int, InventoryItem> active items with a threshold whose stock is at or below it (stock_total = canonical balance) */
    public function lowStock(): Collection
    {
        return $this->once('low', fn () => InventoryItem::ofFarm($this->ctx->farm)->with('stockUnit.dimension')->selectRaw('inventory_items.*, '.InventoryQueries::ITEM_TOTAL.' as stock_total')
            ->where('is_active', true)->whereNotNull('low_stock_threshold')->whereRaw(InventoryQueries::ITEM_TOTAL.' <= inventory_items.low_stock_threshold')
            ->orderBy('normalized_name')->orderBy('id')->get());
    }

    /** True when any active item carries a low-stock threshold (so "0 low items" is a meaningful number). */
    public function hasStockThresholds(): bool
    {
        return $this->once('thresholds', fn () => InventoryItem::ofFarm($this->ctx->farm)->where('is_active', true)->whereNotNull('low_stock_threshold')->exists());
    }

    /** @return Collection<int, InventoryLot> lots that still hold stock and expire within the warning window or already expired (stock_total = canonical balance) */
    public function expiringLots(): Collection
    {
        return $this->once('lots', fn () => InventoryLot::where('farm_id', $this->farmId())->whereNotNull('expires_on')->where('expires_on', '<=', $this->clock->addDays($this->clock->today, self::EXPIRY_DAYS))
            ->selectRaw('inventory_lots.*, '.InventoryQueries::LOT_TOTAL.' as stock_total')->whereRaw(InventoryQueries::LOT_TOTAL.' > 0')
            ->with('item.stockUnit.dimension')->orderBy('expires_on')->orderBy('id')->get());
    }

    // ------------------------------------------------------------------ health and breeding

    /** @return Collection<int, HealthRecordMedicine> medicine lines whose withdrawal period is still running (event not reversed) */
    public function withdrawals(): Collection
    {
        return $this->once('withdrawals', fn () => HealthRecordMedicine::where('farm_id', $this->farmId())->whereNotNull('withdrawal_ends_at')->where('withdrawal_ends_at', '>', $this->clock->nowSql())
            ->whereDoesntHave('record.reversal')->with('record')->orderBy('withdrawal_ends_at')->orderBy('id')->get());
    }

    /** @return Collection<int, BreedingProject> */
    public function activeBreeding(): Collection
    {
        return $this->once('breeding', fn () => BreedingProject::where('farm_id', $this->farmId())->where('status', BreedingStatus::Active->value)->orderBy('expected_date')->orderBy('id')->get());
    }

    // ------------------------------------------------------------------ work

    /** Tasks the viewer may see (Phase 12 visibility). */
    public function visibleTasks(): Builder
    {
        return $this->work->visible(Task::where('farm_id', $this->farmId()), $this->ctx);
    }

    /**
     * Counts of the viewer's visible tasks by derived due state (same rules as DueState::derive and the task list filter).
     *
     * @return array{overdue: int, due_today: int, upcoming: int, upcoming_7d: int, completed_today: int}
     */
    public function taskCounts(): array
    {
        return $this->once('taskCounts', function () {
            $now = $this->clock->nowSql();
            $today = $this->clock->today;
            $open = TaskStatus::Open->value;
            $row = $this->visibleTasks()->toBase()->selectRaw(
                "COALESCE(SUM(status = '$open' AND due_at <= ?), 0) as overdue, "
                ."COALESCE(SUM(status = '$open' AND due_at > ? AND due_date <= ?), 0) as due_today, "
                ."COALESCE(SUM(status = '$open' AND due_date > ?), 0) as upcoming, "
                ."COALESCE(SUM(status = '$open' AND due_date > ? AND due_date <= ?), 0) as upcoming_7d, "
                .'COALESCE(SUM(status = ? AND completed_at >= ? AND completed_at < ?), 0) as completed_today',
                [$now, $now, $today, $today, $today, $this->clock->addDays($today, 7), TaskStatus::Completed->value, $this->clock->dayStartSql($today), $this->clock->dayStartSql($this->clock->addDays($today, 1))],
            )->first();

            return ['overdue' => (int) $row->overdue, 'due_today' => (int) $row->due_today, 'upcoming' => (int) $row->upcoming, 'upcoming_7d' => (int) $row->upcoming_7d, 'completed_today' => (int) $row->completed_today];
        });
    }

    // ------------------------------------------------------------------ sales and receivables

    /**
     * Money still owed on live (issued) invoices; a payment that was reversed does not count as received.
     *
     * @return array{invoices: int, outstanding: string, overdue_invoices: int, overdue_outstanding: string, has_invoices: bool}
     */
    public function receivables(): array
    {
        return $this->once('receivables', function () {
            $paid = "(select COALESCE(SUM(p.amount), 0) from payments p where p.invoice_id = invoices.id and p.entry_type = '".Payment::PAYMENT."' "
                .'and not exists (select 1 from payments r where r.reverses_payment_id = p.id))';
            $row = Invoice::where('farm_id', $this->farmId())->where('status', Invoice::ISSUED)->whereRaw("invoices.total_amount - $paid > 0")->toBase()->selectRaw(
                "COUNT(*) as n, COALESCE(SUM(invoices.total_amount - $paid), 0) as outstanding, "
                .'COALESCE(SUM(invoices.due_date IS NOT NULL AND invoices.due_date < ?), 0) as overdue_n, '
                ."COALESCE(SUM(CASE WHEN invoices.due_date IS NOT NULL AND invoices.due_date < ? THEN invoices.total_amount - $paid ELSE 0 END), 0) as overdue_outstanding",
                [$this->clock->today, $this->clock->today],
            )->first();

            return [
                'invoices' => (int) $row->n, 'outstanding' => Money::add((string) $row->outstanding, '0'),
                'overdue_invoices' => (int) $row->overdue_n, 'overdue_outstanding' => Money::add((string) $row->overdue_outstanding, '0'),
                'has_invoices' => Invoice::where('farm_id', $this->farmId())->where('status', Invoice::ISSUED)->exists(),
            ];
        });
    }

    /** @return array{count: int, total: string} sales (not cancelled) recorded in the farm-local month so far */
    public function salesMonth(): array
    {
        return $this->once('salesMonth', function () {
            $row = Sale::where('farm_id', $this->farmId())->where('status', Sale::ACTIVE)->where('recorded_at', '>=', $this->clock->dayStartSql($this->clock->monthStart()))
                ->where('recorded_at', '<', $this->clock->dayStartSql($this->clock->nextMonthStart()))->toBase()->selectRaw('COUNT(*) as n, COALESCE(SUM(total_amount), 0) as total')->first();

            return ['count' => (int) $row->n, 'total' => Money::add((string) $row->total, '0')];
        });
    }
}
