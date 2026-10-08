<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Quantity in the listing's selling unit (decimal string). */
            'quantity' => ['required', 'regex:/^\d+(\.\d+)?$/'],
        ];
    }
}
