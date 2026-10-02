<?php

namespace App\Http\Requests\Purchases;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPurchasesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(['active', 'cancelled'])],
            'contact_id' => ['sometimes', 'uuid'],
            'production_cycle_id' => ['sometimes', 'uuid'],
            'search' => ['sometimes', 'string', 'max:100'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
