<?php

namespace App\Http\Resources;

use App\Models\Payment;
use App\Support\Access\FarmContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $void = $this->isVoid();

        return [
            'id' => $this->id, 'reference' => $this->reference, 'sale_id' => $this->sale_id, 'sale_reference' => $this->sale?->reference,
            /** Document state, separate from payment state. */
            /** @var 'issued'|'void' */
            'status' => $this->status,
            'payment_status' => $this->paymentStatus(),
            'issue_date' => $this->issue_date->toDateString(), 'due_date' => $this->due_date?->toDateString(),
            'is_overdue' => ! $void && $this->due_date !== null && $this->due_date->toDateString() < now(app(FarmContext::class)->farm->timezone)->toDateString() && $this->paymentStatus() !== 'paid',
            'total_amount' => (string) $this->total_amount, 'amount_paid' => $this->amountPaid(), 'outstanding' => $void ? '0.00' : $this->outstanding(), 'currency' => $this->currency,
            /** Snapshot taken when the invoice was issued; later contact edits never change it. */
            'customer' => ['contact_id' => $this->contact_id, 'name' => $this->customer_name, 'phone' => $this->customer_phone, 'email' => $this->customer_email, 'address' => $this->customer_address],
            'seller_name' => $this->seller_name, 'notes' => $this->notes,
            'voided_at' => $this->voided_at?->toISOString(), 'void_reason' => $this->void_reason,
            'items' => $this->items->map(fn ($line) => [
                'id' => $line->id, 'line_no' => $line->line_no, 'sale_item_id' => $line->sale_item_id, 'kind' => $line->kind, 'description' => $line->description,
                'quantity_label' => $line->quantity_label, 'amount' => (string) $line->amount,
            ])->all(),
            /** @var array<int, array{id: string, reference: string, entry_type: string, amount: string, method: string|null, received_on: string, is_reversed: bool}> */
            'payments' => $this->payments->map(fn ($p) => [
                'id' => $p->id, 'reference' => $p->reference, 'entry_type' => $p->entry_type, 'amount' => (string) $p->amount, 'method' => $p->method,
                'received_on' => $p->received_on->toDateString(), 'is_reversed' => $p->entry_type === Payment::PAYMENT && $p->reversal !== null,
            ])->all(),
            'created_by' => $this->created_by, 'created_at' => $this->created_at->toISOString(),
        ];
    }
}
