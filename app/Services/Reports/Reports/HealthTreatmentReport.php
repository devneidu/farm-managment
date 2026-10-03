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

/** Health events (vaccination, treatment, ...) per cycle and type, excluding reversed events and the reversal rows themselves. */
class HealthTreatmentReport extends Report
{
    public function definition(): ReportDefinition
    {
        return new ReportDefinition('health_treatment', 'Health and treatment', 'health', 'Health events per cycle and type in the period, with the animals affected and the medicine lines used; reversed events are excluded.',
            [Permission::HealthView], ['from', 'to', 'production_cycle_id']);
    }

    public function run(FarmContext $ctx, ReportFilters $f): ReportResult
    {
        $bindings = [$ctx->farm->id, $f->fromTs(), $f->toTs()];
        $cycleSql = '';
        if ($f->productionCycleId) {
            $cycleSql = ' AND h.production_cycle_id = ?';
            $bindings[] = $f->productionCycleId;
        }
        $data = DB::select('SELECT h.production_cycle_id, h.type, COUNT(*) as events, COALESCE(SUM(h.animals_affected), 0) as animals, '
            .'COALESCE(SUM((select COUNT(*) from health_record_medicines hm where hm.health_record_id = h.id)), 0) as medicine_lines '
            ."FROM health_records h WHERE h.farm_id = ? AND h.reverses_record_id IS NULL AND h.recorded_at >= ? AND h.recorded_at < ?$cycleSql AND ".$this->notReversed('health_records', 'h')
            .' GROUP BY h.production_cycle_id, h.type', $bindings);

        $cycles = ProductionCycle::ofFarm($ctx->farm)->whereIn('id', array_unique(array_column($data, 'production_cycle_id')))->get()->keyBy('id');
        usort($data, fn ($a, $b) => [$cycles[$a->production_cycle_id]->reference, $a->type] <=> [$cycles[$b->production_cycle_id]->reference, $b->type]);
        $rows = [];
        $events = 0;
        $animals = 0;
        foreach ($data as $d) {
            $c = $cycles[$d->production_cycle_id];
            $rows[] = ['reference' => $c->reference, 'name' => $c->name, 'type' => $d->type, 'events' => (int) $d->events, 'animals_affected' => (int) $d->animals, 'medicine_lines' => (int) $d->medicine_lines];
            $events += (int) $d->events;
            $animals += (int) $d->animals;
        }

        return new ReportResult([
            ReportResult::col('reference', 'Reference'), ReportResult::col('name', 'Cycle'), ReportResult::col('type', 'Event type'), ReportResult::col('events', 'Events', 'integer'),
            ReportResult::col('animals_affected', 'Animals affected', 'integer'), ReportResult::col('medicine_lines', 'Medicine lines', 'integer'),
        ], $rows, ['events' => $events, 'animals_affected' => $animals], ['Animals affected is the sum over events; the same animal treated twice is counted twice.']);
    }
}
