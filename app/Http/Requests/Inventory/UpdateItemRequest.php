<?php

namespace App\Http\Requests\Inventory;

use App\Enums\InventoryCategory;
use Illuminate\Validation\Rule;

class UpdateItemRequest extends InventoryRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'low_stock_threshold' => ['sometimes', 'nullable', 'array:quantity,unit'],
            'low_stock_threshold.quantity' => ['required_with:low_stock_threshold'],
            'low_stock_threshold.unit' => ['required_with:low_stock_threshold', 'string', 'max:32'],
            /** Deactivation requires zero stock. Inactive items accept no new stock, transfers or links (reversals stay possible). */
            'is_active' => ['sometimes', 'boolean'],
            /** Basis fields are locked (409 item_has_movements) once the item has any movement. */
            'category' => ['sometimes', Rule::enum(InventoryCategory::class)],
            'stock_unit' => ['sometimes', 'string', 'max:32'],
            'tracks_lots' => ['sometimes', 'boolean'],
            'tracks_expiry' => ['sometimes', 'boolean'],
        ];
    }
}
