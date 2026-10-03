<?php

namespace App\Http\Requests\Account;

use App\Services\Localization\LocaleCatalogue;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('locale'))) {
            $this->merge(['locale' => strtolower(trim($this->input('locale')))]);
        }
    }

    public function rules(): array
    {
        $enabled = app(LocaleCatalogue::class)->enabledCodes();

        return [
            // null clears the explicit choice (follow Accept-Language / the platform default).
            'locale' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', $enabled)],
        ];
    }

    public function messages(): array
    {
        return ['locale.in' => 'That language is not available yet.'];
    }
}
