<?php

namespace App\Http\Requests\Measurement;

use App\Enums\ConversionContextType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPackageConversionsRequest extends FormRequest
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
            'context_type' => ['sometimes', 'string', Rule::enum(ConversionContextType::class)],
            'context_id' => ['sometimes', 'string', 'max:36'],
            'package_unit' => ['sometimes', 'string', 'max:32'],
            'include_inactive' => ['sometimes', 'boolean'],
        ];
    }
}
