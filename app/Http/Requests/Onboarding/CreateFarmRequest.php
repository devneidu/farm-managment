<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

class CreateFarmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim(preg_replace('/\s+/', ' ', $this->input('name')))]);
        }
    }

    public function rules(): array
    {
        return [
            // Only farm name is collected; country/currency/timezone/language use platform defaults.
            'name' => ['required', 'string', 'min:2', 'max:120'],
        ];
    }
}
