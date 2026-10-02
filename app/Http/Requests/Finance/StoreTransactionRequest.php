<?php

namespace App\Http\Requests\Finance;

use App\Rules\MoneyAmount;
use App\Services\Finance\FinanceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** income or expense. */
            'direction' => ['required', Rule::in(['income', 'expense'])],
            /** An active platform or farm category of the same direction (GET /finance/categories). Optional only when the source is a purchase. */
            'finance_category_id' => ['sometimes', 'nullable', 'uuid'],
            /** Positive amount in the farm currency, at most two decimals, sent as a string ("1500.50"). */
            'amount' => ['required', new MoneyAmount],
            /** Farm-local business day, not in the future. */
            'occurred_on' => ['required', 'date_format:Y-m-d'],
            /** When it was entered/happened (explicit offset); defaults to now. Kept separate from created_at. */
            'recorded_at' => ['sometimes', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/'],
            'contact_id' => ['sometimes', 'nullable', 'uuid'],
            /** Allocates the money to an open production cycle (cost/revenue of that cycle). A linked source's own cycle is used when omitted. */
            'production_cycle_id' => ['sometimes', 'nullable', 'uuid'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            /** "Record as expense/income" for an existing event. One live transaction per source; a second attempt is 409 finance_already_recorded. */
            'source' => ['sometimes', 'array:type,id'],
            'source.type' => ['required_with:source', Rule::in(FinanceService::SOURCES)],
            'source.id' => ['required_with:source', 'uuid'],
            /** Replace a reversed transaction once (same direction and source). Needs finance.reverse. */
            'corrects_transaction_id' => ['sometimes', 'nullable', 'uuid'],
            'idempotency_key' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:-]+$/'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
            /** @ignoreParam */
            'created_by' => ['missing'],
            /** @ignoreParam */
            'currency' => ['missing'],
            /** @ignoreParam */
            'entry_type' => ['missing'],
            /** @ignoreParam */
            'reference' => ['missing'],
        ];
    }
}
