<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'reference' => $this->reference,
            /** @var 'payment'|'reversal' */
            'entry_type' => $this->entry_type,
            'invoice_id' => $this->invoice_id, 'invoice_reference' => $this->invoice?->reference,
            /** Always positive; a reversal row offsets the original in the invoice balance. */
            'amount' => (string) $this->amount, 'currency' => $this->currency,
            /** @var 'cash'|'bank_transfer'|'pos'|'mobile_money'|'cheque'|'other'|null */
            'method' => $this->method, 'payment_reference' => $this->payment_reference,
            'received_on' => $this->received_on->toDateString(), 'recorded_at' => $this->recorded_at->toISOString(),
            'notes' => $this->notes,
            /** The ONE income entry this payment booked in the finance ledger (for a reversal: the offsetting ledger row). */
            'finance_transaction_id' => $this->finance_transaction_id,
            'reverses_payment_id' => $this->reverses_payment_id, 'reversed_by_payment_id' => $this->reversal?->id,
            'is_reversed' => $this->entry_type === 'payment' && $this->reversal !== null, 'reason' => $this->reason,
            'created_by' => $this->created_by, 'created_at' => $this->created_at->toISOString(),
        ];
    }
}
