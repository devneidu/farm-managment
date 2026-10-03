<?php

namespace App\Services\Dashboard;

use App\Enums\CycleKind;
use App\Enums\Permission;
use App\Http\Resources\TaskResource;
use App\Models\FinanceTransaction;
use App\Models\Sale;
use App\Services\Finance\FinanceQueries;
use App\Services\Work\WorkSupport;
use App\Support\Access\FarmContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The dashboard is a read model: it assembles what the farm's authoritative modules already know (cycles, population and stock ledgers,
 * records, tasks, finance, invoices) for THIS farm and THIS viewer, and stores nothing. A card exists only when it is relevant to the
 * operations the farm runs (selected operations or actual active production) AND the viewer holds the permission behind its data.
 */
class DashboardService
{
    /** Items listed in the "Today & Overdue" block, upcoming block, recent-completed block and the active-production block. */
    public const WORK_ITEMS = 10;

    public const UPCOMING_ITEMS = 5;

    public const COMPLETED_ITEMS = 5;

    public const PRODUCTION_ITEMS = 6;

    /** Insights embedded in the dashboard (the full list is `GET /insights`). */
    public const INSIGHT_ITEMS = 5;

    public function __construct(
        private WorkSupport $work,
        private InsightService $insights,
        private ActivityFeed $activity,
        private QuickActions $quick,
        private DashboardCalendar $calendar,
        private FinanceQueries $finance,
    ) {}

    public function facts(FarmContext $ctx): DashboardFacts
    {
        return new DashboardFacts($ctx, new DashboardClock($ctx->farm), $this->work);
    }

    /** @return array<string, mixed> */
    public function build(FarmContext $ctx, Request $request): array
    {
        $f = $this->facts($ctx);
        $clock = $f->clock;
        $insights = $this->insights->all($f);
        $can = fn (Permission $p) => $ctx->can($p);

        $data = [
            'farm' => ['id' => $ctx->farm->id, 'name' => $ctx->farm->name, 'currency' => $ctx->farm->currency, 'timezone' => $ctx->farm->timezone, 'today' => $clock->today, 'generated_at' => $clock->now->toISOString()],
            'viewer' => ['user_id' => $ctx->membership->user_id, 'role' => $ctx->membership->role->value, 'role_label' => $ctx->membership->role->label()],
            'operations' => [
                'configured' => $f->operations()->isNotEmpty(),
                'selected' => $f->operations()->map(fn ($o) => ['id' => $o->id, 'code' => $o->code, 'name' => $o->name, 'category' => $o->category->value])->values()->all(),
                'relevant' => ['livestock' => $f->livestockRelevant(), 'crop' => $f->cropRelevant()],
            ],
            'sections' => [
                'production' => $can(Permission::ProductionCycleView), 'work' => $can(Permission::TaskView), 'calendar' => $can(Permission::TaskView),
                'inventory' => $can(Permission::InventoryView), 'health' => $can(Permission::HealthView), 'breeding' => $can(Permission::BreedingView),
                'finance' => $can(Permission::FinanceView), 'sales' => $can(Permission::SaleView) || $can(Permission::InvoiceView),
            ],
            'priority' => array_map(fn ($i) => ['id' => $i['id'], 'code' => $i['code'], 'severity' => $i['severity'], 'title' => $i['title'], 'message' => $i['message']],
                array_slice(array_values(array_filter($insights, fn ($i) => $i['severity'] !== 'info')), 0, 3)),
            'kpis' => $this->kpis($f),
            'work' => $can(Permission::TaskView) ? $this->workBlock($f, $request) : null,
            'calendar' => $can(Permission::TaskView) ? $this->calendar->summary($ctx, $clock) : null,
            'quick_record' => $this->quick->quickRecord($f),
            'production' => $can(Permission::ProductionCycleView) ? $this->production($f) : null,
            'insights' => ['total' => count($insights), 'by_severity' => $this->bySeverity($insights), 'items' => array_slice($insights, 0, self::INSIGHT_ITEMS)],
            'recent_activity' => $this->activity->recent($f),
            'quick_add' => $this->quick->quickAdd($f),
        ];
        $data['empty_state'] = $f->activeCycles()->isEmpty() ? [
            'has_operations' => $data['operations']['configured'], 'has_active_production' => false,
            'message' => 'No active batch or crop project yet. Start one to see production, work and insights here.',
            'suggested_actions' => array_values(array_filter($data['quick_add'], fn ($a) => in_array($a['code'], ['production_cycle', 'stock_in'], true))),
        ] : null;

        return $data;
    }

    /**
     * @param  array{severity?: string, limit?: int}  $filters
     * @return array{items: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function insightList(FarmContext $ctx, array $filters): array
    {
        $f = $this->facts($ctx);
        $all = $this->insights->all($f);
        $items = isset($filters['severity']) ? array_values(array_filter($all, fn ($i) => $i['severity'] === $filters['severity'])) : $all;
        $limit = (int) ($filters['limit'] ?? 50);

        return ['items' => array_slice($items, 0, $limit), 'meta' => ['total' => count($items), 'by_severity' => $this->bySeverity($all), 'today' => $f->clock->today, 'timezone' => $ctx->farm->timezone]];
    }

    /** @return array{critical: int, warning: int, info: int} */
    private function bySeverity(array $insights): array
    {
        $out = ['critical' => 0, 'warning' => 0, 'info' => 0];
        foreach ($insights as $i) {
            $out[$i['severity']]++;
        }

        return $out;
    }

    // ------------------------------------------------------------------ KPIs

    /** @return list<array<string, mixed>> only the cards that are relevant to this farm's operations and visible to this viewer */
    private function kpis(DashboardFacts $f): array
    {
        $ctx = $f->ctx;
        $out = [];
        $add = function (string $code, string $label, string $section, ?string $value, ?string $unit, array $breakdown = [], array $detail = []) use (&$out) {
            $out[] = ['code' => $code, 'label' => $label, 'section' => $section, 'value' => $value, 'unit' => $unit, 'breakdown' => $breakdown, 'detail' => (object) $detail];
        };
        $breakdown = fn (array $sums) => array_map(fn ($unit, $quantity) => ['unit' => $unit, 'quantity' => $quantity], array_keys($sums), array_values($sums));

        if ($f->livestockRelevant() && $ctx->can(Permission::ProductionCycleView)) {
            $cycles = $f->livestockCycles();
            $add('livestock_population', 'Livestock', 'livestock', (string) array_sum(array_map(fn ($c) => (int) $c->current_population, $cycles)), 'head', [], ['active_batches' => count($cycles)]);
        }
        if ($f->livestockCycles() !== [] && $ctx->can(Permission::RecordView)) {
            $deaths = array_sum(array_column($f->mortality(), 'deaths'));
            $add('mortality_7d', 'Deaths, last 7 days', 'livestock', (string) $deaths, 'head', [], ['window_days' => DashboardFacts::MORTALITY_DAYS, 'from' => $f->mortalityFrom(), 'to' => $f->clock->today]);
        }
        if ($ctx->can(Permission::RecordView) && $f->anyCycleHas('produces_eggs')) {
            $eggs = $f->eggsToday();
            $add('eggs_today', 'Eggs today', 'livestock', $eggs['piece'] ?? '0', 'piece', $breakdown($eggs), ['date' => $f->clock->today]);
        }
        if ($f->cropRelevant() && $ctx->can(Permission::ProductionCycleView)) {
            $add('active_crop_projects', 'Crop projects', 'crops', (string) $f->activeCycles()->filter(fn ($c) => $c->kind === CycleKind::Crop)->count(), 'project');
        }
        if ($f->cropRelevant() && $ctx->can(Permission::RecordView)) {
            $add('harvest_30d', 'Harvest, last 30 days', 'crops', null, null, $breakdown($f->harvest()['total']), ['window_days' => DashboardFacts::HARVEST_DAYS]);
        }
        if ($ctx->can(Permission::InventoryView) && $f->hasStockThresholds()) {
            $add('low_stock_items', 'Low stock', 'inventory', (string) $f->lowStock()->count(), 'item');
        }
        if ($ctx->can(Permission::HealthView) && $f->withdrawals()->isNotEmpty()) {
            $add('active_withdrawals', 'In withdrawal', 'health', (string) $f->withdrawals()->count(), 'medicine line');
        }
        if ($ctx->can(Permission::BreedingView) && $f->activeBreeding()->isNotEmpty()) {
            $add('active_breeding_projects', 'Breeding projects', 'breeding', (string) $f->activeBreeding()->count(), 'project');
        }
        if ($ctx->can(Permission::FinanceView) && FinanceTransaction::where('farm_id', $ctx->farm->id)->exists()) {
            $totals = $this->finance->summary($ctx, ['from' => $f->clock->monthStart(), 'to' => $f->clock->today])['totals'];
            $detail = ['from' => $f->clock->monthStart(), 'to' => $f->clock->today, 'currency' => $ctx->farm->currency];
            $add('income_month', 'Income this month', 'finance', $totals['income'], $ctx->farm->currency, [], $detail);
            $add('expense_month', 'Expenses this month', 'finance', $totals['expense'], $ctx->farm->currency, [], $detail);
            $add('net_month', 'Net this month', 'finance', $totals['net'], $ctx->farm->currency, [], $detail);
        }
        if ($ctx->can(Permission::SaleView) && Sale::where('farm_id', $ctx->farm->id)->exists()) {
            $s = $f->salesMonth();
            $add('sales_month', 'Sales this month', 'sales', $s['total'], $ctx->farm->currency, [], ['sales' => $s['count'], 'from' => $f->clock->monthStart(), 'to' => $f->clock->today]);
        }
        if ($ctx->can(Permission::InvoiceView) && $f->receivables()['has_invoices']) {
            $r = $f->receivables();
            $add('receivables', 'Owed to you', 'sales', $r['outstanding'], $ctx->farm->currency, [], ['invoices' => $r['invoices'], 'overdue_invoices' => $r['overdue_invoices'], 'overdue_outstanding' => $r['overdue_outstanding']]);
        }

        return $out;
    }

    // ------------------------------------------------------------------ work

    /** @return array<string, mixed> */
    private function workBlock(DashboardFacts $f, Request $request): array
    {
        $now = $f->clock->nowSql();
        $today = $f->clock->today;
        $counts = $f->taskCounts();
        $resolve = fn ($tasks) => TaskResource::collection($tasks)->resolve($request);

        $focus = $f->visibleTasks()->with('assignee:id,name')->where('status', 'open')->where(fn ($q) => $q->where('due_at', '<=', $now)->orWhere('due_date', '<=', $today))
            ->orderBy('due_at')->orderBy('id')->limit(self::WORK_ITEMS)->get();
        $upcoming = $f->visibleTasks()->with('assignee:id,name')->where('status', 'open')->where('due_date', '>', $today)->orderBy('due_at')->orderBy('id')->limit(self::UPCOMING_ITEMS)->get();
        $completed = $f->visibleTasks()->with('assignee:id,name')->where('status', 'completed')->where('completed_at', '>=', $f->clock->dayStartSql($f->clock->addDays($today, -6)))
            ->orderByDesc('completed_at')->orderByDesc('id')->limit(self::COMPLETED_ITEMS)->get();

        return ['counts' => $counts, 'today_and_overdue' => $resolve($focus), 'upcoming' => $resolve($upcoming), 'recently_completed' => $resolve($completed)];
    }

    // ------------------------------------------------------------------ production

    /** @return array<string, mixed> */
    private function production(DashboardFacts $f): array
    {
        $harvest = $f->harvest()['by_cycle'];
        $items = [];
        foreach ($f->activeCycles()->take(self::PRODUCTION_ITEMS) as $c) {
            $row = [
                'id' => $c->id, 'reference' => $c->reference, 'name' => $c->name, 'kind' => $c->kind->value,
                'operation' => $c->operation ? ['id' => $c->operation->id, 'code' => $c->operation->code, 'name' => $c->operation->name] : null,
                'production_area' => $c->productionArea ? ['id' => $c->productionArea->id, 'name' => $c->productionArea->name] : null,
                'start_date' => $c->start_date->toDateString(), 'expected_end_date' => $c->expected_end_date?->toDateString(),
                'age_days' => (int) $c->start_date->diffInDays(CarbonImmutable::parse($f->clock->today, 'UTC')), 'livestock' => null, 'crop' => null,
            ];
            if ($c->kind === CycleKind::Livestock && $c->livestock) {
                $m = $f->mortality()[$c->id] ?? ['deaths' => 0];
                $row['livestock'] = ['species' => ['id' => $c->livestock->species->id, 'code' => $c->livestock->species->code, 'name' => $c->livestock->species->name],
                    'initial_population' => $c->livestock->initial_population, 'current_population' => (int) $c->current_population, 'population_unit' => 'head', 'deaths_7d' => $m['deaths']];
            }
            if ($c->kind === CycleKind::Crop && $c->crop) {
                $units = $harvest[$c->id] ?? [];
                $row['crop'] = ['crop_type' => ['id' => $c->crop->cropType->id, 'code' => $c->crop->cropType->code, 'name' => $c->crop->cropType->name], 'initial_planting_units' => $c->crop->initial_planting_units,
                    'harvest_30d' => array_map(fn ($u, $q) => ['unit' => $u, 'quantity' => $q], array_keys($units), array_values($units))];
            }
            $items[] = $row;
        }

        return ['total_active' => $f->activeCycles()->count(), 'items' => $items];
    }
}
