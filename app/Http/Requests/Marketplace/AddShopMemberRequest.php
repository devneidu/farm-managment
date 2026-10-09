<?php

namespace App\Http\Requests\Marketplace;

use App\Enums\ShopRole;
use App\Http\Requests\Concerns\NormalizesEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddShopMemberRequest extends FormRequest
{
    use NormalizesEmail;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Email of an existing, verified account. */
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            /** manager | staff */
            'role' => ['required', 'string', Rule::in(array_map(fn (ShopRole $r) => $r->value, ShopRole::assignable()))],
        ];
    }
}
