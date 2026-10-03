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

/** Egg and milk output per cycle, summed only within one unit (pieces are never added to litres). */
class ProductionOutputReport extends Report
{
    public const TYPES = ['egg_collection' => 'Eggs', 'milk' => 'Milk'];

    public function definition(): ReportDefinition
    {
        return new ReportDefinition('production_output', 'Egg and milk output', 'production', 'Egg collections and milk yield per cycle in the period, in normalised units; reversed collections are excluded.',
            [Permission::RecordView, Permission::ProductionCycleView], ['from', 'to', 'production_cycle_id'], null, 'livestock');
    }

    public function run(FarmContext $ctx, ReportFilters $f): ReportResult
    {
        $unit = $this->unit();
        $bindings = [$ctx->farm->id, $f->fromTs(), $f->toTs()];
        $cycleSql = '';
        if ($f->productionCycleId) {
            $cycleSql = ' AND r.production_cycle_id = ?';
            $bindings[] = $f->productionCycleId;
        }
        $data = DB::select("SELECT r.production_cycle_id, r.type, $unit as unit, COUNT(*) as records, SUM(".$this->qty().') as quantity FROM operational_records r '
            ."WHERE r.farm_id = ? AND r.type IN ('egg_collection', 'milk') AND r.recorded_at >= ? AND r.recorded_at < ?$cycleSql AND ".$this->notReversed('operational_records', 'r')
            ." GROUP BY r.production_cycle_id, r.type, $unit", $bindings);

        $cycles = ProductionCycle::ofFarm($ctx->farm)->whereIn('id', array_unique(array_column($data, 'production_cycle_id')))->get()->keyBy('id');
        usort($data, fn ($a, $b) => [$cycles[$a->production_cycle_id]->reference, $a->type, $a->unit] <=> [$cycles[$b->production_cycle_id]->reference, $b->type, $b->unit]);
        $rows = [];
        $totals = [];
        foreach ($data as $d) {
            $c = $cycles[$d->production_cycle_id];
            $quantity = Decimal::trim((string) $d->quantity);
            $rows[] = ['reference' => $c->reference, 'name' => $c->name, 'product' => self::TYPES[$d->type], 'unit' => $d->unit, 'records' => (int) $d->records, 'quantity' => $quantity];
            $key = self::TYPES[$d->type].' ('.$d->unit.')';
            $totals[$key] = Decimal::add($totals[$key] ?? '0', $quantity);
        }
        ksort($totals);

        return new ReportResult([
            ReportResult::col('reference', 'Reference'), ReportResult::col('name', 'Cycle'), ReportResult::col('product', 'Product'), ReportResult::col('unit', 'Unit'),
            ReportResult::col('records', 'Records', 'integer'), ReportResult::col('quantity', 'Quantity', 'decimal'),
        ], $rows, ['totals_by_product_unit' => array_map(fn ($v) => Decimal::trim($v), $totals)]);
    }
}
