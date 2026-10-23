<?php

namespace App\Http\Requests\Finance;

use App\Models\Payment;
use App\Services\Finance\FinanceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListTransactionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'direction' => ['sometimes', Rule::in(['income', 'expense'])],
            'entry_type' => ['sometimes', Rule::in(['entry', 'reversal'])],
            'finance_category_id' => ['sometimes', 'uuid'],
            'contact_id' => ['sometimes', 'uuid'],
            /** How the money moved: cash, bank_transfer, pos, mobile_money, cheque or other. */
            'payment_method' => ['sometimes', Rule::in(Payment::METHODS)],
            'production_cycle_id' => ['sometimes', 'uuid'],
            'source_type' => ['sometimes', Rule::in(FinanceService::FILTER_SOURCES)],
            'source_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
