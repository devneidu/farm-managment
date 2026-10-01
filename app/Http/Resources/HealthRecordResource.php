<?php

namespace App\Http\Resources;

use App\Services\Inventory\StockLedger;
use App\Support\Measurement\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HealthRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ledger = app(StockLedger::class);
        $reversed = $this->reversal !== null;

        return [
            'id' => $this->id, 'production_cycle_id' => $this->production_cycle_id, 'type' => $this->type,
            /** @var object */
            'details' => $this->details,
            'animals_affected' => $this->animals_affected, 'follow_up_on' => $this->follow_up_on?->toDateString(),
            /** The linked Phase 8 mortality record, if any. Population is only ever changed by that record. */
            'mortality_record_id' => $this->mortality_record_id,
            'recorded_at' => $this->recorded_at->toISOString(), 'notes' => $this->notes,
            'reverses_record_id' => $this->reverses_record_id, 'corrects_record_id' => $this->corrects_record_id,
            'reversed_by_record_id' => $this->reversal?->id,
            /** @var array<int, array{id: string, line_no: int, inventory_item_id: string, item_name: string, storage_location_id: string, inventory_lot_id: string|null, lot: array{code: string, expires_on: string|null}|null, quantity_used: array{quantity: string, unit: string, normalized: array{quantity: string, unit: string}}, measurement: object, dose_per_animal: object|null, dosage_instructions: string|null, withdrawal: array{days: int|null, source: string|null, started_at: string, ends_at: string|null, is_active: bool}, inventory_movement_id: string|null}> */
            'medicines' => $this->medicines->map(function ($line) use ($ledger, $reversed) {
                $canonical = Decimal::trim((string) $line->quantity_used);

                return [
                    'id' => $line->id, 'line_no' => $line->line_no, 'inventory_item_id' => $line->inventory_item_id, 'item_name' => $line->item_name,
                    'storage_location_id' => $line->storage_location_id, 'inventory_lot_id' => $line->inventory_lot_id,
                    'lot' => $line->lot ? ['code' => $line->lot->code, 'expires_on' => $line->lot->expires_on?->toDateString()] : null,
                    'quantity_used' => $ledger->display($line->item, $canonical) + ['normalized' => $ledger->canonical($line->item, $canonical)],
                    'measurement' => $line->measurement, 'dose_per_animal' => $line->dose, 'dosage_instructions' => $line->dosage_instructions,
                    'withdrawal' => [
                        'days' => $line->withdrawal_days, 'source' => $line->withdrawal_source, 'started_at' => $this->recorded_at->toISOString(),
                        'ends_at' => $line->withdrawal_ends_at?->toISOString(), 'is_active' => ! $reversed && $line->withdrawal_ends_at !== null && $line->withdrawal_ends_at->isFuture(),
                    ],
                    'inventory_movement_id' => $line->movement?->id,
                ];
            })->all(),
            'created_by' => $this->created_by, 'created_at' => $this->created_at->toISOString(),
        ];
    }
}
