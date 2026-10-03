<?php

namespace App\Services\Reports\Reports;

use App\Enums\Permission;
use App\Models\ProductionCycle;
use App\Services\Reports\Report;
use App\Services\Reports\ReportDefinition;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use App\Support\Access\FarmContext;
use App\Support\Measurement\Decimal;
use Illuminate\Support\Facades\DB;

/** Live-weight sampling per livestock cycle: weighted average weight per head over the period and the most recent sample. */
class LivestockGrowthReport extends Report
{
    public function definition(): ReportDefinition
    {
        return new ReportDefinition('livestock_growth', 'Livestock growth (weight)', 'livestock', 'Weigh-ins per livestock cycle: sampled head, weighted average weight per head and the latest sample average. Reversed weigh-ins are excluded.',
            [Permission::RecordView, Permission::ProductionCycleView], ['from', 'to', 'production_cycle_id'], null, 'livestock');
    }

    public function run(FarmContext $ctx, ReportFilters $f): ReportResult
    {
        $sample = "CAST(JSON_UNQUOTE(JSON_EXTRACT(r.details, '\$.sample_size')) AS UNSIGNED)";
        $qty = $this->qty();
        $unit = $this->unit();
        $bindings = [$ctx->farm->id, $f->fromTs(), $f->toTs()];
        $cycleSql = '';
        if ($f->productionCycleId) {
            $cycleSql = ' AND r.production_cycle_id = ?';
            $bindings[] = $f->productionCycleId;
        }
        $base = "FROM operational_records r WHERE r.farm_id = ? AND r.type = 'weight' AND r.recorded_at >= ? AND r.recorded_at < ?$cycleSql AND ".$this->notReversed('operational_records', 'r');

        $agg = DB::select("SELECT r.production_cycle_id, $unit as unit, COUNT(*) as weigh_ins, SUM($sample) as head, SUM($qty) as weight, MIN(r.recorded_at) as first_at, MAX(r.recorded_at) as last_at $base GROUP BY r.production_cycle_id, $unit", $bindings);
        $latest = collect(DB::select("SELECT t.production_cycle_id, t.unit, t.q, t.s FROM (SELECT r.production_cycle_id, $unit as unit, $qty as q, $sample as s, ROW_NUMBER() OVER (PARTITION BY r.production_cycle_id, $unit ORDER BY r.recorded_at DESC, r.id DESC) as rn $base) t WHERE t.rn = 1", $bindings))
            ->keyBy(fn ($r) => $r->production_cycle_id.'|'.$r->unit);

        $cycles = ProductionCycle::ofFarm($ctx->farm)->whereIn('id', array_unique(array_column($agg, 'production_cycle_id')))->get()->keyBy('id');
        usort($agg, fn ($a, $b) => [$cycles[$a->production_cycle_id]->reference, $a->unit] <=> [$cycles[$b->production_cycle_id]->reference, $b->unit]);
        $rows = [];
        foreach ($agg as $a) {
            $c = $cycles[$a->production_cycle_id];
            $l = $latest[$a->production_cycle_id.'|'.$a->unit];
            $rows[] = [
                'reference' => $c->reference, 'name' => $c->name, 'unit' => $a->unit, 'weigh_ins' => (int) $a->weigh_ins, 'head_sampled' => (int) $a->head,
                'average_weight_per_head' => (int) $a->head > 0 ? Decimal::round(Decimal::div(Decimal::trim((string) $a->weight), (string) $a->head), 3) : null,
                'latest_average_weight_per_head' => (int) $l->s > 0 ? Decimal::round(Decimal::div(Decimal::trim((string) $l->q), (string) $l->s), 3) : null,
                'first_weighed_at' => $a->first_at, 'last_weighed_at' => $a->last_at,
            ];
        }

        return new ReportResult([
            ReportResult::col('reference', 'Reference'), ReportResult::col('name', 'Cycle'), ReportResult::col('unit', 'Weight unit'), ReportResult::col('weigh_ins', 'Weigh-ins', 'integer'),
            ReportResult::col('head_sampled', 'Head sampled', 'integer'), ReportResult::col('average_weight_per_head', 'Average weight per head', 'decimal'),
            ReportResult::col('latest_average_weight_per_head', 'Latest sample average per head', 'decimal'), ReportResult::col('first_weighed_at', 'First weigh-in', 'datetime'), ReportResult::col('last_weighed_at', 'Last weigh-in', 'datetime'),
        ], $rows, ['weigh_ins' => array_sum(array_column($rows, 'weigh_ins'))], ['Weights are in the normalised unit shown; averages are never combined across units or cycles.']);
    }
}
