<?php

namespace App\Http\Requests\Team;

use App\Enums\FarmRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::enum(FarmRole::class)],
        ];
    }
}
