<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class ShopVerificationDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** verified | rejected (from a pending request) | unverified (revokes the badge) */
            'decision' => ['required', 'string', 'in:verified,rejected,unverified'],
            /** Required for `rejected` and `unverified`. */
            'reason' => ['required_if:decision,rejected,unverified', 'nullable', 'string', 'min:3', 'max:500'],
        ];
    }
}
