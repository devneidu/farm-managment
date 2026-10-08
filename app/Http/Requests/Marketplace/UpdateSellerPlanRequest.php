<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSellerPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            /** Applies to FUTURE purchases of a paid plan; a period already paid for keeps its limit. For the free plan it applies at once. */
            'listing_limit' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            /** A paid plan can be switched on only when it has a limit and at least one active price. */
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
