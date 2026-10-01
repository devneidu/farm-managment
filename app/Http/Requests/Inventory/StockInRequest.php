<?php

namespace App\Http\Requests\Inventory;

use App\Enums\StockInReason;
use Illuminate\Validation\Rule;

class StockInRequest extends InventoryRequest
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
            /** Existing lot of this item. Use `lot` instead to receive into a (new or existing) lot by code. */
            'lot_id' => ['sometimes', 'nullable', 'uuid', 'prohibits:lot'],
            /** Lot code (and expiry for expiry-tracked items). An existing code of the same item is reused; a different expiry is rejected. */
            'lot' => ['sometimes', 'array:code,expires_on'],
            'lot.code' => ['required_with:lot', 'string', 'max:100'],
            'lot.expires_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'reason' => ['required', Rule::enum(StockInReason::class)],
            ...InventoryRules::components('components'),
            ...InventoryRules::event(),
        ];
    }
}
