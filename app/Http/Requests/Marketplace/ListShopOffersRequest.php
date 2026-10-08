<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListShopOffersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** `expired` includes pending offers past their deadline; `pending` excludes them. Not applicable to purchase intents. */
            'status' => ['sometimes', 'string', Rule::in(['pending', 'accepted', 'rejected', 'expired', 'voided'])],
            /** Only this listing (its id). */
            'listing' => ['sometimes', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
