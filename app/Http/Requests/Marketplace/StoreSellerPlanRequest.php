<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class StoreSellerPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:marketplace_seller_plans,code'],
            'name' => ['required', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            /** Published listings allowed at once while this plan's period runs. */
            'listing_limit' => ['required', 'integer', 'min:1', 'max:100000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
