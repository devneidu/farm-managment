<?php

namespace App\Http\Requests\Health;

use Illuminate\Foundation\Http\FormRequest;

class ListWithdrawalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'production_cycle_id' => ['sometimes', 'uuid'],
            'inventory_item_id' => ['sometimes', 'uuid'],
            /** 1 (default) = withdrawal windows still running now; 0 = include finished windows. Reversed records never count. */
            'active' => ['sometimes', 'in:0,1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
