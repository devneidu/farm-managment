<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Required when the listing offers both pickup and seller delivery; otherwise implied by the listing. */
            'fulfilment_method' => ['sometimes', 'nullable', Rule::in(['pickup', 'seller_delivery'])],
            /** Optional delivery charge in naira (at most 2 decimals) the seller proposes, only for seller delivery on a listing whose charge is agreed separately. Kept apart from the product total. Omit when unknown: it then reads "To be agreed directly". */
            'delivery_charge' => ['sometimes', 'nullable', 'regex:/^\\d{1,10}(\\.\\d{1,2})?$/'],
        ];
    }

    public function messages(): array
    {
        return ['delivery_charge.regex' => 'Enter the delivery charge in naira with at most 2 decimals.'];
    }
}
