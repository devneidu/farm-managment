<?php

namespace App\Http\Requests\Marketplace;

use App\Services\Marketplace\MarketplaceProductCatalogue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListImageLibraryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_kind' => ['sometimes', 'string', Rule::in(MarketplaceProductCatalogue::kinds())],
        ];
    }
}
