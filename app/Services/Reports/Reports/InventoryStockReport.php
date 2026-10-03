<?php

namespace App\Services\Reports\Reports;

use App\Enums\Permission;
use App\Models\InventoryItem;
use App\Services\Inventory\StockLedger;
use App\Services\Reports\Report;
use App\Services\Reports\ReportDefinition;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use App\Support\Access\FarmContext;
use App\Support\Measurement\Decimal;

/** Stock balance per item as of a farm-local day, derived from the movement ledger (there is no stored balance). Quantities of different items are never summed. */
class InventoryStockReport extends Report
{
    public function __construct(private StockLedger $ledger) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition('inventory_stock', 'Stock on hand', 'inventory', 'Balance per inventory item at the end of the as-of day, summed from the stock movement ledger, with low-stock flags.',
            [Permission::InventoryView], ['as_of']);
    }

    public function run(FarmContext $ctx, ReportFilters $f): ReportResult
    {
        $asOf = $f->asOfTs();
        $items = InventoryItem::ofFarm($ctx->farm)->with('stockUnit.dimension')
            ->selectRaw('inventory_items.*, (select COALESCE(SUM(m.quantity_delta), 0) from inventory_movements m where m.inventory_item_id = inventory_items.id and m.recorded_at < ?) as stock_total', [$asOf])
            ->orderBy('normalized_name')->orderBy('id')->get();

        $rows = [];
        $low = 0;
        $out = 0;
        foreach ($items as $item) {
            $canonical = Decimal::trim((string) $item->stock_total);
            if (! $item->is_active && Decimal::isZero($canonical)) {
                continue;
            }
            $shown = $this->ledger->display($item, $canonical);
            $threshold = $item->low_stock_threshold !== null ? $this->ledger->display($item, Decimal::trim((string) $item->low_stock_threshold)) : null;
            $isLow = $threshold !== null && Decimal::cmp($canonical, Decimal::trim((string) $item->low_stock_threshold)) <= 0;
            $isEmpty = Decimal::cmp($canonical, '0') <= 0;
            $low += $isLow ? 1 : 0;
            $out += $isEmpty ? 1 : 0;
            $rows[] = [
                'item' => $item->name, 'category' => $item->category instanceof \BackedEnum ? $item->category->value : (string) $item->category, 'unit' => $shown['unit'], 'quantity' => $shown['quantity'],
                'low_stock_threshold' => $threshold['quantity'] ?? null, 'low_stock' => $isLow ? 'yes' : 'no', 'status' => $item->is_active ? 'active' : 'inactive',
            ];
        }

        return new ReportResult([
            ReportResult::col('item', 'Item'), ReportResult::col('category', 'Category'), ReportResult::col('unit', 'Unit'), ReportResult::col('quantity', 'Quantity on hand', 'decimal'),
            ReportResult::col('low_stock_threshold', 'Low-stock threshold', 'decimal'), ReportResult::col('low_stock', 'Low stock'), ReportResult::col('status', 'Status'),
        ], $rows, ['items' => count($rows), 'low_stock_items' => $low, 'empty_items' => $out], ['Each row is in its own item unit; quantities are not totalled across items.']);
    }
}
