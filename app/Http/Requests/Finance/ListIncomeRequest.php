<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListIncomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Sales: paid | part_paid | not_paid. received = other-income rows only (they are always received). */
            'money_status' => ['sometimes', Rule::in(['paid', 'part_paid', 'not_paid', 'received'])],
            /** The buyer / contact. */
            'contact_id' => ['sometimes', 'uuid'],
            /** Matches the reference (SAL-… / TRX-…), the buyer name and the buyer phone. */
            'search' => ['sometimes', 'string', 'max:100'],
            /** The income category (sale category or the other-income category). */
            'finance_category_id' => ['sometimes', 'uuid'],
            'production_cycle_id' => ['sometimes', 'uuid'],
            /** Show cancelled sales (money_status cancelled). Default false. */
            'include_cancelled' => ['sometimes', 'boolean'],
            /** Inclusive farm-local days: a sale's date, or a transaction's occurred_on. */
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
