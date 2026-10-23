<?php

namespace App\Http\Resources;

use App\Services\Finance\FinanceService;
use App\Services\Finance\FinanceSource;
use App\Support\Measurement\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinanceTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'reference' => $this->reference,
            /** @var 'entry'|'reversal' */
            'entry_type' => $this->entry_type,
            /** @var 'income'|'expense' */
            'direction' => $this->direction,
            'category' => ['id' => $this->category->id, 'code' => $this->category->code, 'name' => $this->category->name],
            /** Always positive; a reversal row offsets the original in every total. */
            'amount' => (string) $this->amount, 'currency' => $this->currency,
            'occurred_on' => $this->occurred_on->toDateString(), 'recorded_at' => $this->recorded_at->toISOString(),
            'contact_id' => $this->contact_id, 'contact_name' => $this->contact?->name,
            'production_cycle_id' => $this->production_cycle_id, 'description' => $this->description,
            /** How the money moved: cash, bank_transfer, pos, mobile_money, cheque or other. Null when not recorded (older rows). */
            'payment_method' => $this->payment_method,
            /** Optional: amount = quantity x unit_price. */
            'quantity' => $this->quantity !== null ? Decimal::trim((string) $this->quantity) : null, 'unit_price' => $this->unit_price !== null ? (string) $this->unit_price : null,
            /**
             * Always an object. type is null and label "Entered manually" for manual rows; payment rows add reference, invoice_id and sale_id,
             * purchase rows add reference, so the page can open the source.
             *
             * @var array{type: string|null, id: string|null, label: string, reference?: string|null, invoice_id?: string|null, sale_id?: string|null}
             */
            'source' => FinanceSource::describe($this->resource),
            /** False when the row must be reversed at its source (reverse the payment / cancel the purchase), or is a reversal or already reversed. */
            'can_reverse_directly' => $this->entry_type === 'entry' && $this->reversal === null && ! in_array($this->source_type, FinanceService::REVERSED_AT_SOURCE, true),
            'reverses_transaction_id' => $this->reverses_transaction_id, 'corrects_transaction_id' => $this->corrects_transaction_id,
            'reversed_by_transaction_id' => $this->reversal?->id, 'is_reversed' => $this->reversal !== null, 'reason' => $this->reason,
            'created_by' => $this->created_by, 'created_at' => $this->created_at->toISOString(),
        ];
    }
}
