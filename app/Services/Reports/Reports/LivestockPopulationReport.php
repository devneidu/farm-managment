<?php

namespace App\Services\Reports\Reports;

use App\Enums\Permission;
use App\Models\ProductionCycle;
use App\Services\Reports\Report;
use App\Services\Reports\ReportDefinition;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use App\Support\Access\FarmContext;
use Illuminate\Support\Facades\DB;

/**
 * Head count per livestock cycle straight from the population ledger: opening + movements in the period = closing. A reversal is
 * attributed to the event it reverses, so a reversed death nets to zero instead of showing as a separate "reversal" column.
 */
class LivestockPopulationReport extends Report
{
    public function definition(): ReportDefinition
    {
        return new ReportDefinition('livestock_population', 'Livestock population', 'livestock', 'Opening head, additions, deaths, sales and adjustments per livestock cycle, reconciled to the population ledger.',
            [Permission::RecordView, Permission::ProductionCycleView], ['from', 'to', 'status', 'production_cycle_id'], null, 'livestock');
    }

    public function run(FarmContext $ctx, ReportFilters $f): ReportResult
    {
        $effective = "(CASE WHEN r.type = 'reversal' THEN o.type ELSE m.type END)";
        $inPeriod = 'm.recorded_at >= ? AND m.recorded_at < ?';
        $sums = [
            ['opening', 'm.recorded_at < ?', [$f->fromTs()]],
            ['initial', "$inPeriod AND $effective = 'initial'", [$f->fromTs(), $f->toTs()]],
            ['births', "$inPeriod AND $effective = 'breeding_outcome'", [$f->fromTs(), $f->toTs()]],
            ['deaths', "$inPeriod AND $effective = 'mortality'", [$f->fromTs(), $f->toTs()]],
            ['sold', "$inPeriod AND $effective = 'livestock_sale'", [$f->fromTs(), $f->toTs()]],
            ['adjustments', "$inPeriod AND $effective = 'population_adjustment'", [$f->fromTs(), $f->toTs()]],
            ['other', "$inPeriod AND $effective NOT IN ('initial', 'breeding_outcome', 'mortality', 'livestock_sale', 'population_adjustment')", [$f->fromTs(), $f->toTs()]],
        ];
        $select = [];
        $bindings = [];
        foreach ($sums as [$alias, $when, $b]) {
            $select[] = "COALESCE(SUM(CASE WHEN $when THEN m.quantity ELSE 0 END), 0) as $alias";
            array_push($bindings, ...$b);
        }
        $select[] = 'COALESCE(SUM(m.quantity), 0) as closing';
        $where = 'm.farm_id = ? AND m.recorded_at < ?';
        array_push($bindings, $ctx->farm->id, $f->toTs());
        if ($f->productionCycleId) {
            $where .= ' AND m.production_cycle_id = ?';
            $bindings[] = $f->productionCycleId;
        }
        if ($f->status) {
            $where .= ' AND c.status = ?';
            $bindings[] = $f->status;
        }

        $data = collect(DB::select('SELECT m.production_cycle_id, '.implode(', ', $select).' FROM population_movements m '
            ."JOIN production_cycles c ON c.id = m.production_cycle_id AND c.farm_id = m.farm_id AND c.kind = 'livestock' "
            .'LEFT JOIN operational_records r ON r.id = m.operational_record_id LEFT JOIN operational_records o ON o.id = r.reverses_record_id '
            ."WHERE $where GROUP BY m.production_cycle_id", $bindings))->keyBy('production_cycle_id');

        $cycles = ProductionCycle::ofFarm($ctx->farm)->whereIn('id', $data->keys()->all())->with('livestock.species')->orderBy('start_date')->orderBy('reference')->get();
        $rows = [];
        $total = array_fill_keys(['opening', 'initial', 'births', 'deaths', 'sold', 'adjustments', 'other', 'closing'], 0);
        foreach ($cycles as $c) {
            $d = $data[$c->id];
            $row = ['reference' => $c->reference, 'name' => $c->name, 'species' => $c->livestock?->species?->name, 'status' => $c->status->value];
            foreach ($total as $key => $_) {
                $row[$key] = (int) $d->{$key};
                $total[$key] += $row[$key];
            }
            $rows[] = $row;
        }
        $cols = [ReportResult::col('reference', 'Reference'), ReportResult::col('name', 'Cycle'), ReportResult::col('species', 'Species'), ReportResult::col('status', 'Status')];
        foreach (['opening' => 'Opening head', 'initial' => 'Initial stocking', 'births' => 'Births / hatched', 'deaths' => 'Deaths', 'sold' => 'Sold', 'adjustments' => 'Adjustments', 'other' => 'Other', 'closing' => 'Closing head'] as $k => $label) {
            $cols[] = ReportResult::col($k, $label, 'integer');
        }

        return new ReportResult($cols, $rows, $total + ['cycles' => count($rows)], ['Movements are signed (deaths and sales are negative) and net of reversals. Closing head = opening + the movements in the period.']);
    }
}
