<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class AcceptDealConfirmationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Must be true: the buyer confirms the exact terms shown on the confirmation. */
            'accept_terms' => ['required', 'accepted'],
            /** Optional phone for THIS deal only, shared with the seller after the deal exists. Digits with an optional leading +, 7-15 digits. Email is the fallback. */
            'contact_phone' => ['sometimes', 'nullable', 'string', 'regex:/^\\+?[0-9]{7,15}$/'],
        ];
    }
}
