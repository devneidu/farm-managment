<?php

namespace App\Http\Requests\Contacts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'kind' => ['sometimes', Rule::in(['person', 'business'])],
            'roles' => ['sometimes', 'array', 'min:1', 'max:2'],
            'roles.*' => ['required', 'distinct', Rule::in(['supplier', 'customer'])],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            /** false = deactivate (history is kept; inactive contacts cannot be chosen on new purchases or transactions). */
            'is_active' => ['sometimes', 'boolean'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
            /** @ignoreParam */
            'created_by' => ['missing'],
        ];
    }
}
