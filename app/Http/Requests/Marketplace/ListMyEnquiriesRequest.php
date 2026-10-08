<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListMyEnquiriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kind' => ['sometimes', 'string', Rule::in(['offer', 'purchase_intent'])],
            /** Offers only (a status implies `kind=offer`). `expired` includes pending offers past their deadline. */
            'status' => ['sometimes', 'string', Rule::in(['pending', 'accepted', 'rejected', 'expired', 'voided'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
