<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePromotionPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            /** Applies to FUTURE purchases only. */
            'duration_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'amount' => ['sometimes', 'numeric', 'gt:0', 'max:100000000', 'regex:/^\d+(\.\d{1,2})?$/'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
