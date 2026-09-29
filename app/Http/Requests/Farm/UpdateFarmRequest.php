<?php

namespace App\Http\Requests\Farm;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFarmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorised by the farm.permission:farm.update route middleware
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim(preg_replace('/\s+/', ' ', $this->input('name')))]);
        }
    }

    public function rules(): array
    {
        // Only the farm name is editable; country/currency/timezone/language keep platform defaults.
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
        ];
    }
}
