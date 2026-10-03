<?php

namespace App\Services\Audit;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\ProductionCycle;
use App\Models\User;
use App\Services\Dashboard\DashboardClock;
use App\Support\Access\FarmContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The audit trail is a READ MODEL. Who did what, when and to which resource is already recorded by the authoritative append-only tables
 * (operational and health records, inventory movements, finance, purchases, sales, invoices, payments, breeding outcomes, task completion,
 * cycle events, subscription events) with their actor, request id and reversal links. This class unions those rows into one timeline and adds
 * the audit_logs table for the privileged actions nothing else records (team, farm settings, exports). No history is copied, and each source
 * is included only when the viewer may see that module. Money amounts and record contents are deliberately never part of an entry.
 */
class AuditQueries
{
    /** Common column list, in order, for every branch of the union. */
    private const COLUMNS = ['id', 'action', 'resource_type', 'resource_id', 'resource_label', 'actor_id', 'performed_at', 'recorded_at', 'request_id', 'ip_address', 'production_cycle_id', 'related_id'];

    /** @param  array{from?: string, to?: string, actor_id?: string, action?: string, resource_type?: string, resource_id?: string, request_id?: string, production_cycle_id?: string, per_page?: int}  $f */
    public function list(FarmContext $ctx, array $f): LengthAwarePaginator
    {
        $ctx->authorize(Permission::AuditView);
        $farm = $ctx->farm->id;
        $clock = new DashboardClock($ctx->farm);
        $lower = isset($f['from']) ? $clock->dayStartSql($f['from']) : null;
        $upper = isset($f['to']) ? $clock->dayStartSql($clock->addDays($f['to'], 1)) : null;

        $branches = [];
        $add = function (string $table, string $alias, string $select, callable $where, string $timeColumn) use (&$branches, $farm, $lower, $upper) {
            $q = DB::table("$table as $alias")->selectRaw($select)->where("$alias.farm_id", $farm);
            $where($q);
            if ($lower !== null) {
                $q->where("$alias.$timeColumn", '>=', $lower);
            }
            if ($upper !== null) {
                $q->where("$alias.$timeColumn", '<', $upper);
            }
            $branches[] = $q;
        };
        $none = fn () => null;

        $add('audit_logs', 'a', "CONCAT('audit:', a.id) as id, a.action as action, a.resource_type as resource_type, a.resource_id as resource_id, a.resource_label as resource_label, a.actor_id as actor_id, a.created_at as performed_at, NULL as recorded_at, a.request_id as request_id, a.ip_address as ip_address, NULL as production_cycle_id, NULL as related_id", $none, 'created_at');

        foreach ([['operational_records', 'r', 'operational_record', 'record', Permission::RecordView], ['health_records', 'h', 'health_record', 'health_record', Permission::HealthView]] as [$table, $a, $type, $prefix, $permission]) {
            if ($ctx->can($permission)) {
                $add($table, $a, "CONCAT('$prefix:', $a.id), CASE WHEN $a.reverses_record_id IS NOT NULL THEN '$prefix.reversed' WHEN $a.corrects_record_id IS NOT NULL THEN '$prefix.corrected' ELSE '$prefix.created' END, "
                    ."'$type', COALESCE($a.reverses_record_id, $a.id), CASE WHEN $a.reverses_record_id IS NOT NULL THEN (select o.type from $table o where o.id = $a.reverses_record_id) ELSE $a.type END, "
                    ."$a.created_by, $a.created_at, $a.recorded_at, $a.request_id, NULL, $a.production_cycle_id, CASE WHEN $a.reverses_record_id IS NOT NULL THEN $a.id ELSE $a.corrects_record_id END", $none, 'created_at');
            }
        }
        if ($ctx->can(Permission::InventoryView)) {
            $add('inventory_movements', 'm', "CONCAT('inventory:', m.id), CASE m.type WHEN 'stock_in' THEN 'inventory.stock_in' WHEN 'stock_out' THEN 'inventory.stock_out' WHEN 'adjustment' THEN 'inventory.adjusted' WHEN 'transfer_out' THEN 'inventory.transferred' ELSE 'inventory.reversed' END, "
                ."'inventory_movement', COALESCE(m.reverses_movement_id, m.id), (select i.name from inventory_items i where i.id = m.inventory_item_id), m.created_by, m.created_at, m.recorded_at, m.request_id, NULL, NULL, CASE WHEN m.reverses_movement_id IS NOT NULL THEN m.id ELSE NULL END",
                fn ($q) => $q->whereNull('m.operational_record_id')->whereNull('m.health_record_id')->whereNull('m.purchase_id')->whereNull('m.sale_id')->where('m.type', '<>', 'transfer_in')
                    ->whereRaw("(m.type <> 'reversal' OR NOT EXISTS (select 1 from inventory_movements o where o.id = m.reverses_movement_id AND (o.operational_record_id IS NOT NULL OR o.health_record_id IS NOT NULL OR o.purchase_id IS NOT NULL OR o.sale_id IS NOT NULL)))"), 'created_at');
        }
        if ($ctx->can(Permission::FinanceView)) {
            $add('finance_transactions', 'f', "CONCAT('finance:', f.id), CASE WHEN f.entry_type = 'reversal' THEN 'finance.reversed' WHEN f.corrects_transaction_id IS NOT NULL THEN 'finance.corrected' ELSE 'finance.recorded' END, "
                ."'finance_transaction', COALESCE(f.reverses_transaction_id, f.id), f.reference, f.created_by, f.created_at, f.recorded_at, f.request_id, NULL, f.production_cycle_id, CASE WHEN f.reverses_transaction_id IS NOT NULL THEN f.id ELSE f.corrects_transaction_id END",
                fn ($q) => $q->whereNull('f.source_type'), 'created_at');
        }
        foreach ([['purchases', 'p', 'purchase', Permission::PurchaseView], ['sales', 's', 'sale', Permission::SaleView]] as [$table, $a, $type, $permission]) {
            if ($ctx->can($permission)) {
                $add($table, $a, "CONCAT('$type:', $a.id), '$type.created', '$type', $a.id, $a.reference, $a.created_by, $a.created_at, $a.recorded_at, $a.request_id, NULL, ".($type === 'purchase' ? "$a.production_cycle_id" : 'NULL').', NULL', $none, 'created_at');
                $add($table, $a, "CONCAT('$type:', $a.id, ':cancelled'), '$type.cancelled', '$type', $a.id, $a.reference, $a.cancelled_by, $a.cancelled_at, NULL, NULL, NULL, ".($type === 'purchase' ? "$a.production_cycle_id" : 'NULL').', NULL',
                    fn ($q) => $q->whereNotNull("$a.cancelled_at"), 'cancelled_at');
            }
        }
        if ($ctx->can(Permission::InvoiceView)) {
            $add('invoices', 'i', "CONCAT('invoice:', i.id), 'invoice.issued', 'invoice', i.id, i.reference, i.created_by, i.created_at, NULL, i.request_id, NULL, NULL, NULL", $none, 'created_at');
            $add('invoices', 'i', "CONCAT('invoice:', i.id, ':void'), 'invoice.voided', 'invoice', i.id, i.reference, i.voided_by, i.voided_at, NULL, NULL, NULL, NULL, NULL", fn ($q) => $q->whereNotNull('i.voided_at'), 'voided_at');
        }
        if ($ctx->can(Permission::PaymentView)) {
            $add('payments', 'y', "CONCAT('payment:', y.id), CASE WHEN y.entry_type = 'reversal' THEN 'payment.reversed' ELSE 'payment.recorded' END, 'payment', COALESCE(y.reverses_payment_id, y.id), y.reference, y.created_by, y.created_at, y.recorded_at, y.request_id, NULL, NULL, CASE WHEN y.reverses_payment_id IS NOT NULL THEN y.id ELSE NULL END", $none, 'created_at');
        }
        if ($ctx->can(Permission::BreedingView)) {
            $add('breeding_projects', 'b', "CONCAT('breeding_project:', b.id), 'breeding.project_started', 'breeding_project', b.id, b.reference, b.created_by, b.created_at, NULL, NULL, NULL, b.production_cycle_id, NULL", $none, 'created_at');
            $add('breeding_outcomes', 'o', "CONCAT('breeding_outcome:', o.id), CASE WHEN o.kind = 'reversal' THEN 'breeding.outcome_reversed' ELSE 'breeding.outcome_recorded' END, 'breeding_outcome', COALESCE(o.reverses_outcome_id, o.id), "
                .'(select p.reference from breeding_projects p where p.id = o.breeding_project_id), o.created_by, o.created_at, o.recorded_at, o.request_id, NULL, o.production_cycle_id, CASE WHEN o.reverses_outcome_id IS NOT NULL THEN o.id ELSE NULL END', $none, 'created_at');
        }
        if ($ctx->can(Permission::TaskManage)) {
            $add('tasks', 't', "CONCAT('task:', t.id, ':completed'), 'task.completed', 'task', t.id, t.reference, t.completed_by, t.completed_at, NULL, NULL, NULL, t.production_cycle_id, NULL", fn ($q) => $q->whereNotNull('t.completed_at'), 'completed_at');
        }
        if ($ctx->can(Permission::ProductionCycleView)) {
            $add('production_cycle_events', 'e', "CONCAT('cycle_event:', e.id), CONCAT('production_cycle.', e.action), 'production_cycle', e.production_cycle_id, (select c.reference from production_cycles c where c.id = e.production_cycle_id), e.actor_id, e.created_at, e.recorded_at, NULL, NULL, e.production_cycle_id, NULL", $none, 'created_at');
        }
        if ($ctx->can(Permission::SubscriptionView)) {
            $add('subscription_events', 'v', "CONCAT('subscription:', v.id), CONCAT('subscription.', v.type), 'subscription', v.subscription_id, NULL, v.actor_user_id, v.created_at, NULL, NULL, NULL, NULL, NULL", $none, 'created_at');
        }

        $union = array_shift($branches);
        foreach ($branches as $b) {
            $union->unionAll($b);
        }

        $query = DB::query()->fromSub($union, 'u')->select(self::COLUMNS);
        foreach (['actor_id', 'action', 'resource_type', 'request_id', 'production_cycle_id'] as $column) {
            if (isset($f[$column])) {
                $query->where($column, $f[$column]);
            }
        }
        if (isset($f['resource_id'])) {
            $query->where(fn ($w) => $w->where('resource_id', $f['resource_id'])->orWhere('related_id', $f['resource_id']));
        }
        $query->orderByDesc('performed_at')->orderByDesc('id');

        $page = $query->paginate($f['per_page'] ?? 50);

        // Two lookups for the whole page (actors and cycles) - no per-row queries.
        $rows = $page->getCollection();
        $users = User::whereIn('id', $rows->pluck('actor_id')->filter()->unique()->all())->pluck('name', 'id');
        $cycles = ProductionCycle::ofFarm($ctx->farm)->whereIn('id', $rows->pluck('production_cycle_id')->filter()->unique()->all())->get(['id', 'reference', 'name'])->keyBy('id');
        // The safe change summary exists only on audit-log entries (role from/to, changed settings, export format and filters).
        $changes = AuditLog::where('farm_id', $ctx->farm->id)->whereIn('id', $rows->filter(fn ($r) => str_starts_with($r->id, 'audit:'))->map(fn ($r) => substr($r->id, 6))->all())->pluck('changes', 'id');
        $page->setCollection($rows->map(fn ($r) => [
            'id' => $r->id, 'action' => $r->action,
            'resource' => ['type' => $r->resource_type, 'id' => $r->resource_id, 'label' => $r->resource_label],
            'actor' => $r->actor_id ? ['id' => $r->actor_id, 'name' => $users[$r->actor_id] ?? null] : null,
            'performed_at' => $r->performed_at ? CarbonImmutable::parse($r->performed_at, 'UTC')->toISOString() : null,
            'recorded_at' => $r->recorded_at ? CarbonImmutable::parse($r->recorded_at, 'UTC')->toISOString() : null,
            'changes' => str_starts_with($r->id, 'audit:') ? (object) ($changes[substr($r->id, 6)] ?? []) : null,
            'request_id' => $r->request_id, 'ip_address' => $r->ip_address, 'related_id' => $r->related_id,
            'production_cycle' => $r->production_cycle_id && isset($cycles[$r->production_cycle_id]) ? ['id' => $r->production_cycle_id, 'reference' => $cycles[$r->production_cycle_id]->reference, 'name' => $cycles[$r->production_cycle_id]->name] : null,
        ]));

        return $page;
    }
}
