<?php

namespace App\Http\Requests\Contacts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListContactsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Contacts holding this role (a supplier-and-customer appears under both). */
            'role' => ['sometimes', Rule::in(['supplier', 'customer'])],
            'search' => ['sometimes', 'string', 'max:100'],
            /** 1 includes inactive contacts. */
            'include_inactive' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
