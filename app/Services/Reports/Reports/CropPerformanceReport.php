<?php

namespace App\Services\Reports\Reports;

use App\Enums\Permission;
use App\Models\ProductionCycle;
use App\Services\Reports\Report;
use App\Services\Reports\ReportDefinition;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use App\Support\Access\FarmContext;
use App\Support\Measurement\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Crop projects: planting units planted and lost in the period, the latest establishment check, and harvest output by unit. Planting
 * units, area and harvested weight are separate measurements and are never combined; planting-unit totals are not summed across
 * projects because a "heap" and a "stand" are different units.
 */
class CropPerformanceReport extends Report
{
    public function definition(): ReportDefinition
    {
        return new ReportDefinition('crop_performance', 'Crop establishment, loss and harvest', 'crops', 'Per crop project: planting units planted and lost, latest establishment survival and harvest output by unit; reversed records are excluded.',
            [Permission::RecordView, Permission::ProductionCycleView], ['from', 'to', 'status', 'production_cycle_id'], null, 'crop');
    }

    public function run(FarmContext $ctx, ReportFilters $f): ReportResult
    {
        $cycles = ProductionCycle::ofFarm($ctx->farm)->where('kind', 'crop')->with(['crop.cropType', 'crop.unitType'])
            ->where('start_date', '<=', $f->to)->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $f->from))
            ->when($f->status, fn ($q, $v) => $q->where('status', $v))->when($f->productionCycleId, fn ($q, $v) => $q->whereKey($v))
            ->orderBy('start_date')->orderBy('reference')->get();
        $ids = $cycles->pluck('id')->all();
        $in = implode(',', array_fill(0, max(1, count($ids)), '?'));
        $ids = $ids === [] ? ['-'] : $ids;
        $notReversed = $this->notReversed('operational_records', 'r');

        $flows = collect(DB::select('SELECT r.production_cycle_id, '
            ."COALESCE(SUM(CASE WHEN r.type = 'planting' THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(r.details, '\$.units_planted')) AS UNSIGNED) ELSE 0 END), 0) as planted, "
            ."COALESCE(SUM(CASE WHEN r.type = 'crop_loss' THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(r.details, '\$.units_lost')) AS UNSIGNED) ELSE 0 END), 0) as lost "
            ."FROM operational_records r WHERE r.farm_id = ? AND r.type IN ('planting', 'crop_loss') AND r.recorded_at >= ? AND r.recorded_at < ? AND r.production_cycle_id IN ($in) AND $notReversed GROUP BY r.production_cycle_id",
            [$ctx->farm->id, $f->fromTs(), $f->toTs(), ...$ids]))->keyBy('production_cycle_id');

        $harvest = [];
        foreach (DB::select('SELECT r.production_cycle_id, '.$this->unit().' as unit, SUM('.$this->qty().") as quantity FROM operational_records r WHERE r.farm_id = ? AND r.type = 'crop_harvest' AND r.recorded_at >= ? AND r.recorded_at < ? "
            ."AND r.production_cycle_id IN ($in) AND $notReversed GROUP BY r.production_cycle_id, ".$this->unit(), [$ctx->farm->id, $f->fromTs(), $f->toTs(), ...$ids]) as $h) {
            $harvest[$h->production_cycle_id][$h->unit] = Decimal::trim((string) $h->quantity);
        }

        // The latest non-reversed establishment check recorded up to the end of the period (a state, not a flow).
        $checks = collect(DB::select("SELECT t.production_cycle_id, t.established, t.survival FROM (SELECT r.production_cycle_id, JSON_UNQUOTE(JSON_EXTRACT(r.details, '\$.established_units')) as established, "
            ."JSON_UNQUOTE(JSON_EXTRACT(r.details, '\$.survival_percent')) as survival, ROW_NUMBER() OVER (PARTITION BY r.production_cycle_id ORDER BY r.recorded_at DESC, r.id DESC) as rn "
            ."FROM operational_records r WHERE r.farm_id = ? AND r.type = 'establishment_check' AND r.recorded_at < ? AND r.production_cycle_id IN ($in) AND $notReversed) t WHERE t.rn = 1",
            [$ctx->farm->id, $f->toTs(), ...$ids]))->keyBy('production_cycle_id');

        $rows = [];
        $harvestTotals = [];
        foreach ($cycles as $c) {
            $base = [
                'reference' => $c->reference, 'name' => $c->name, 'crop' => $c->crop?->cropType?->name, 'status' => $c->status->value, 'start_date' => $c->start_date->toDateString(),
                'planting_unit' => $c->crop?->unitType?->name, 'initial_planting_units' => (int) $c->crop?->initial_planting_units,
                'units_planted' => (int) ($flows[$c->id]->planted ?? 0), 'units_lost' => (int) ($flows[$c->id]->lost ?? 0),
                'established_units' => isset($checks[$c->id]) ? (int) $checks[$c->id]->established : null, 'survival_percent' => isset($checks[$c->id]) ? Decimal::trim((string) $checks[$c->id]->survival) : null,
            ];
            $units = $harvest[$c->id] ?? [];
            ksort($units);
            if ($units === []) {
                $rows[] = $base + ['harvest_quantity' => null, 'harvest_unit' => null];

                continue;
            }
            foreach ($units as $unit => $quantity) {
                $rows[] = $base + ['harvest_quantity' => $quantity, 'harvest_unit' => $unit];
                $harvestTotals[$unit] = Decimal::add($harvestTotals[$unit] ?? '0', $quantity);
            }
        }
        ksort($harvestTotals);

        return new ReportResult([
            ReportResult::col('reference', 'Reference'), ReportResult::col('name', 'Project'), ReportResult::col('crop', 'Crop'), ReportResult::col('status', 'Status'), ReportResult::col('start_date', 'Planting date', 'date'),
            ReportResult::col('planting_unit', 'Planting unit'), ReportResult::col('initial_planting_units', 'Planned planting units', 'integer'), ReportResult::col('units_planted', 'Units planted in period', 'integer'),
            ReportResult::col('units_lost', 'Units lost in period', 'integer'), ReportResult::col('established_units', 'Established units (latest check)', 'integer'), ReportResult::col('survival_percent', 'Survival % (latest check)', 'percent'),
            ReportResult::col('harvest_quantity', 'Harvest', 'decimal'), ReportResult::col('harvest_unit', 'Harvest unit'),
        ], $rows, ['projects' => $cycles->count(), 'harvest_totals_by_unit' => array_map(fn ($v) => Decimal::trim($v), $harvestTotals)],
            ['Planting units differ per project (heap, stand, ...) and are not summed; harvest is only totalled within one unit.']);
    }
}
