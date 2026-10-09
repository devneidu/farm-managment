<?php

namespace App\Http\Requests\Marketplace;

use App\Models\MarketplaceShop;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('country_code'))) {
            $this->merge(['country_code' => strtoupper($this->input('country_code'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'seller_type' => ['sometimes', 'string', Rule::in(MarketplaceShop::SELLER_TYPES)],
            /** Replaces the whole list. */
            'categories' => ['sometimes', 'array', 'max:7'],
            'categories.*' => ['string', 'distinct', Rule::in(MarketplaceShop::CATEGORIES)],
            'country_code' => ['sometimes', 'string', 'size:2', 'alpha'],
            'state' => ['sometimes', 'nullable', 'string', 'max:60'],
            'city' => ['sometimes', 'nullable', 'string', 'max:80'],
            'area' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }
}
