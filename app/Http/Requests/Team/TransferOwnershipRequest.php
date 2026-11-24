<?php

namespace App\Http\Requests\Team;

use Illuminate\Foundation\Http\FormRequest;

class TransferOwnershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The membership id from GET /farm/members (the `id` of a member row), not the user id.
            'member_id' => ['required', 'uuid'],
        ];
    }
}
