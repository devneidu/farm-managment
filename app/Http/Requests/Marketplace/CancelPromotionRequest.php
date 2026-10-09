<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class CancelPromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Why the promotion is being stopped (kept in the audit trail). */
            'reason' => ['required', 'string', 'min:3', 'max:200'],
        ];
    }
}
