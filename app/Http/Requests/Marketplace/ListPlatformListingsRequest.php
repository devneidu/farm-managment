<?php

namespace App\Http\Requests\Marketplace;

use App\Enums\ListingStatus;
use App\Services\Marketplace\MarketplaceProductCatalogue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPlatformListingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Matches title, reference or slug. */
            'q' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(array_map(fn ($s) => $s->value, ListingStatus::cases()))],
            'shop_id' => ['sometimes', 'uuid'],
            'product_kind' => ['sometimes', 'string', Rule::in(MarketplaceProductCatalogue::kinds())],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
