<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class SetCapabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            /** Allowed keys depend on the capability (see GET /platform-admin/master/capabilities); null or an empty object clears it. */
            'reference_config' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
