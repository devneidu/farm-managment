<?php

namespace App\Http\Requests\Inventory;

class TransferStockRequest extends InventoryRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'inventory_item_id' => ['required', 'uuid'],
            'from_storage_location_id' => ['required', 'uuid'],
            'to_storage_location_id' => ['required', 'uuid', 'different:from_storage_location_id'],
            /** Required for lot-tracked items; the same lot arrives at the destination. */
            ...InventoryRules::lotChoice(),
            ...InventoryRules::components('components'),
            ...InventoryRules::event(),
        ];
    }
}
