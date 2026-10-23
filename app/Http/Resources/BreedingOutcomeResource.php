<?php

namespace App\Http\Resources;

use App\Models\ProductionCycle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BreedingOutcomeResource extends JsonResource
{
    private function destinationBatch(): ?array
    {
        $batch = ProductionCycle::where('farm_id', $this->farm_id)->withSum('movements as current_population', 'quantity')->find($this->production_cycle_id);

        return $batch ? ['id' => $batch->id, 'name' => $batch->name, 'reference' => $batch->reference, 'status' => $batch->status->value, 'current_population' => (int) $batch->current_population] : null;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'breeding_project_id' => $this->breeding_project_id, /** The batch whose head count this outcome (or its undo) changed: the mother batch, or the new batch started for the offspring. */
            'production_cycle_id' => $this->production_cycle_id,
            /** @var 'mother_batch'|'new_batch' */
            'destination' => $this->destination,
            /**
             * The destination batch as it stands now (name, reference, status and current head count).
             *
             * @var array{id: string, name: string, reference: string, status: string, current_population: int}|null
             */
            'destination_batch' => $this->destinationBatch(),
            'kind' => $this->kind,
            'live_count' => $this->live_count, 'loss_count' => $this->loss_count,
            /** Actual outcome day (farm-local). Separate from the expected date/window and from created_at. */
            'outcome_date' => $this->outcome_date?->toDateString(),
            /** The Phase 8 operational record carrying the population effect (or its compensation); null when live_count is 0. */
            'operational_record_id' => $this->operational_record_id,
            'reverses_outcome_id' => $this->reverses_outcome_id, 'corrects_outcome_id' => $this->corrects_outcome_id,
            'reversed_by_outcome_id' => $this->reversal?->id, 'reason' => $this->reason,
            'recorded_at' => $this->recorded_at->toISOString(), 'notes' => $this->notes,
            'created_by' => $this->created_by, 'created_at' => $this->created_at->toISOString(),
        ];
    }
}
