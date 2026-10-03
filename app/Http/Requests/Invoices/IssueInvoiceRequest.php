<?php

namespace App\Http\Requests\Invoices;

use App\Http\Requests\Inventory\InventoryRules;
use Illuminate\Foundation\Http\FormRequest;

class IssueInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** The sale to invoice. Required on POST /invoices; taken from the URL on POST /sales/{sale}/invoice. */
            'sale_id' => [$this->route('sale') ? 'prohibited' : 'required', 'uuid'],
            /** Farm-local day; defaults to today, never before the sale or in the future. */
            'issue_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'idempotency_key' => InventoryRules::event()['idempotency_key'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
            /** @ignoreParam */
            'total_amount' => ['missing'],
            /** @ignoreParam */
            'status' => ['missing'],
            /** @ignoreParam */
            'reference' => ['missing'],
        ];
    }
}
