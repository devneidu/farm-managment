<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class SummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Inclusive farm-local days on occurred_on. */
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            /** Cycle profitability: only money allocated to this cycle. */
            'production_cycle_id' => ['sometimes', 'uuid'],
        ];
    }
}
