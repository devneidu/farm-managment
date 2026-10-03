<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class SetPlanEntitlementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Keys are Feature registry values, for example `advanced_reports`. */
            'features' => ['sometimes', 'array'],
            'features.*' => ['boolean'],
            /** Keys are Limit registry values. */
            'limits' => ['sometimes', 'array'],
            'limits.*' => ['array'],
            /** Either a number, or `unlimited: true` with no number. */
            'limits.*.limit' => ['nullable', 'integer', 'min:0', 'max:1000000', 'required_without:limits.*.unlimited'],
            'limits.*.unlimited' => ['sometimes', 'boolean'],
        ];
    }
}
