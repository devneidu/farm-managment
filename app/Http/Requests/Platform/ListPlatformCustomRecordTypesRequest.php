<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class ListPlatformCustomRecordTypesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** `farm` (default) = types farms made; `public` = the platform's own. */
            'source' => ['sometimes', 'in:farm,public'],
            'status' => ['sometimes', 'in:active,archived'],
            'applies_to' => ['sometimes', 'in:livestock,crop,both'],
            /** Matches the name. */
            'q' => ['sometimes', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
