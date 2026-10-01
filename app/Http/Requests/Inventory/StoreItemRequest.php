<?php

namespace App\Http\Requests\Inventory;

use App\Enums\InventoryCategory;
use Illuminate\Validation\Rule;

class StoreItemRequest extends InventoryRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'category' => ['required', Rule::enum(InventoryCategory::class)],
            /** Unit code (kg, g, l, ml, piece, egg, head, planting_unit...). Fixes the item's measurement basis; packages never. */
            'stock_unit' => ['required', 'string', 'max:32'],
            'tracks_lots' => ['sometimes', 'boolean'],
            /** Expiry tracking requires lot tracking: expiry belongs to a lot. */
            'tracks_expiry' => ['sometimes', 'boolean'],
            'low_stock_threshold' => ['sometimes', 'nullable', 'array:quantity,unit'],
            'low_stock_threshold.quantity' => ['required_with:low_stock_threshold'],
            'low_stock_threshold.unit' => ['required_with:low_stock_threshold', 'string', 'max:32'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
