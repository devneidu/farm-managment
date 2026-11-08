<?php

namespace App\Http\Requests\Records;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCustomRecordTypesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorised by the farm.permission:master_data.view route middleware
    }

    /** `?include_archived=true` as the other custom-data lists accept it. */
    protected function prepareForValidation(): void
    {
        $value = $this->query('include_archived');
        if (is_string($value) && in_array(strtolower($value), ['true', 'false'], true)) {
            $this->merge(['include_archived' => strtolower($value) === 'true']);
        }
    }

    public function rules(): array
    {
        return [
            /** Also list archived types (default: active only). */
            'include_archived' => ['sometimes', 'boolean'],
            'applies_to' => ['sometimes', Rule::in(['livestock', 'crop', 'both'])],
            /** `farm` = this farm's own types, `public` = the platform's. */
            'source' => ['sometimes', Rule::in(['farm', 'public'])],
        ];
    }
}
