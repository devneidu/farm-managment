<?php

namespace App\Http\Resources;

use App\Services\Inventory\StockLedger;
use App\Support\Measurement\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ledger = app(StockLedger::class);
        $invoice = $this->invoice;

        return [
            'id' => $this->id, 'reference' => $this->reference,
            /** @var 'active'|'cancelled' */
            'status' => $this->status,
            'contact_id' => $this->contact_id, 'customer_name' => $this->customer_name,
            'recorded_at' => $this->recorded_at->toISOString(),
            'total_amount' => (string) $this->total_amount, 'currency' => $this->currency,
            'finance_category' => $this->category ? ['id' => $this->category->id, 'code' => $this->category->code, 'name' => $this->category->name] : null,
            /**
             * Receivable state, derived from the live invoice: uninvoiced (no invoice), unpaid, partially_paid, paid. A sale is not paid
             * or unpaid by itself - only its invoice is. amount_paid / outstanding are null while uninvoiced.
             */
            'payment_status' => $invoice ? $invoice->paymentStatus() : 'uninvoiced',
            'amount_paid' => $invoice?->amountPaid(),
            'outstanding' => $invoice?->outstanding(),
            'invoice' => $invoice ? ['id' => $invoice->id, 'reference' => $invoice->reference, 'status' => $invoice->status] : null,
            'notes' => $this->notes, 'corrects_sale_id' => $this->corrects_sale_id,
            'cancelled_at' => $this->cancelled_at?->toISOString(), 'cancel_reason' => $this->cancel_reason,
            /** @var array<int, array{id: string, line_no: int, kind: 'stock'|'livestock'|'other', description: string, inventory_item_id: string|null, storage_location_id: string|null, inventory_lot_id: string|null, lot: array{code: string, expires_on: string|null}|null, production_cycle_id: string|null, head_count: int|null, quantity: array{quantity: string, unit: string, normalized: array{quantity: string, unit: string}}|null, measurement: object|null, amount: string, inventory_movement_id: string|null, inventory_reversal_movement_id: string|null, operational_record_id: string|null, reversal_record_id: string|null}> */
            'items' => $this->items->map(function ($line) use ($ledger) {
                $canonical = $line->quantity === null ? null : Decimal::trim((string) $line->quantity);

                return [
                    'id' => $line->id, 'line_no' => $line->line_no, 'kind' => $line->kind, 'description' => $line->description,
                    'inventory_item_id' => $line->inventory_item_id, 'storage_location_id' => $line->storage_location_id, 'inventory_lot_id' => $line->inventory_lot_id,
                    'lot' => $line->lot ? ['code' => $line->lot->code, 'expires_on' => $line->lot->expires_on?->toDateString()] : null,
                    'production_cycle_id' => $line->production_cycle_id, 'head_count' => $line->head_count,
                    'quantity' => $canonical === null ? null : $ledger->display($line->item, $canonical) + ['normalized' => $ledger->canonical($line->item, $canonical)],
                    'measurement' => $line->measurement, 'amount' => (string) $line->amount,
                    /** The Phase 9 stock-out this line caused (stock lines), and the movement that compensated it after a cancellation. */
                    'inventory_movement_id' => $line->movement?->id, 'inventory_reversal_movement_id' => $line->movement?->reversal?->id,
                    /** The population-ledger record that removed the animals (livestock lines), and the record that restored them after a cancellation. */
                    'operational_record_id' => $line->operational_record_id, 'reversal_record_id' => $line->record?->reversal?->id,
                ];
            })->all(),
            'created_by' => $this->created_by, 'created_at' => $this->created_at->toISOString(),
        ];
    }
}
