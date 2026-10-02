<?php

namespace App\Http\Requests\Contacts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'kind' => ['sometimes', Rule::in(['person', 'business'])],
            /** One or both of supplier / customer. A contact that is both stays ONE record. */
            'roles' => ['required', 'array', 'min:1', 'max:2'],
            'roles.*' => ['required', 'distinct', Rule::in(['supplier', 'customer'])],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
            /** @ignoreParam */
            'created_by' => ['missing'],
        ];
    }
}
