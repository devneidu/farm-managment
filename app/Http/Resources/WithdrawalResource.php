<?php

namespace App\Http\Resources;

use App\Models\HealthRecordMedicine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin HealthRecordMedicine
 *
 * A withdrawal window, always traceable to the health record and medicine line that started it.
 */
class WithdrawalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'health_record_medicine_id' => $this->id, 'health_record_id' => $this->health_record_id, 'production_cycle_id' => $this->record->production_cycle_id,
            'health_record_type' => $this->record->type, 'inventory_item_id' => $this->inventory_item_id, 'item_name' => $this->item_name,
            'inventory_lot_id' => $this->inventory_lot_id, 'lot' => $this->lot ? ['code' => $this->lot->code, 'expires_on' => $this->lot->expires_on?->toDateString()] : null,
            'days' => $this->withdrawal_days, 'source' => $this->withdrawal_source, 'started_at' => $this->record->recorded_at->toISOString(),
            'ends_at' => $this->withdrawal_ends_at->toISOString(), 'is_active' => $this->withdrawal_ends_at->isFuture(),
        ];
    }
}
