<?php

namespace App\Services\Records;

use App\Enums\CycleKind;
use App\Models\OperationalRecord;
use App\Models\ProductionCycle;
use App\Support\Api\ApiHttpException;

class PopulationLedger
{
    /** Caller holds the farm and cycle locks. Verify both links and chronological nonnegative balances. */
    public function reconcile(ProductionCycle $cycle): int
    {
        $rows = $cycle->movements()->orderBy('recorded_at')->orderBy('id')->lockForUpdate()->get();
        if ($cycle->kind !== CycleKind::Livestock) {
            if ($rows->isNotEmpty()) {
                $this->invalid();
            }

            return 0;
        }
        $initial = $rows->where('source_key', 'initial');
        if (! $cycle->livestock || $initial->count() !== 1 || $initial->first()->type !== 'initial' || $initial->first()->operational_record_id !== null || $initial->first()->quantity !== $cycle->livestock->initial_population) {
            $this->invalid();
        }
        $records = OperationalRecord::where('production_cycle_id', $cycle->id)->get()->keyBy('id');
        $total = 0;
        foreach ($rows as $row) {
            if ($row->source_key !== 'initial') {
                $record = $records->get($row->operational_record_id);
                if (! $record || $record->farm_id !== $cycle->farm_id || $row->source_key !== 'record:'.$record->id || $row->quantity !== $record->population_delta || $row->type !== $record->type || ! $row->recorded_at->equalTo($record->recorded_at)) {
                    $this->invalid();
                }
            }
            $total += $row->quantity;
            if ($total < 0) {
                throw new ApiHttpException(409, 'insufficient_population', 'This event would produce a negative population, including in the dated ledger history.');
            }
        }
        foreach ($records as $record) {
            $needsMovement = $record->population_delta !== 0 || in_array($record->type, ['mortality', 'population_adjustment']);
            if ($rows->where('operational_record_id', $record->id)->count() !== ($needsMovement ? 1 : 0)) {
                $this->invalid();
            }
        }

        return $total;
    }

    private function invalid(): never
    {
        throw new ApiHttpException(409, 'cycle_reconciliation_failed', 'The population ledger and its source records do not reconcile.');
    }
}
