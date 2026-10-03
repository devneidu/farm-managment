<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class SetPlanPricesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'prices' => ['required', 'array', 'min:1', 'max:2'],
            'prices.*.interval' => ['required', 'in:monthly,annual', 'distinct'],
            /** Integer kobo. */
            'prices.*.amount_minor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'prices.*.is_active' => ['sometimes', 'boolean'],
        ];
    }
}
