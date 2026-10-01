<?php

namespace App\Http\Requests\Inventory;

class StoreFormulaRequest extends InventoryRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'species_id' => ['sometimes', 'nullable', 'uuid'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            /** Ingredients as percentages of the mix; they must add up to exactly 100. A formula never holds or changes stock. */
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['required', 'array:ingredient_name,inclusion_percent,inventory_item_id'],
            'items.*.ingredient_name' => ['required', 'string', 'max:150'],
            'items.*.inclusion_percent' => ['required'],
            'items.*.inventory_item_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
