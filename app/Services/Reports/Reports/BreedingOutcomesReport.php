<?php

namespace App\Services\Reports\Reports;

use App\Enums\Permission;
use App\Models\BreedingProject;
use App\Models\ProductionCycle;
use App\Services\Reports\Report;
use App\Services\Reports\ReportDefinition;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use App\Support\Access\FarmContext;
use Illuminate\Support\Facades\DB;

/** Breeding projects started or concluded in the period with their expectations and the actual (non-reversed) outcome counts. */
class BreedingOutcomesReport extends Report
{
    public function definition(): ReportDefinition
    {
        return new ReportDefinition('breeding_outcomes', 'Breeding outcomes', 'breeding', 'Breeding projects started or with an outcome in the period: expected window against the live and lost offspring actually recorded (reversed outcomes excluded).',
            [Permission::BreedingView], ['from', 'to', 'status', 'production_cycle_id']);
    }

    public function run(FarmContext $ctx, ReportFilters $f): ReportResult
    {
        $projects = BreedingProject::where('farm_id', $ctx->farm->id)
            ->where(fn ($q) => $q->whereBetween('start_date', [$f->from, $f->to])->orWhereExists(fn ($e) => $e->selectRaw('1')->from('breeding_outcomes as o')
                ->whereColumn('o.breeding_project_id', 'breeding_projects.id')->where('o.kind', 'outcome')->whereBetween('o.outcome_date', [$f->from, $f->to])))
            ->when($f->status, fn ($q, $v) => $q->where('status', $v))->when($f->productionCycleId, fn ($q, $v) => $q->where('production_cycle_id', $v))
            ->orderBy('start_date')->orderBy('reference')->get();
        $ids = $projects->pluck('id')->all();

        $outcomes = $ids === [] ? collect() : collect(DB::select('SELECT o.breeding_project_id, COALESCE(SUM(o.live_count), 0) as live, COALESCE(SUM(o.loss_count), 0) as loss, MAX(o.outcome_date) as outcome_date '
            ."FROM breeding_outcomes o WHERE o.farm_id = ? AND o.kind = 'outcome' AND o.breeding_project_id IN (".implode(',', array_fill(0, count($ids), '?')).') '
            .'AND NOT EXISTS (select 1 from breeding_outcomes rv where rv.reverses_outcome_id = o.id) GROUP BY o.breeding_project_id', [$ctx->farm->id, ...$ids]))->keyBy('breeding_project_id');
        $cycles = ProductionCycle::ofFarm($ctx->farm)->whereIn('id', $projects->pluck('production_cycle_id')->unique()->all())->get()->keyBy('id');

        $rows = [];
        $live = 0;
        $loss = 0;
        foreach ($projects as $p) {
            $o = $outcomes[$p->id] ?? null;
            $live += (int) ($o->live ?? 0);
            $loss += (int) ($o->loss ?? 0);
            $rows[] = [
                'reference' => $p->reference, 'workflow' => $p->workflow->value, 'cycle' => $cycles[$p->production_cycle_id]->reference ?? null, 'status' => $p->status->value, 'start_date' => $p->start_date->toDateString(),
                'expected_from' => ($p->expected_date ?? $p->expected_from)?->toDateString(), 'expected_to' => ($p->expected_date ?? $p->expected_to)?->toDateString(),
                'eggs_set' => $p->eggs_set, 'females_bred' => $p->females_bred, 'expected_offspring' => $p->expected_offspring,
                'outcome_date' => $o?->outcome_date, 'live_count' => $o ? (int) $o->live : null, 'loss_count' => $o ? (int) $o->loss : null,
            ];
        }

        return new ReportResult([
            ReportResult::col('reference', 'Project'), ReportResult::col('workflow', 'Workflow'), ReportResult::col('cycle', 'Cycle'), ReportResult::col('status', 'Status'), ReportResult::col('start_date', 'Started', 'date'),
            ReportResult::col('expected_from', 'Expected from', 'date'), ReportResult::col('expected_to', 'Expected to', 'date'), ReportResult::col('eggs_set', 'Eggs set', 'integer'),
            ReportResult::col('females_bred', 'Females bred', 'integer'), ReportResult::col('expected_offspring', 'Expected offspring', 'integer'),
            ReportResult::col('outcome_date', 'Outcome date', 'date'), ReportResult::col('live_count', 'Live offspring', 'integer'), ReportResult::col('loss_count', 'Lost', 'integer'),
        ], $rows, ['projects' => count($rows), 'live_offspring' => $live, 'lost' => $loss]);
    }
}
