<?php

namespace App\Http\Requests\Inventory;

use App\Enums\InventoryCategory;
use Illuminate\Validation\Rule;

class ListItemsRequest extends InventoryRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category' => ['sometimes', Rule::enum(InventoryCategory::class)],
            'search' => ['sometimes', 'string', 'max:150'],
            'include_inactive' => ['sometimes', 'boolean'],
            /** true: only items with a threshold whose stock is at or below it. */
            'low_stock' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
