<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPlatformServicePaymentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(['pending', 'paid', 'failed', 'abandoned'])],
            'purpose' => ['sometimes', 'string', Rule::in(['subscription', 'promotion'])],
            'shop_id' => ['sometimes', 'uuid'],
            /** `true` = paid but no benefit granted (needs an administrator). */
            'needs_attention' => ['sometimes', 'boolean'],
            /** Part of the payment reference. */
            'q' => ['sometimes', 'string', 'max:40'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
