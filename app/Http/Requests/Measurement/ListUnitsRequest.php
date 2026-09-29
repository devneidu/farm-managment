<?php

namespace App\Http\Requests\Measurement;

use Illuminate\Foundation\Http\FormRequest;

/** Query for `GET /master/units`. `dimension` is mandatory on purpose: the API never returns one giant unit list. */
class ListUnitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorised by the farm.permission:measurement.view route middleware
    }

    protected function prepareForValidation(): void
    {
        $value = $this->query('include_inactive');
        if (is_string($value) && in_array(strtolower($value), ['true', 'false'], true)) {
            $this->merge(['include_inactive' => strtolower($value) === 'true']);
        }
    }

    public function rules(): array
    {
        return [
            'dimension' => ['required', 'string', 'exists:measurement_dimensions,code'],
            'family' => ['sometimes', 'string', 'max:32'],
            'include_inactive' => ['sometimes', 'boolean'],
        ];
    }
}
