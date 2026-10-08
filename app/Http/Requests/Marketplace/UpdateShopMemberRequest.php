<?php

namespace App\Http\Requests\Marketplace;

use App\Enums\ShopRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateShopMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** manager | staff */
            'role' => ['required', 'string', Rule::in(array_map(fn (ShopRole $r) => $r->value, ShopRole::assignable()))],
        ];
    }
}
