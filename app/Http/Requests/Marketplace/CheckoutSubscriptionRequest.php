<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** The seller plan to buy (from `GET shops/{shop}/plans`). */
            'plan_id' => ['required', 'uuid'],
            /** Prepaid period in days: 30 or 365. */
            'interval_days' => ['required', 'integer', Rule::in([30, 365])],
        ];
    }
}
