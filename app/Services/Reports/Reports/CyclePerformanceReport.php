<?php

namespace App\Services\Reports\Reports;

use App\Enums\CycleKind;
use App\Enums\Feature;
use App\Enums\Permission;
use App\Models\OperationalRecord;
use App\Models\PopulationMovement;
use App\Models\ProductionCycle;
use App\Services\Reports\Report;
use App\Services\Reports\ReportDefinition;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use App\Support\Access\FarmContext;
use App\Support\Measurement\Decimal;
use Carbon\CarbonImmutable;

/** One row per production cycle that overlaps the period: lifecycle, ledger population at the period edges and mortality in the period. */
class CyclePerformanceReport extends Report
{
    public function definition(): ReportDefinition
    {
        return new ReportDefinition('cycle_performance', 'Production cycle performance', 'production', 'Every production cycle active in the period: dates, ledger population at the start and end of the period, deaths and mortality rate (livestock), planting baseline (crops).',
            [Permission::ProductionCycleView, Permission::RecordView], ['from', 'to', 'kind', 'status', 'production_cycle_id'], Feature::AdvancedReports);
    }

    public function run(FarmContext $ctx, ReportFilters $f): ReportResult
    {
        $cycles = ProductionCycle::ofFarm($ctx->farm)->with(['operation', 'livestock', 'crop.unitType'])
            ->where('start_date', '<=', $f->to)->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $f->from))
            ->when($f->kind, fn ($q, $v) => $q->where('kind', $v))->when($f->status, fn ($q, $v) => $q->where('status', $v))
            ->when($f->productionCycleId, fn ($q, $v) => $q->whereKey($v))->orderBy('start_date')->orderBy('reference')->get();
        $ids = $cycles->pluck('id')->all();

        $population = $ids === [] ? collect() : PopulationMovement::where('farm_id', $ctx->farm->id)->whereIn('production_cycle_id', $ids)->where('recorded_at', '<', $f->toTs())->toBase()
            ->selectRaw('production_cycle_id, SUM(CASE WHEN recorded_at < ? THEN quantity ELSE 0 END) as opening, SUM(quantity) as closing', [$f->fromTs()])->groupBy('production_cycle_id')->get()->keyBy('production_cycle_id');
        $deaths = $ids === [] ? collect() : OperationalRecord::where('farm_id', $ctx->farm->id)->whereIn('production_cycle_id', $ids)->where('type', 'mortality')
            ->where('recorded_at', '>=', $f->fromTs())->where('recorded_at', '<', $f->toTs())->whereDoesntHave('reversal')->toBase()
            ->selectRaw('production_cycle_id, -SUM(population_delta) as deaths')->groupBy('production_cycle_id')->pluck('deaths', 'production_cycle_id');

        $rows = [];
        $totalDeaths = 0;
        foreach ($cycles as $c) {
            $livestock = $c->kind === CycleKind::Livestock;
            $opening = $livestock ? (int) ($population[$c->id]->opening ?? 0) : null;
            $closing = $livestock ? (int) ($population[$c->id]->closing ?? 0) : null;
            $died = $livestock ? (int) ($deaths[$c->id] ?? 0) : null;
            $base = $livestock ? ($opening > 0 ? $opening : (int) $c->livestock?->initial_population) : 0;
            $until = $c->end_date !== null && $c->end_date->toDateString() < $f->to ? $c->end_date->toDateString() : $f->to;
            $totalDeaths += $died ?? 0;
            $rows[] = [
                'reference' => $c->reference, 'name' => $c->name, 'kind' => $c->kind->value, 'operation' => $c->operation?->name, 'status' => $c->status->value,
                'start_date' => $c->start_date->toDateString(), 'end_date' => $c->end_date?->toDateString(),
                'days_in_production' => max(0, (int) $c->start_date->diffInDays(CarbonImmutable::parse($until, 'UTC'), false)),
                'initial_population' => $livestock ? (int) $c->livestock?->initial_population : null, 'opening_population' => $opening, 'closing_population' => $closing, 'deaths' => $died,
                'mortality_rate' => $livestock ? ($base > 0 ? Decimal::trim(Decimal::round(Decimal::div(Decimal::mul((string) $died, '100'), (string) $base), 2)) : '0') : null,
                'planting_units' => $c->kind === CycleKind::Crop ? (int) $c->crop?->initial_planting_units : null, 'planting_unit' => $c->kind === CycleKind::Crop ? $c->crop?->unitType?->name : null,
            ];
        }

        return new ReportResult([
            ReportResult::col('reference', 'Reference'), ReportResult::col('name', 'Cycle'), ReportResult::col('kind', 'Kind'), ReportResult::col('operation', 'Operation'), ReportResult::col('status', 'Status'),
            ReportResult::col('start_date', 'Start', 'date'), ReportResult::col('end_date', 'End', 'date'), ReportResult::col('days_in_production', 'Days in production', 'integer'),
            ReportResult::col('initial_population', 'Initial head', 'integer'), ReportResult::col('opening_population', 'Head at period start', 'integer'), ReportResult::col('closing_population', 'Head at period end', 'integer'),
            ReportResult::col('deaths', 'Deaths in period', 'integer'), ReportResult::col('mortality_rate', 'Mortality rate %', 'percent'),
            ReportResult::col('planting_units', 'Planting units', 'integer'), ReportResult::col('planting_unit', 'Planting unit'),
        ], $rows, ['cycles' => count($rows), 'active_cycles' => $cycles->filter(fn ($c) => $c->status->value === 'active')->count(), 'deaths' => $totalDeaths]);
    }
}
