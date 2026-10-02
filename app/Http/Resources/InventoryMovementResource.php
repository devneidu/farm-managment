<?php

namespace App\Http\Resources;

use App\Services\Inventory\StockLedger;
use App\Support\Measurement\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ledger = app(StockLedger::class);
        $delta = Decimal::trim((string) $this->quantity_delta);

        return [
            'id' => $this->id, 'inventory_item_id' => $this->inventory_item_id, 'storage_location_id' => $this->storage_location_id,
            'inventory_lot_id' => $this->inventory_lot_id,
            'lot' => $this->lot ? ['code' => $this->lot->code, 'expires_on' => $this->lot->expires_on?->toDateString()] : null,
            'type' => $this->type->value, 'reason' => $this->reason, 'justification' => $this->justification,
            /**
             * Signed change in the canonical unit (positive = stock added).
             *
             * @var array{quantity: string, unit: string}
             */
            'quantity_delta' => $ledger->canonical($this->item, $delta),
            /** @var array{quantity: string, unit: string} */
            'quantity_delta_display' => $ledger->display($this->item, $delta),
            /**
             * Entered parts, normalised totals and the replayable Phase 5 conversion snapshot.
             *
             * @var object
             */
            'measurement' => $this->measurement,
            'recorded_at' => $this->recorded_at->toISOString(), 'notes' => $this->notes,
            'operational_record_id' => $this->operational_record_id, 'health_record_id' => $this->health_record_id, 'health_record_medicine_id' => $this->health_record_medicine_id, 'purchase_id' => $this->purchase_id, 'purchase_item_id' => $this->purchase_item_id, 'transfer_group_id' => $this->transfer_group_id,
            'reverses_movement_id' => $this->reverses_movement_id, 'reversed_by_movement_id' => $this->reversal?->id,
            'created_by' => $this->created_by, 'created_at' => $this->created_at->toISOString(),
        ];
    }
}
