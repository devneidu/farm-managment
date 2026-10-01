<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OperationalRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'production_cycle_id' => $this->production_cycle_id, 'type' => $this->type,
            /** @var object */
            'details' => $this->details,
            /** @var object|null */
            'measurement' => $this->measurement,
            'population_delta' => $this->population_delta, 'recorded_at' => $this->recorded_at->toISOString(),
            'notes' => $this->notes, 'reverses_record_id' => $this->reverses_record_id, 'corrects_record_id' => $this->corrects_record_id,
            'reversed_by_record_id' => $this->reversal?->id, 'inventory_movement_id' => $this->inventoryMovement?->id, 'created_by' => $this->created_by,
            'created_at' => $this->created_at->toISOString(), 'attachments' => RecordAttachmentResource::collection($this->attachments)];
    }
}
