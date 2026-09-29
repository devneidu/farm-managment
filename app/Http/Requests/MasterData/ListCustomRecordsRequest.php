<?php

namespace App\Http\Requests\MasterData;

use Illuminate\Foundation\Http\FormRequest;

/** Filters for GET /custom-breeds and GET /custom-varieties. */
class ListCustomRecordsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorised by the farm.permission:master_data.view route middleware
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
            'species_id' => ['sometimes', 'string', 'max:36'],
            'crop_type_id' => ['sometimes', 'string', 'max:36'],
            'include_inactive' => ['sometimes', 'boolean'],
        ];
    }
}
