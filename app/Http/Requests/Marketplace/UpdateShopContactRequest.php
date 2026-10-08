<?php

namespace App\Http\Requests\Marketplace;

use App\Models\MarketplaceShop;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateShopContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('contact_email'))) {
            $this->merge(['contact_email' => strtolower(trim($this->input('contact_email')))]);
        }
    }

    public function rules(): array
    {
        return [
            /** PRIVATE street address; never in a public response. */
            'address_line' => ['sometimes', 'nullable', 'string', 'max:255'],
            /** Digits with an optional leading +, 7-15 digits. PRIVATE. */
            'contact_phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
            'contact_whatsapp' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
            'contact_email' => ['sometimes', 'nullable', 'email:rfc', 'max:254'],
            /** in_app | phone | whatsapp | email. A phone/whatsapp/email choice needs that channel configured. */
            'preferred_contact_method' => ['sometimes', 'nullable', 'string', Rule::in(MarketplaceShop::CONTACT_METHODS)],
        ];
    }
}
