<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class ListUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Matches name or email. */
            'q' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'in:active,suspended'],
            'platform_role' => ['sometimes', 'in:admin,support'],
            'verified' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
