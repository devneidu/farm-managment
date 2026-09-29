<?php

namespace App\Http\Requests\Measurement;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMeasurementContextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorised by the farm.permission:measurement.manage route middleware
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim(preg_replace('/\s+/u', ' ', $this->input('name')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:100', "regex:/^[\\pL\\pN][\\pL\\pN \\-'.&()\\/]*$/u"],
            'is_active' => ['sometimes', 'boolean'],
            'farm_id' => ['missing'],
            'id' => ['missing'],
        ];
    }
}
