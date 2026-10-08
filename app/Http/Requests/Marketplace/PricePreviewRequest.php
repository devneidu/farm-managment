<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class PricePreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Quantity in the listing's selling unit (decimal string). Within the minimum order and the declared available quantity. */
            'quantity' => ['required', 'regex:/^\d+(\.\d+)?$/'],
        ];
    }
}
