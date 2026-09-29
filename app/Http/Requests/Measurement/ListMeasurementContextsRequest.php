<?php

namespace App\Http\Requests\Measurement;

use Illuminate\Foundation\Http\FormRequest;

class ListMeasurementContextsRequest extends FormRequest
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
        return ['include_inactive' => ['sometimes', 'boolean']];
    }
}
