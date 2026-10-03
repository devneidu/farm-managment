<?php

namespace App\Http\Resources;

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
            /** @var array{type: string, id: string}|null */
            'source' => $this->source_type ? ['type' => $this->source_type, 'id' => $this->source_id] : null,
            'reverses_transaction_id' => $this->reverses_transaction_id, 'corrects_transaction_id' => $this->corrects_transaction_id,
            'reversed_by_transaction_id' => $this->reversal?->id, 'is_reversed' => $this->reversal !== null, 'reason' => $this->reason,
            'created_by' => $this->created_by, 'created_at' => $this->created_at->toISOString(),
        ];
    }
}
