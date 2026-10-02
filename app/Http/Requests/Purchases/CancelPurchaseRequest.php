<?php

namespace App\Http\Requests\Purchases;

use App\Http\Requests\Inventory\InventoryRules;
use Illuminate\Foundation\Http\FormRequest;

class CancelPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
            /** When the goods were returned / the purchase was voided (explicit offset); the stock reversal is dated here. */
            'recorded_at' => InventoryRules::event()['recorded_at'],
            'idempotency_key' => InventoryRules::event()['idempotency_key'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
        ];
    }
}
