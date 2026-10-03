<?php

namespace App\Http\Requests\Invoices;

use App\Http\Requests\Inventory\InventoryRules;
use Illuminate\Foundation\Http\FormRequest;

class VoidInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => InventoryRules::event()['idempotency_key'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
        ];
    }
}
