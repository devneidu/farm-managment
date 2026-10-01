<?php

namespace App\Http\Requests\Inventory;

class AdjustStockRequest extends InventoryRequest
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
            ...InventoryRules::lotChoice(),
            /** The ledger balance the client believes exists; a stale value is rejected (409 stock_changed). */
            ...InventoryRules::components('expected'),
            /** The physically counted stock (zero allowed). The server records counted minus expected as an adjustment. */
            ...InventoryRules::components('counted'),
            'reason' => ['required', 'string', 'max:2000'],
            ...InventoryRules::event(),
        ];
    }
}
