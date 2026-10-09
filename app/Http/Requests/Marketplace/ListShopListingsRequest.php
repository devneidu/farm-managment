<?php

namespace App\Http\Requests\Marketplace;

use App\Enums\ListingStatus;
use App\Services\Marketplace\MarketplaceProductCatalogue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListShopListingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(array_map(fn ($s) => $s->value, ListingStatus::cases()))],
            'product_kind' => ['sometimes', 'string', Rule::in(MarketplaceProductCatalogue::kinds())],
            /** Matches title, reference or product name. */
            'q' => ['sometimes', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
