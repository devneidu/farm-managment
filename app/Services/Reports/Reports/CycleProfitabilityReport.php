<?php

namespace App\Services\Reports\Reports;

use App\Enums\Feature;
use App\Enums\Permission;
use App\Models\ProductionCycle;
use App\Services\Finance\FinanceQueries;
use App\Services\Reports\Report;
use App\Services\Reports\ReportDefinition;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use App\Support\Access\FarmContext;
use App\Support\Finance\Money;

/** Income, expense and net per production cycle from the finance ledger; money not allocated to a cycle is its own row. */
class CycleProfitabilityReport extends Report
{
    public function __construct(private FinanceQueries $finance) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition('cycle_profitability', 'Cycle profitability', 'finance', 'Income, expenses and net per production cycle over the period; money not allocated to a cycle is shown separately.',
            [Permission::FinanceView, Permission::ProductionCycleView], ['from', 'to'], Feature::AdvancedReports);
    }

    public function run(FarmContext $ctx, ReportFilters $f): ReportResult
    {
        $s = $this->finance->summary($ctx, ['from' => $f->from, 'to' => $f->to]);
        $cycles = ProductionCycle::ofFarm($ctx->farm)->whereIn('id', array_filter(array_column($s['by_cycle'], 'production_cycle_id')))->get()->keyBy('id');
        $rows = [];
        foreach ($s['by_cycle'] as $r) {
            $c = $r['production_cycle_id'] ? ($cycles[$r['production_cycle_id']] ?? null) : null;
            $rows[] = ['reference' => $c?->reference, 'name' => $c?->name ?? 'Not allocated to a cycle', 'status' => $c?->status->value, 'income' => $r['income'], 'expense' => $r['expense'], 'net' => $r['net']];
        }
        usort($rows, fn ($a, $b) => [$a['reference'] === null, (string) $a['reference']] <=> [$b['reference'] === null, (string) $b['reference']]);
        $cur = $s['currency'];

        return new ReportResult([
            ReportResult::col('reference', 'Reference'), ReportResult::col('name', 'Cycle'), ReportResult::col('status', 'Status'),
            ReportResult::col('income', "Income ($cur)", 'money'), ReportResult::col('expense', "Expenses ($cur)", 'money'), ReportResult::col('net', "Net ($cur)", 'money'),
        ], $rows, ['currency' => $cur, 'income' => $s['totals']['income'], 'expense' => $s['totals']['expense'], 'net' => Money::sub($s['totals']['income'], $s['totals']['expense'])]);
    }
}
