<?php

namespace App\Http\Requests\Marketplace;

use App\Models\MarketplaceShop;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPublicShopsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('verified')) {
            $this->merge(['verified' => filter_var($this->input('verified'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)]);
        }
    }

    public function rules(): array
    {
        return [
            /** Matches shop name, tagline or description. */
            'q' => ['sometimes', 'string', 'max:100'],
            'state' => ['sometimes', 'string', 'max:60'],
            'city' => ['sometimes', 'string', 'max:80'],
            'category' => ['sometimes', 'string', Rule::in(MarketplaceShop::CATEGORIES)],
            'seller_type' => ['sometimes', 'string', Rule::in(MarketplaceShop::SELLER_TYPES)],
            /** true = verified shops only; false = unverified only. */
            'verified' => ['sometimes', 'boolean'],
            /** newest (default, by approval date) | name */
            'sort' => ['sometimes', 'in:newest,name'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
