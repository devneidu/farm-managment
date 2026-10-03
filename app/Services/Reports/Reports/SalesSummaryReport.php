<?php

namespace App\Services\Reports\Reports;

use App\Enums\Feature;
use App\Enums\Permission;
use App\Models\Sale;
use App\Services\Reports\Report;
use App\Services\Reports\ReportDefinition;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use App\Support\Access\FarmContext;
use App\Support\Finance\Money;
use Illuminate\Support\Facades\DB;

/** Sales recorded in the period by line kind. Cancelled sales are excluded from every total and counted separately. Head count is only reported for livestock lines; stock quantities are never mixed with money or head. */
class SalesSummaryReport extends Report
{
    private const KINDS = ['livestock' => 'Livestock', 'stock' => 'Stock (produce)', 'other' => 'Other'];

    public function definition(): ReportDefinition
    {
        return new ReportDefinition('sales_summary', 'Sales summary', 'sales', 'Sales in the period by line kind (livestock, produce stock, other) with exact totals; cancelled sales are excluded.',
            [Permission::SaleView], ['from', 'to'], Feature::AdvancedReports);
    }

    public function run(FarmContext $ctx, ReportFilters $f): ReportResult
    {
        $bindings = [$ctx->farm->id, Sale::ACTIVE, $f->fromTs(), $f->toTs()];
        $data = DB::select('SELECT si.kind, COUNT(DISTINCT s.id) as sales, COUNT(*) as line_count, COALESCE(SUM(si.amount), 0) as amount, COALESCE(SUM(si.head_count), 0) as head '
            .'FROM sale_items si JOIN sales s ON s.id = si.sale_id AND s.farm_id = si.farm_id WHERE s.farm_id = ? AND s.status = ? AND s.recorded_at >= ? AND s.recorded_at < ? GROUP BY si.kind', $bindings);
        $totals = Sale::where('farm_id', $ctx->farm->id)->where('status', Sale::ACTIVE)->where('recorded_at', '>=', $f->fromTs())->where('recorded_at', '<', $f->toTs())
            ->toBase()->selectRaw('COUNT(*) as n, COALESCE(SUM(total_amount), 0) as total')->first();
        $cancelled = Sale::where('farm_id', $ctx->farm->id)->where('status', Sale::CANCELLED)->where('recorded_at', '>=', $f->fromTs())->where('recorded_at', '<', $f->toTs())->count();

        usort($data, fn ($a, $b) => array_search($a->kind, array_keys(self::KINDS)) <=> array_search($b->kind, array_keys(self::KINDS)));
        $rows = array_map(fn ($d) => [
            'kind' => self::KINDS[$d->kind] ?? $d->kind, 'sales' => (int) $d->sales, 'lines' => (int) $d->line_count,
            'head_sold' => $d->kind === 'livestock' ? (int) $d->head : null, 'amount' => Money::add((string) $d->amount, '0'),
        ], $data);
        $cur = $ctx->farm->currency;

        return new ReportResult([
            ReportResult::col('kind', 'Line kind'), ReportResult::col('sales', 'Sales', 'integer'), ReportResult::col('lines', 'Lines', 'integer'),
            ReportResult::col('head_sold', 'Head sold', 'integer'), ReportResult::col('amount', "Amount ($cur)", 'money'),
        ], $rows, ['currency' => $cur, 'sales' => (int) $totals->n, 'total' => Money::add((string) $totals->total, '0'), 'cancelled_sales' => $cancelled],
            ['A sale appears once per line kind it contains, so the Sales column can add up to more than the number of sales.']);
    }
}
