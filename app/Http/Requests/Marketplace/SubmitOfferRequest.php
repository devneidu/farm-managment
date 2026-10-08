<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class SubmitOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Quantity in the listing's selling unit (decimal string): the unit's precision, the minimum order and the declared available quantity apply. */
            'quantity' => ['required', 'regex:/^\d+(\.\d+)?$/'],
            /** Proposed price per ONE unit in naira (at most 2 decimals). Strictly below the listed unit price and not below the platform floor (`marketplace_min_offer_percent`). */
            'unit_price' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
        ];
    }

    public function messages(): array
    {
        return ['unit_price.regex' => 'Enter a price in naira with at most 2 decimals.', 'quantity.regex' => 'Enter the quantity as a number.'];
    }
}
