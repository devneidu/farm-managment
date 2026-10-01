<?php

namespace App\Http\Requests\Inventory;

class ListLotsRequest extends InventoryRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'inventory_item_id' => ['sometimes', 'uuid'],
            /** true: only expired lots; false: only unexpired or non-expiring lots. Compared with today in the farm timezone. */
            'expired' => ['sometimes', 'boolean'],
            /** Lots with stock on hand expiring within N days (inclusive of today). */
            'expiring_within_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'include_empty' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
