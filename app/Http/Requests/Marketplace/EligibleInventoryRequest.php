<?php

namespace App\Http\Requests\Marketplace;

use App\Services\Marketplace\MarketplaceProductCatalogue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EligibleInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Only items that can back a listing of this kind. */
            'product_kind' => ['sometimes', 'string', Rule::in(MarketplaceProductCatalogue::INVENTORY_KINDS)],
        ];
    }
}
