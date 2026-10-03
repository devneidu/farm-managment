<?php

namespace App\Services\Reports\Reports;

use App\Enums\Permission;
use App\Models\InventoryItem;
use App\Models\ProductionCycle;
use App\Services\Inventory\StockLedger;
use App\Services\Reports\Report;
use App\Services\Reports\ReportDefinition;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use App\Support\Access\FarmContext;
use App\Support\Measurement\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Stock consumed (feed, crop inputs, planting material, medicine) per item, cycle and origin, from stock-out movements with reason
 * "use". A reversal movement is netted against the movement it reverses. Quantities are shown in each item's own unit and are never
 * added across items.
 */
class InputConsumptionReport extends Report
{
    private const ORIGINS = ['records' => 'Feed and crop inputs', 'medicine' => 'Medicine', 'other' => 'Other use'];

    public function __construct(private StockLedger $ledger) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition('input_consumption', 'Feed and input consumption', 'inventory', 'Stock used per item and cycle in the period (feed, fertilizer, pesticide, planting material, medicine), net of reversals.',
            [Permission::InventoryView], ['from', 'to', 'production_cycle_id']);
    }

    public function run(FarmContext $ctx, ReportFilters $f): ReportResult
    {
        $bindings = [$ctx->farm->id, $f->fromTs(), $f->toTs()];
        $cycleSql = '';
        if ($f->productionCycleId) {
            $cycleSql = ' AND COALESCE(rec.production_cycle_id, hr.production_cycle_id) = ?';
            $bindings[] = $f->productionCycleId;
        }
        $data = DB::select('SELECT m.inventory_item_id, COALESCE(rec.production_cycle_id, hr.production_cycle_id) as cycle_id, '
            ."CASE WHEN COALESCE(m.operational_record_id, o.operational_record_id) IS NOT NULL THEN 'records' WHEN COALESCE(m.health_record_id, o.health_record_id) IS NOT NULL THEN 'medicine' ELSE 'other' END as origin, "
            .'-SUM(m.quantity_delta) as quantity FROM inventory_movements m LEFT JOIN inventory_movements o ON o.id = m.reverses_movement_id '
            .'LEFT JOIN operational_records rec ON rec.id = COALESCE(m.operational_record_id, o.operational_record_id) LEFT JOIN health_records hr ON hr.id = COALESCE(m.health_record_id, o.health_record_id) '
            ."WHERE m.farm_id = ? AND m.recorded_at >= ? AND m.recorded_at < ? AND COALESCE(o.type, m.type) = 'stock_out' AND COALESCE(o.reason, m.reason) = 'use'$cycleSql "
            .'GROUP BY m.inventory_item_id, COALESCE(rec.production_cycle_id, hr.production_cycle_id), origin', $bindings);

        $items = InventoryItem::ofFarm($ctx->farm)->with('stockUnit.dimension')->whereIn('id', array_unique(array_column($data, 'inventory_item_id')))->get()->keyBy('id');
        $cycles = ProductionCycle::ofFarm($ctx->farm)->whereIn('id', array_filter(array_unique(array_column($data, 'cycle_id'))))->get()->keyBy('id');
        $rows = [];
        foreach ($data as $d) {
            $quantity = Decimal::trim((string) $d->quantity);
            if (Decimal::isZero($quantity)) {
                continue;
            }
            $item = $items[$d->inventory_item_id];
            $shown = $this->ledger->display($item, $quantity);
            $rows[] = ['item' => $item->name, 'category' => $item->category instanceof \BackedEnum ? $item->category->value : (string) $item->category, 'cycle' => $cycles[$d->cycle_id]->reference ?? null,
                'origin' => self::ORIGINS[$d->origin], 'unit' => $shown['unit'], 'quantity' => $shown['quantity']];
        }
        usort($rows, fn ($a, $b) => [$a['item'], (string) $a['cycle'], $a['origin']] <=> [$b['item'], (string) $b['cycle'], $b['origin']]);

        return new ReportResult([
            ReportResult::col('item', 'Item'), ReportResult::col('category', 'Category'), ReportResult::col('cycle', 'Cycle'), ReportResult::col('origin', 'Used for'),
            ReportResult::col('unit', 'Unit'), ReportResult::col('quantity', 'Quantity used', 'decimal'),
        ], $rows, ['rows' => count($rows)], ['Quantities are in each item unit and are not totalled across items.']);
    }
}
