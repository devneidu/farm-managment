<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetSellerPlanPricesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'prices' => ['required', 'array', 'min:1', 'max:2'],
            'prices.*.interval_days' => ['required', 'integer', Rule::in([30, 365]), 'distinct'],
            /** Naira, up to 2 decimals. `null` removes the price (that period can no longer be bought). */
            'prices.*.amount' => ['present', 'nullable', 'numeric', 'gt:0', 'max:100000000', 'regex:/^\d+(\.\d{1,2})?$/'],
            'prices.*.is_active' => ['sometimes', 'boolean'],
        ];
    }
}
