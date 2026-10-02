<?php

namespace App\Services\Finance;

use App\Enums\Permission;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\ProductionCycle;
use App\Support\Access\FarmContext;
use App\Support\Finance\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Read side of the money ledger: farm-scoped and finance.view gated. Totals are SUMs over the ledger, reversals offset entries. */
class FinanceQueries
{
    private const SIGNED = "CASE WHEN entry_type = 'reversal' THEN -amount ELSE amount END";

    public function transactions(FarmContext $ctx, array $f): LengthAwarePaginator
    {
        $ctx->authorize(Permission::FinanceView);
        $q = FinanceTransaction::where('farm_id', $ctx->farm->id)->with(['category', 'contact', 'reversal']);
        foreach (['direction', 'entry_type', 'finance_category_id', 'contact_id', 'production_cycle_id', 'source_type', 'source_id'] as $column) {
            if (isset($f[$column])) {
                $q->where($column, $f[$column]);
            }
        }
        if (isset($f['from'])) {
            $q->where('occurred_on', '>=', $f['from']);
        }
        if (isset($f['to'])) {
            $q->where('occurred_on', '<=', $f['to']);
        }

        return $q->orderByDesc('occurred_on')->orderByDesc('recorded_at')->orderByDesc('id')->paginate($f['per_page'] ?? 50);
    }

    /**
     * Income, expense and net (signed, reversals offset entries), by category and - without a cycle filter - by production cycle.
     * With production_cycle_id this is that cycle's profitability.
     *
     * @return array<string, mixed>
     */
    public function summary(FarmContext $ctx, array $f): array
    {
        $ctx->authorize(Permission::FinanceView);
        $cycle = null;
        if (isset($f['production_cycle_id'])) {
            $cycle = ProductionCycle::ofFarm($ctx->farm)->findOrFail($f['production_cycle_id']);
        }
        $base = fn () => FinanceTransaction::where('farm_id', $ctx->farm->id)
            ->when(isset($f['from']), fn ($q) => $q->where('occurred_on', '>=', $f['from']))
            ->when(isset($f['to']), fn ($q) => $q->where('occurred_on', '<=', $f['to']))
            ->when($cycle, fn ($q) => $q->where('production_cycle_id', $cycle->id));

        $totals = ['income' => '0.00', 'expense' => '0.00'];
        foreach ($base()->selectRaw('direction, SUM('.self::SIGNED.') as total')->groupBy('direction')->get() as $row) {
            $totals[$row->direction] = Money::add((string) $row->total, '0');
        }

        $categories = FinanceCategory::visibleTo($ctx->farm)->get()->keyBy('id');
        $byCategory = [];
        foreach ($base()->selectRaw('finance_category_id, direction, SUM('.self::SIGNED.') as total')->groupBy('finance_category_id', 'direction')->get() as $row) {
            $category = $categories[$row->finance_category_id] ?? null;
            $byCategory[] = [
                'finance_category_id' => $row->finance_category_id, 'code' => $category?->code, 'name' => $category?->name, 'direction' => $row->direction,
                'amount' => Money::add((string) $row->total, '0'),
            ];
        }
        usort($byCategory, fn ($a, $b) => [$a['direction'], $a['name']] <=> [$b['direction'], $b['name']]);

        $summary = [
            'currency' => $ctx->farm->currency,
            'from' => $f['from'] ?? null, 'to' => $f['to'] ?? null, 'production_cycle_id' => $cycle?->id,
            'totals' => ['income' => $totals['income'], 'expense' => $totals['expense'], 'net' => Money::sub($totals['income'], $totals['expense'])],
            'by_category' => $byCategory,
        ];
        if ($cycle === null) {
            $rows = [];
            foreach ($base()->selectRaw('production_cycle_id, direction, SUM('.self::SIGNED.') as total')->groupBy('production_cycle_id', 'direction')->get() as $row) {
                $rows[$row->production_cycle_id ?? ''][$row->direction] = Money::add((string) $row->total, '0');
            }
            $summary['by_cycle'] = collect($rows)->map(function (array $r, string $cycleId) {
                $income = $r['income'] ?? '0.00';
                $expense = $r['expense'] ?? '0.00';

                return ['production_cycle_id' => $cycleId === '' ? null : $cycleId, 'income' => $income, 'expense' => $expense, 'net' => Money::sub($income, $expense)];
            })->sortBy(fn ($r) => $r['production_cycle_id'] ?? '')->values()->all();
        }

        return $summary;
    }
}
