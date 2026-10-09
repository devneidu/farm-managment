<?php

namespace App\Http\Requests\Marketplace;

use App\Models\MarketplaceShop;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShopRequest extends FormRequest
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
            /** Public shop name, unique among the caller's own shops. */
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            /** individual | business | farm */
            'seller_type' => ['required', 'string', Rule::in(MarketplaceShop::SELLER_TYPES)],
            /** Up to 7 distinct values of livestock, crops, eggs_dairy, feed_inputs, equipment, processed_goods, services. */
            'categories' => ['sometimes', 'array', 'max:7'],
            'categories.*' => ['string', 'distinct', Rule::in(MarketplaceShop::CATEGORIES)],
            /** ISO 3166-1 alpha-2; defaults to NG. */
            'country_code' => ['sometimes', 'string', 'size:2', 'alpha'],
            'state' => ['sometimes', 'nullable', 'string', 'max:60'],
            'city' => ['sometimes', 'nullable', 'string', 'max:80'],
            /** Public neighbourhood or landmark (not the street address). */
            'area' => ['sometimes', 'nullable', 'string', 'max:120'],
            /** Optional farm to link. The caller must be an active member of it with `marketplace.manage`; a farm has at most one shop; cannot be changed later. */
            'farm_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
