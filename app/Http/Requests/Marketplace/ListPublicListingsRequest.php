<?php

namespace App\Http\Requests\Marketplace;

use App\Services\Marketplace\MarketplaceProductCatalogue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPublicListingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['verified', 'negotiable'] as $flag) {
            if ($this->has($flag)) {
                $this->merge([$flag => filter_var($this->input($flag), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)]);
            }
        }
    }

    public function rules(): array
    {
        return [
            /** Matches title, description, product name, species and crop name. */
            'q' => ['sometimes', 'string', 'max:100'],
            'product_kind' => ['sometimes', 'string', Rule::in(MarketplaceProductCatalogue::kinds())],
            /** Species code from the master data (for example chicken). */
            'species' => ['sometimes', 'string', 'max:60'],
            /** Crop type code from the master data (for example yam). */
            'crop_type' => ['sometimes', 'string', 'max:60'],
            'state' => ['sometimes', 'string', 'max:60'],
            'city' => ['sometimes', 'string', 'max:80'],
            /** Naira per selling unit. Prices of different units are not comparable: combine with `unit`. */
            'min_price' => ['sometimes', 'numeric', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'max_price' => ['sometimes', 'numeric', 'regex:/^\d{1,10}(\.\d{1,2})?$/', Rule::when($this->filled('min_price'), 'gte:min_price')],
            /** Selling unit code (kg, head, crate...). */
            'unit' => ['sometimes', 'string', 'max:30'],
            'negotiable' => ['sometimes', 'boolean'],
            /** pickup | seller_delivery: listings that offer it (a listing offering both matches either). */
            'fulfilment' => ['sometimes', 'string', Rule::in(['pickup', 'seller_delivery'])],
            /** A state or city the seller delivers to. */
            'delivers_to' => ['sometimes', 'string', 'max:80'],
            /** Shop slug. */
            'shop' => ['sometimes', 'string', 'max:100'],
            'verified' => ['sometimes', 'boolean'],
            /** newest (default) | price_asc | price_desc | relevance (ranks title matches first; reads as newest without `q`). */
            'sort' => ['sometimes', 'in:newest,price_asc,price_desc,relevance'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
