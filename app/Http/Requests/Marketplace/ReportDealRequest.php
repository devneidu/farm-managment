<?php

namespace App\Http\Requests\Marketplace;

use App\Services\Marketplace\MarketplaceDealLifecycle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** `deal` reports the deal itself; `other_party` reports the person on the other side. */
            'target' => ['required', Rule::in(MarketplaceDealLifecycle::REPORT_TARGETS)],
            'reason' => ['required', Rule::in(MarketplaceDealLifecycle::REPORT_REASONS)],
            /** Optional detail, kept with the deal for platform admins. Never shown to the other party. */
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
