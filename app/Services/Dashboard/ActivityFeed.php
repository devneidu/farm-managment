<?php

namespace App\Services\Dashboard;

use App\Enums\Permission;
use App\Models\FinanceTransaction;
use App\Models\HealthRecord;
use App\Models\OperationalRecord;
use App\Models\Payment;
use App\Models\ProductionCycle;
use App\Models\ProductionCycleEvent;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Task;
use App\Models\User;
use App\Support\Finance\Money;
use Illuminate\Support\Str;

/**
 * Recent activity is assembled live from the authoritative records - there is no activity table. Each source is included only when the
 * viewer holds its view permission (a Farm Worker never sees money, a Finance user never sees health notes they cannot open), and an
 * event that was reversed or cancelled is left out together with its correcting row, so the feed shows what stands, not noise.
 */
class ActivityFeed
{
    public const LIMIT = 15;

    /** Record types that are shown by their own module instead (sales, breeding) or are corrections. */
    private const HIDDEN_RECORD_TYPES = ['reversal', 'livestock_sale', 'breeding_outcome'];

    /** @return list<array<string, mixed>> newest first */
    public function recent(DashboardFacts $f, int $limit = self::LIMIT): array
    {
        $farm = $f->ctx->farm;
        $ctx = $f->ctx;
        $items = [];
        $add = function (string $kind, string $id, string $title, ?string $summary, $at, ?string $actor, ?string $cycle, array $subject) use (&$items) {
            $items[] = ['kind' => $kind, 'id' => $kind.':'.$id, 'title' => $title, 'summary' => $summary, 'occurred_at' => $at->toISOString(), 'actor_id' => $actor, 'production_cycle_id' => $cycle, 'subject' => $subject];
        };

        if ($ctx->can(Permission::RecordView)) {
            foreach (OperationalRecord::where('farm_id', $farm->id)->whereNotIn('type', self::HIDDEN_RECORD_TYPES)->whereDoesntHave('reversal')->orderByDesc('recorded_at')->orderByDesc('id')->limit($limit)->get() as $r) {
                $m = $r->measurement['normalized'] ?? null;
                $summary = $r->population_delta !== 0 ? abs($r->population_delta).' head' : ($m ? $m['quantity'].' '.$m['unit'] : null);
                $add('operational_record', $r->id, $this->label($r->type), $summary, $r->recorded_at, $r->created_by, $r->production_cycle_id, ['type' => 'operational_record', 'id' => $r->id, 'reference' => null]);
            }
        }
        if ($ctx->can(Permission::HealthView)) {
            foreach (HealthRecord::where('farm_id', $farm->id)->whereNull('reverses_record_id')->whereDoesntHave('reversal')->orderByDesc('recorded_at')->orderByDesc('id')->limit($limit)->get() as $r) {
                $add('health_record', $r->id, 'Health: '.$this->label($r->type), null, $r->recorded_at, $r->created_by, $r->production_cycle_id, ['type' => 'health_record', 'id' => $r->id, 'reference' => null]);
            }
        }
        if ($ctx->can(Permission::ProductionCycleView)) {
            foreach (ProductionCycleEvent::where('farm_id', $farm->id)->whereIn('action', ['created', 'closed', 'reopened'])->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get() as $e) {
                $add('production_cycle', $e->id, 'Production cycle '.$e->action, $e->reason, $e->recorded_at ?? $e->created_at, $e->actor_id, $e->production_cycle_id, ['type' => 'production_cycle', 'id' => $e->production_cycle_id, 'reference' => null]);
            }
        }
        if ($ctx->can(Permission::TaskView)) {
            foreach ($f->visibleTasks()->where('status', 'completed')->orderByDesc('completed_at')->orderByDesc('id')->limit($limit)->get() as $t) {
                /** @var Task $t */
                $add('task', $t->id, 'Task completed', $t->title, $t->completed_at, $t->completed_by, $t->production_cycle_id, ['type' => 'task', 'id' => $t->id, 'reference' => $t->reference]);
            }
        }
        if ($ctx->can(Permission::SaleView)) {
            foreach (Sale::where('farm_id', $farm->id)->where('status', Sale::ACTIVE)->orderByDesc('recorded_at')->orderByDesc('id')->limit($limit)->get() as $s) {
                $add('sale', $s->id, 'Sale '.$s->reference, Money::format((string) $s->total_amount).' '.$s->currency.($s->customer_name ? ' - '.$s->customer_name : ''), $s->recorded_at, $s->created_by, null, ['type' => 'sale', 'id' => $s->id, 'reference' => $s->reference]);
            }
        }
        if ($ctx->can(Permission::PaymentView)) {
            foreach (Payment::where('farm_id', $farm->id)->where('entry_type', Payment::PAYMENT)->whereDoesntHave('reversal')->orderByDesc('recorded_at')->orderByDesc('id')->limit($limit)->get() as $p) {
                $add('payment', $p->id, 'Payment '.$p->reference, Money::format((string) $p->amount).' '.$p->currency, $p->recorded_at, $p->created_by, null, ['type' => 'payment', 'id' => $p->id, 'reference' => $p->reference]);
            }
        }
        if ($ctx->can(Permission::PurchaseView)) {
            foreach (Purchase::where('farm_id', $farm->id)->where('status', Purchase::ACTIVE)->orderByDesc('recorded_at')->orderByDesc('id')->limit($limit)->get() as $p) {
                $add('purchase', $p->id, 'Purchase '.$p->reference, Money::format((string) $p->total_amount).' '.$p->currency, $p->recorded_at, $p->created_by, $p->production_cycle_id, ['type' => 'purchase', 'id' => $p->id, 'reference' => $p->reference]);
            }
        }
        if ($ctx->can(Permission::FinanceView)) {
            // Manually entered income/expense only: purchases, payments and sales already appear under their own module.
            foreach (FinanceTransaction::where('farm_id', $farm->id)->where('entry_type', FinanceTransaction::ENTRY)->whereNull('source_type')->whereDoesntHave('reversal')->orderByDesc('recorded_at')->orderByDesc('id')->limit($limit)->get() as $t) {
                $add('finance_transaction', $t->id, ucfirst($t->direction).' '.$t->reference, Money::format((string) $t->amount).' '.$t->currency.($t->description ? ' - '.$t->description : ''), $t->recorded_at, $t->created_by, $t->production_cycle_id, ['type' => 'finance_transaction', 'id' => $t->id, 'reference' => $t->reference]);
            }
        }

        usort($items, fn ($a, $b) => [$b['occurred_at'], $b['id']] <=> [$a['occurred_at'], $a['id']]);
        $items = array_slice($items, 0, $limit);

        // Two lookups for the whole page: actors and cycle names (no per-row queries).
        $users = User::whereIn('id', array_filter(array_unique(array_column($items, 'actor_id'))))->pluck('name', 'id');
        $cycles = ProductionCycle::ofFarm($farm)->whereIn('id', array_filter(array_unique(array_column($items, 'production_cycle_id'))))->get(['id', 'name', 'reference'])->keyBy('id');

        return array_map(function (array $i) use ($users, $cycles) {
            $cycle = $cycles[$i['production_cycle_id']] ?? null;
            $actor = $i['actor_id'];
            unset($i['actor_id']);

            return $i + ['actor' => $actor ? ['id' => $actor, 'name' => $users[$actor] ?? null] : null, 'production_cycle' => $cycle ? ['id' => $cycle->id, 'name' => $cycle->name, 'reference' => $cycle->reference] : null];
        }, $items);
    }

    private function label(string $type): string
    {
        return ucfirst(str_replace('_', ' ', Str::snake($type)));
    }
}
