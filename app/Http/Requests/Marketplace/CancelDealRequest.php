<?php

namespace App\Http\Requests\Marketplace;

use App\Services\Marketplace\MarketplaceDealLifecycle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CancelDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** A reason code. */
            'reason' => ['required', Rule::in(MarketplaceDealLifecycle::CANCEL_REASONS)],
            /** Optional short note shown to the other party and platform admins. */
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
