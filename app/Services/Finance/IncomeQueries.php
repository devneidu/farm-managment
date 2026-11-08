<?php

namespace App\Services\Finance;

use App\Enums\Permission;
use App\Models\FinanceCategory;
use App\Models\Sale;
use App\Services\Inventory\StockLedger;
use App\Support\Access\FarmContext;
use App\Support\Finance\Money;
use App\Support\Measurement\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Income list: money coming in, one row per SALE plus one row per manual income ledger entry, in one paginated SQL UNION.
 *
 * - sale rows: every sale of the farm (also un-invoiced ones). amount_received = live payments on the sale's live invoice
 *   (payment rows themselves are never listed); balance = total - received (0 for a cancelled sale).
 * - other_income rows: finance_transactions with direction income, entry_type entry, not booked by a sale payment, and not reversed
 *   (reversed entries and the reversal rows are hidden). Received in full, balance 0.
 *
 * Permissions: finance.view sees both kinds plus the money totals. A user with only sale.view gets sale rows only and no totals.
 */
class IncomeQueries
{
    public function __construct(private StockLedger $ledger) {}

    /** Live payments per sale (a payment and the reversal that offsets it both drop out); only the live (issued) invoice counts. */
    private function paidPerSale(string $farmId): Builder
    {
        return DB::table('invoices as i')
            ->join('payments as p', fn ($j) => $j->on('p.invoice_id', '=', 'i.id')->where('p.entry_type', 'payment')
                ->whereRaw('NOT EXISTS (SELECT 1 FROM payments r WHERE r.reverses_payment_id = p.id)'))
            ->where('i.farm_id', $farmId)->where('i.status', 'issued')
            ->groupBy('i.sale_id')->selectRaw('i.sale_id, SUM(p.amount) as amount');
    }

    public function list(FarmContext $ctx, array $f): array
    {
        $full = $ctx->can(Permission::FinanceView);
        if (! $full) {
            $ctx->authorize(Permission::SaleView);
        }
        $farmId = $ctx->farm->id;
        $offset = CarbonImmutable::now($ctx->farm->timezone)->offset;
        $status = $f['money_status'] ?? null;
        $includeCancelled = (bool) ($f['include_cancelled'] ?? false);

        $sales = DB::table('sales as s')
            ->leftJoin('contacts as c', 'c.id', '=', 's.contact_id')
            ->leftJoinSub($this->paidPerSale($farmId), 'paid', 'paid.sale_id', '=', 's.id')
            ->where('s.farm_id', $farmId)
            ->selectRaw("'sale' as kind, s.id, s.reference, DATE(DATE_ADD(s.recorded_at, INTERVAL {$offset} SECOND)) as occurred_on, s.created_at, s.contact_id,
                COALESCE(c.name, s.customer_name) as contact_name, c.phone as contact_phone, s.finance_category_id as category_id, NULL as description,
                s.total_amount as total, COALESCE(paid.amount, 0) as amount_received,
                CASE WHEN s.status = 'cancelled' THEN 0 ELSE s.total_amount - COALESCE(paid.amount, 0) END as balance,
                CASE WHEN s.status = 'cancelled' THEN 'cancelled' WHEN COALESCE(paid.amount, 0) = 0 THEN 'not_paid'
                     WHEN COALESCE(paid.amount, 0) >= s.total_amount THEN 'paid' ELSE 'part_paid' END as money_status");
        $this->commonFilters($sales, $f, ['date' => "DATE(DATE_ADD(s.recorded_at, INTERVAL {$offset} SECOND))", 'contact' => 's.contact_id', 'category' => 's.finance_category_id', 'reference' => 's.reference', 'name' => 'COALESCE(c.name, s.customer_name)', 'phone' => 'c.phone']);
        if (isset($f['production_cycle_id'])) {
            $sales->whereExists(fn ($q) => $q->selectRaw('1')->from('sale_items as si')->whereColumn('si.sale_id', 's.id')->where('si.production_cycle_id', $f['production_cycle_id']));
        }
        if (! $includeCancelled) {
            $sales->where('s.status', '!=', 'cancelled');
        }
        if (in_array($status, ['paid', 'part_paid', 'not_paid'], true)) {
            $sales->whereRaw("(CASE WHEN s.status = 'cancelled' THEN 'cancelled' WHEN COALESCE(paid.amount, 0) = 0 THEN 'not_paid' WHEN COALESCE(paid.amount, 0) >= s.total_amount THEN 'paid' ELSE 'part_paid' END) = ?", [$status]);
        }

        if ($status === 'received') {
            $sales->whereRaw('1 = 0'); // "received" is the other-income status; no sale ever has it.
        }
        $query = $sales;
        // Other income: only for finance.view, and not when the filter asks for sale statuses only.
        if ($full && ($status === null || $status === 'received')) {
            $manual = DB::table('finance_transactions as t')
                ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
                ->where('t.farm_id', $farmId)->where('t.direction', 'income')->where('t.entry_type', 'entry')
                ->where(fn ($w) => $w->whereNull('t.source_type')->orWhere('t.source_type', '!=', 'payment'))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('finance_transactions as r')->whereColumn('r.reverses_transaction_id', 't.id'))
                ->selectRaw("'other_income' as kind, t.id, t.reference, t.occurred_on, t.created_at, t.contact_id, c.name as contact_name, c.phone as contact_phone,
                    t.finance_category_id as category_id, t.description, t.amount as total, t.amount as amount_received, 0 as balance, 'received' as money_status");
            $this->commonFilters($manual, $f, ['date' => 't.occurred_on', 'contact' => 't.contact_id', 'category' => 't.finance_category_id', 'reference' => 't.reference', 'name' => 'c.name', 'phone' => 'c.phone']);
            if (isset($f['production_cycle_id'])) {
                $manual->where('t.production_cycle_id', $f['production_cycle_id']);
            }
            $query = $sales->unionAll($manual);
        }

        $page = DB::query()->fromSub($query, 'income')
            ->orderByDesc('occurred_on')->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($f['per_page'] ?? 50);

        return ['page' => $page, 'rows' => $this->rows($ctx, $page), 'totals' => $full ? $this->totals($ctx, $f) : null];
    }

    /** @param array<string, string> $c SQL expression per filterable column of this branch */
    private function commonFilters(Builder $q, array $f, array $c): void
    {
        if (isset($f['from'])) {
            $q->whereRaw($c['date'].' >= ?', [$f['from']]);
        }
        if (isset($f['to'])) {
            $q->whereRaw($c['date'].' <= ?', [$f['to']]);
        }
        if (isset($f['contact_id'])) {
            $q->whereRaw($c['contact'].' = ?', [$f['contact_id']]);
        }
        if (isset($f['finance_category_id'])) {
            $q->whereRaw($c['category'].' = ?', [$f['finance_category_id']]);
        }
        if (! empty($f['search'])) {
            $term = '%'.addcslashes($f['search'], '%_\\').'%';
            $q->where(fn ($w) => $w->whereRaw($c['reference'].' LIKE ?', [$term])->orWhereRaw($c['name'].' LIKE ?', [$term])->orWhereRaw($c['phone'].' LIKE ?', [$term]));
        }
    }

    /**
     * meta.totals for the filtered date range (from / to; nothing else filters them):
     * - money_received: SUM over the money ledger (finance_transactions) of income, reversal rows negative, by occurred_on in the range.
     *   It is exactly /finance/summary totals.income for the same from/to (sale payments + manual income, net of reversals).
     * - still_owed: SUM of balance over every non-cancelled sale, un-invoiced ones included (they owe their whole total). Not date filtered: as of now.
     */
    private function totals(FarmContext $ctx, array $f): array
    {
        $received = DB::table('finance_transactions')->where('farm_id', $ctx->farm->id)->where('direction', 'income')
            ->when(isset($f['from']), fn ($q) => $q->where('occurred_on', '>=', $f['from']))
            ->when(isset($f['to']), fn ($q) => $q->where('occurred_on', '<=', $f['to']))
            ->selectRaw("SUM(CASE WHEN entry_type = 'reversal' THEN -amount ELSE amount END) as total")->value('total');
        $owed = DB::table('sales as s')->leftJoinSub($this->paidPerSale($ctx->farm->id), 'paid', 'paid.sale_id', '=', 's.id')
            ->where('s.farm_id', $ctx->farm->id)->where('s.status', 'active')
            ->selectRaw('SUM(s.total_amount - COALESCE(paid.amount, 0)) as total')->value('total');

        return [
            'currency' => $ctx->farm->currency, 'from' => $f['from'] ?? null, 'to' => $f['to'] ?? null,
            'money_received' => Money::add((string) ($received ?? '0'), '0'), 'still_owed' => Money::add((string) ($owed ?? '0'), '0'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rows(FarmContext $ctx, LengthAwarePaginator $page): array
    {
        $items = collect($page->items());
        $sales = Sale::where('farm_id', $ctx->farm->id)->whereIn('id', $items->where('kind', 'sale')->pluck('id'))->with('items.item.stockUnit')->get()->keyBy('id');
        $categories = FinanceCategory::whereIn('id', $items->pluck('category_id')->filter()->unique())->get()->keyBy('id');

        return $items->map(function ($row) use ($sales, $categories) {
            $isSale = $row->kind === 'sale';
            $category = $categories[$row->category_id] ?? null;

            return [
                'kind' => $row->kind, 'id' => $row->id, 'reference' => $row->reference, 'date' => (string) $row->occurred_on,
                'contact_id' => $row->contact_id, 'contact_name' => $row->contact_name, 'contact_phone' => $row->contact_phone,
                'summary' => $isSale ? $this->summary($sales[$row->id] ?? null) : ($row->description ?: $category?->name),
                'category' => $category ? ['id' => $category->id, 'code' => $category->code, 'name' => $category->name] : null,
                'total' => Money::add((string) $row->total, '0'), 'amount_received' => Money::add((string) $row->amount_received, '0'), 'balance' => Money::add((string) $row->balance, '0'),
                'money_status' => $row->money_status,
                'links' => ['sale' => $isSale ? '/api/v1/sales/'.$row->id : null, 'transaction' => $isSale ? null : '/api/v1/finance/transactions/'.$row->id],
            ];
        })->all();
    }

    /** "Eggs × 10 crate, Goats × 2": at most three lines, then "+ N more". */
    private function summary(?Sale $sale): ?string
    {
        if ($sale === null) {
            return null;
        }
        $parts = $sale->items->map(function ($line) {
            $quantity = match (true) {
                $line->kind === 'livestock' => (string) $line->head_count,
                $line->kind === 'stock' && $line->item !== null && $line->quantity !== null => implode(' ', $this->ledger->display($line->item, Decimal::trim((string) $line->quantity))),
                $line->kind === 'other' && $line->quantity !== null => Decimal::trim((string) $line->quantity),
                default => null,
            };

            return $line->description.($quantity !== null ? ' × '.$quantity : '');
        });
        $text = $parts->take(3)->implode(', ');

        return $parts->count() > 3 ? $text.' + '.($parts->count() - 3).' more' : $text;
    }
}
