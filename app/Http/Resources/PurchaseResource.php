<?php

namespace App\Http\Resources;

use App\Services\Inventory\StockLedger;
use App\Support\Measurement\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ledger = app(StockLedger::class);

        return [
            'id' => $this->id, 'reference' => $this->reference,
            /** @var 'active'|'cancelled' */
            'status' => $this->status,
            'contact_id' => $this->contact_id, 'contact_name' => $this->contact?->name,
            'production_cycle_id' => $this->production_cycle_id, 'supplier_reference' => $this->supplier_reference,
            'recorded_at' => $this->recorded_at->toISOString(),
            'total_amount' => (string) $this->total_amount, 'currency' => $this->currency,
            'finance_category' => $this->category ? ['id' => $this->category->id, 'code' => $this->category->code, 'name' => $this->category->name] : null,
            'records_expense' => $this->records_expense,
            /** The one expense this purchase booked (null when none was booked), and the entry that offsets it after a cancellation. */
            'finance_transaction_id' => $this->transaction?->id,
            'finance_reversal_transaction_id' => $this->transaction?->reversal?->id,
            'notes' => $this->notes, 'corrects_purchase_id' => $this->corrects_purchase_id,
            'cancelled_at' => $this->cancelled_at?->toISOString(), 'cancel_reason' => $this->cancel_reason,
            /** @var array<int, array{id: string, line_no: int, kind: string, description: string, inventory_item_id: string|null, storage_location_id: string|null, inventory_lot_id: string|null, lot: array{code: string, expires_on: string|null}|null, quantity: array{quantity: string, unit: string, normalized: array{quantity: string, unit: string}}|null, measurement: object|null, amount: string, inventory_movement_id: string|null}> */
            'items' => $this->items->map(function ($line) use ($ledger) {
                $canonical = $line->quantity === null ? null : Decimal::trim((string) $line->quantity);

                return [
                    'id' => $line->id, 'line_no' => $line->line_no, 'kind' => $line->kind, 'description' => $line->description,
                    'inventory_item_id' => $line->inventory_item_id, 'storage_location_id' => $line->storage_location_id, 'inventory_lot_id' => $line->inventory_lot_id,
                    'lot' => $line->lot ? ['code' => $line->lot->code, 'expires_on' => $line->lot->expires_on?->toDateString()] : null,
                    'quantity' => $canonical === null ? null : $ledger->display($line->item, $canonical) + ['normalized' => $ledger->canonical($line->item, $canonical)],
                    'measurement' => $line->measurement, 'amount' => (string) $line->amount,
                    'inventory_movement_id' => $line->movement?->id,
                ];
            })->all(),
            'created_by' => $this->created_by, 'created_at' => $this->created_at->toISOString(),
        ];
    }
}
