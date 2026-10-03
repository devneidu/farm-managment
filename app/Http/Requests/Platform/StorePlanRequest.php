<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class StorePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Stable machine identity; cannot be changed later. */
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9-]*$/', 'unique:plans,slug'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            /** Fixed to NGN for now. */
            'currency' => ['sometimes', 'in:NGN'],
            'is_public' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            /** @ignoreParam */
            'is_active' => ['missing'],
            /** @ignoreParam */
            'is_default' => ['missing'],
        ];
    }
}
