<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
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
        // Email is read-only in Phase 2 (changing it needs re-verification); verification/onboarding
        // state is never client-writable.
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
        ];
    }
}
