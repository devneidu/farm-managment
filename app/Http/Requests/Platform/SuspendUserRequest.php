<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class SuspendUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Kept in the audit trail. */
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
