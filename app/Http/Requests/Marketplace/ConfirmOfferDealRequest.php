<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmOfferDealRequest extends FormRequest
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
            /** Optional phone for THIS deal only, shared with the seller after the deal exists. Digits with an optional leading +, 7-15 digits. Email is the fallback. */
            'contact_phone' => ['sometimes', 'nullable', 'string', 'regex:/^\\+?[0-9]{7,15}$/'],
        ];
    }
}
