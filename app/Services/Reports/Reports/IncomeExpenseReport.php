<?php

namespace App\Services\Reports\Reports;

use App\Enums\Feature;
use App\Enums\Permission;
use App\Services\Finance\FinanceQueries;
use App\Services\Reports\Report;
use App\Services\Reports\ReportDefinition;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use App\Support\Access\FarmContext;

/** Income and expense by category from the canonical money ledger (reversals offset their entries). Reuses the finance summary, so the numbers can never disagree with it. */
class IncomeExpenseReport extends Report
{
    public function __construct(private FinanceQueries $finance) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition('income_expense', 'Income and expenses', 'finance', 'Income, expense and net by category over the period (farm-local occurred_on dates), straight from the finance ledger with reversals netted.',
            [Permission::FinanceView], ['from', 'to', 'production_cycle_id'], Feature::AdvancedReports);
    }

    public function run(FarmContext $ctx, ReportFilters $f): ReportResult
    {
        $s = $this->finance->summary($ctx, array_filter(['from' => $f->from, 'to' => $f->to, 'production_cycle_id' => $f->productionCycleId]));
        $rows = array_map(fn ($r) => ['direction' => $r['direction'], 'category' => $r['name'] ?? 'Uncategorised', 'amount' => $r['amount']], $s['by_category']);

        return new ReportResult([
            ReportResult::col('direction', 'Direction'), ReportResult::col('category', 'Category'), ReportResult::col('amount', 'Amount ('.$s['currency'].')', 'money'),
        ], $rows, ['currency' => $s['currency']] + $s['totals']);
    }
}
