<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class StorePromotionPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:marketplace_promotion_packages,code'],
            'name' => ['required', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:365'],
            /** Naira, up to 2 decimals. */
            'amount' => ['required', 'numeric', 'gt:0', 'max:100000000', 'regex:/^\d+(\.\d{1,2})?$/'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
