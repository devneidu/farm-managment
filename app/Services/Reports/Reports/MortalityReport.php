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

/** Deaths per livestock cycle and recorded cause, from mortality records that were not reversed. */
class MortalityReport extends Report
{
    public function definition(): ReportDefinition
    {
        return new ReportDefinition('mortality', 'Mortality by cause', 'livestock', 'Deaths per livestock cycle and cause in the period; reversed mortality records are excluded.',
            [Permission::RecordView, Permission::ProductionCycleView], ['from', 'to', 'production_cycle_id'], null, 'livestock');
    }

    public function run(FarmContext $ctx, ReportFilters $f): ReportResult
    {
        $cause = "LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.details, '\$.cause'))))";
        $bindings = [$ctx->farm->id, $f->fromTs(), $f->toTs()];
        $cycleSql = '';
        if ($f->productionCycleId) {
            $cycleSql = ' AND r.production_cycle_id = ?';
            $bindings[] = $f->productionCycleId;
        }
        $data = DB::select("SELECT r.production_cycle_id, $cause as cause_key, MIN(JSON_UNQUOTE(JSON_EXTRACT(r.details, '\$.cause'))) as cause, COUNT(*) as records, -SUM(r.population_delta) as deaths "
            ."FROM operational_records r WHERE r.farm_id = ? AND r.type = 'mortality' AND r.recorded_at >= ? AND r.recorded_at < ?$cycleSql AND ".$this->notReversed('operational_records', 'r')
            ." GROUP BY r.production_cycle_id, $cause", $bindings);

        $cycles = ProductionCycle::ofFarm($ctx->farm)->whereIn('id', array_unique(array_column($data, 'production_cycle_id')))->get()->keyBy('id');
        usort($data, fn ($a, $b) => [$cycles[$a->production_cycle_id]->reference, -$a->deaths, $a->cause_key] <=> [$cycles[$b->production_cycle_id]->reference, -$b->deaths, $b->cause_key]);
        $rows = [];
        $deaths = 0;
        $records = 0;
        foreach ($data as $d) {
            $c = $cycles[$d->production_cycle_id];
            $rows[] = ['reference' => $c->reference, 'name' => $c->name, 'cause' => $d->cause, 'records' => (int) $d->records, 'deaths' => (int) $d->deaths];
            $deaths += (int) $d->deaths;
            $records += (int) $d->records;
        }

        return new ReportResult([
            ReportResult::col('reference', 'Reference'), ReportResult::col('name', 'Cycle'), ReportResult::col('cause', 'Cause'),
            ReportResult::col('records', 'Records', 'integer'), ReportResult::col('deaths', 'Deaths', 'integer'),
        ], $rows, ['deaths' => $deaths, 'records' => $records]);
    }
}
