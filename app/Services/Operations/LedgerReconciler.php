<?php

namespace App\Services\Operations;

use App\Models\Farm;
use App\Models\ProductionCycle;
use App\Services\Production\CycleService;
use App\Support\Api\ApiHttpException;
use Illuminate\Support\Facades\DB;

/** Console-only diagnostics. Never repairs immutable history or stores a derived balance. */
class LedgerReconciler
{
    /** @return list<array{check: string, count: int, sample_ids: list<string>}> */
    public function forFarm(Farm $farm): array
    {
        // Canonical writers use this lock; all checks see a consistent farm without racing a multi-ledger write.
        return DB::transaction(function () use ($farm) {
            Farm::whereKey($farm->id)->lockForUpdate()->firstOrFail();
            $issues = [];
            foreach (ProductionCycle::ofFarm($farm)->with(['livestock', 'crop'])->lazyById(100) as $cycle) {
                try {
                    app(CycleService::class)->reconcile($cycle);
                } catch (ApiHttpException $e) {
                    $issues[] = ['check' => $e->errorCode, 'count' => 1, 'sample_ids' => [$cycle->id]];
                }
            }
            foreach ($this->checks() as $name => $sql) {
                // Return counts plus at most ten diagnostic ids, never full ledger contents.
                $count = (int) DB::selectOne("SELECT COUNT(*) AS n FROM ($sql) discrepancies", [$farm->id])->n;
                if ($count > 0) {
                    $ids = array_map(fn ($row) => (string) $row->id, DB::select("$sql LIMIT 10", [$farm->id]));
                    $issues[] = ['check' => $name, 'count' => $count, 'sample_ids' => $ids];
                }
            }

            return $issues;
        });
    }

    private function checks(): array
    {
        return [
            'record_stock_mismatch' => "SELECT r.id FROM operational_records r LEFT JOIN inventory_movements m ON m.operational_record_id = r.id WHERE r.farm_id = ? AND r.reverses_record_id IS NULL AND JSON_EXTRACT(r.details, '$.inventory.item_id') IS NOT NULL AND (m.id IS NULL OR m.farm_id <> r.farm_id OR m.inventory_item_id <> JSON_UNQUOTE(JSON_EXTRACT(r.details, '$.inventory.item_id')) OR ABS(m.quantity_delta) <> CAST(JSON_UNQUOTE(JSON_EXTRACT(r.measurement, '$.normalized.quantity')) AS DECIMAL(24,6)))",
            'purchase_expense_mismatch' => "SELECT p.id FROM purchases p LEFT JOIN finance_transactions t ON t.source_type = 'purchase' AND t.source_id = p.id AND t.entry_type = 'entry' AND NOT EXISTS (SELECT 1 FROM finance_transactions rv WHERE rv.reverses_transaction_id = t.id) WHERE p.farm_id = ? GROUP BY p.id, p.total_amount, p.status, p.records_expense HAVING (p.status = 'active' AND p.records_expense = 1 AND COALESCE(SUM(t.amount), 0) <> p.total_amount) OR (p.status = 'cancelled' AND COUNT(t.id) > 0)",
            'health_stock_mismatch' => 'SELECT h.id FROM health_record_medicines h LEFT JOIN inventory_movements m ON m.health_record_medicine_id = h.id WHERE h.farm_id = ? AND (m.id IS NULL OR m.farm_id <> h.farm_id OR m.inventory_item_id <> h.inventory_item_id OR m.quantity_delta <> -h.quantity_used)',
            'breeding_population_mismatch' => "SELECT b.id FROM breeding_outcomes b LEFT JOIN operational_records r ON r.id = b.operational_record_id WHERE b.farm_id = ? AND b.kind = 'outcome' AND b.live_count > 0 AND (r.id IS NULL OR r.farm_id <> b.farm_id OR r.production_cycle_id <> b.production_cycle_id OR r.population_delta <> b.live_count)",
            'stock_negative_history' => 'SELECT id FROM (SELECT id, SUM(quantity_delta) OVER (PARTITION BY inventory_item_id, storage_location_id, inventory_lot_id ORDER BY recorded_at, id ROWS UNBOUNDED PRECEDING) AS balance FROM inventory_movements WHERE farm_id = ?) history WHERE balance < 0',
            'stock_reversal_mismatch' => 'SELECT m.id FROM inventory_movements m LEFT JOIN inventory_movements o ON o.id = m.reverses_movement_id WHERE m.farm_id = ? AND m.reverses_movement_id IS NOT NULL AND (o.id IS NULL OR o.farm_id <> m.farm_id OR o.inventory_item_id <> m.inventory_item_id OR o.storage_location_id <> m.storage_location_id OR NOT (o.inventory_lot_id <=> m.inventory_lot_id) OR o.quantity_delta + m.quantity_delta <> 0)',
            'purchase_stock_mismatch' => "SELECT p.id FROM purchase_items p LEFT JOIN inventory_movements m ON m.purchase_item_id = p.id WHERE p.farm_id = ? AND p.kind = 'stock' AND (m.id IS NULL OR m.farm_id <> p.farm_id OR m.inventory_item_id <> p.inventory_item_id OR m.quantity_delta <> p.quantity)",
            'sale_stock_mismatch' => "SELECT s.id FROM sale_items s LEFT JOIN inventory_movements m ON m.sale_item_id = s.id WHERE s.farm_id = ? AND s.kind = 'stock' AND (m.id IS NULL OR m.farm_id <> s.farm_id OR m.inventory_item_id <> s.inventory_item_id OR m.quantity_delta <> -s.quantity)",
            'sale_population_mismatch' => "SELECT s.id FROM sale_items s LEFT JOIN operational_records r ON r.id = s.operational_record_id WHERE s.farm_id = ? AND s.kind = 'livestock' AND (r.id IS NULL OR r.farm_id <> s.farm_id OR r.production_cycle_id <> s.production_cycle_id OR r.population_delta <> -s.head_count)",
            'finance_reversal_mismatch' => "SELECT r.id FROM finance_transactions r LEFT JOIN finance_transactions o ON o.id = r.reverses_transaction_id WHERE r.farm_id = ? AND r.entry_type = 'reversal' AND (o.id IS NULL OR o.entry_type <> 'entry' OR o.farm_id <> r.farm_id OR o.amount <> r.amount OR o.currency <> r.currency OR o.direction <> r.direction OR o.finance_category_id <> r.finance_category_id)",
            'duplicate_live_finance_source' => "SELECT MIN(t.id) AS id FROM finance_transactions t WHERE t.farm_id = ? AND t.source_type IS NOT NULL AND t.entry_type = 'entry' AND NOT EXISTS (SELECT 1 FROM finance_transactions r WHERE r.reverses_transaction_id = t.id) GROUP BY t.source_type, t.source_id HAVING COUNT(*) > 1",
            'payment_finance_mismatch' => "SELECT p.id FROM payments p LEFT JOIN finance_transactions t ON t.id = p.finance_transaction_id WHERE p.farm_id = ? AND (t.id IS NULL OR t.farm_id <> p.farm_id OR t.amount <> p.amount OR t.currency <> p.currency OR t.direction <> 'income' OR t.source_type <> 'payment' OR (p.entry_type = 'payment' AND (t.entry_type <> 'entry' OR t.source_id <> p.id)) OR (p.entry_type = 'reversal' AND (t.entry_type <> 'reversal' OR t.source_id <> p.reverses_payment_id)))",
            'payment_reversal_mismatch' => "SELECT p.id FROM payments p LEFT JOIN payments o ON o.id = p.reverses_payment_id LEFT JOIN finance_transactions t ON t.id = p.finance_transaction_id WHERE p.farm_id = ? AND p.entry_type = 'reversal' AND (o.id IS NULL OR o.entry_type <> 'payment' OR o.farm_id <> p.farm_id OR o.invoice_id <> p.invoice_id OR o.amount <> p.amount OR o.currency <> p.currency OR NOT (t.reverses_transaction_id <=> o.finance_transaction_id))",
            'invoice_balance_invalid' => "SELECT i.id FROM invoices i LEFT JOIN payments p ON p.invoice_id = i.id WHERE i.farm_id = ? GROUP BY i.id, i.total_amount, i.status HAVING SUM(CASE WHEN p.entry_type = 'payment' THEN p.amount WHEN p.entry_type = 'reversal' THEN -p.amount ELSE 0 END) > i.total_amount OR SUM(CASE WHEN p.entry_type = 'payment' THEN p.amount WHEN p.entry_type = 'reversal' THEN -p.amount ELSE 0 END) < 0 OR (i.status = 'void' AND SUM(CASE WHEN p.entry_type = 'payment' THEN p.amount WHEN p.entry_type = 'reversal' THEN -p.amount ELSE 0 END) <> 0)",
            'sale_total_mismatch' => 'SELECT s.id FROM sales s LEFT JOIN sale_items l ON l.sale_id = s.id WHERE s.farm_id = ? GROUP BY s.id, s.total_amount HAVING COALESCE(SUM(l.amount), 0) <> s.total_amount',
            'purchase_total_mismatch' => 'SELECT p.id FROM purchases p LEFT JOIN purchase_items l ON l.purchase_id = p.id WHERE p.farm_id = ? GROUP BY p.id, p.total_amount HAVING COALESCE(SUM(l.amount), 0) <> p.total_amount',
            'invoice_total_mismatch' => 'SELECT i.id FROM invoices i LEFT JOIN invoice_items l ON l.invoice_id = i.id WHERE i.farm_id = ? GROUP BY i.id, i.total_amount HAVING COALESCE(SUM(l.amount), 0) <> i.total_amount',
        ];
    }
}
