<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\Inventory\InventoryRules;
use Illuminate\Foundation\Http\FormRequest;

class CancelSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
            /** When the goods/animals came back (explicit offset); the stock and population compensation is dated here. */
            'recorded_at' => InventoryRules::event()['recorded_at'],
            'idempotency_key' => InventoryRules::event()['idempotency_key'],
            /** Also reverse every live payment on the sale's invoice in the same transaction. Needs payment.reverse as well as sale.cancel. Without it a sale with payments is refused (409 sale_has_payments). */
            'reverse_payments' => ['sometimes', 'boolean'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
        ];
    }
}
