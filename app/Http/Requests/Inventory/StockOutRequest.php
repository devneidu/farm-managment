<?php

namespace App\Http\Requests\Inventory;

use App\Enums\StockOutReason;
use Illuminate\Validation\Rule;

class StockOutRequest extends InventoryRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'inventory_item_id' => ['required', 'uuid'],
            'storage_location_id' => ['required', 'uuid'],
            /** Required for lot-tracked items. There is no automatic FIFO/FEFO: the caller names the lot. */
            ...InventoryRules::lotChoice(),
            'reason' => ['required', Rule::enum(StockOutReason::class)],
            ...InventoryRules::components('components'),
            ...InventoryRules::event(),
        ];
    }
}
