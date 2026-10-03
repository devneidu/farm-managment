<?php

namespace App\Http\Requests\Payments;

use App\Http\Requests\Inventory\InventoryRules;
use App\Models\Payment;
use App\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Money actually received, in the farm currency, a string with at most two decimals; may not exceed the invoice balance. */
            'amount' => ['required', new MoneyAmount],
            /** @var 'cash'|'bank_transfer'|'pos'|'mobile_money'|'cheque'|'other' */
            'method' => ['required', Rule::in(Payment::METHODS)],
            /** Bank/transfer/receipt reference. */
            'payment_reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            /** Farm-local day the money was received; not in the future. */
            'received_on' => ['required', 'date_format:Y-m-d'],
            /** When it was entered (explicit offset); defaults to now. Kept separate from created_at. */
            'recorded_at' => ['sometimes', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/'],
            /** Income category for the ledger entry; defaults to the sale category. */
            'finance_category_id' => ['sometimes', 'nullable', 'uuid'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'idempotency_key' => InventoryRules::event()['idempotency_key'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
            /** @ignoreParam */
            'invoice_id' => ['missing'],
            /** @ignoreParam */
            'currency' => ['missing'],
            /** @ignoreParam */
            'entry_type' => ['missing'],
            /** @ignoreParam */
            'reference' => ['missing'],
        ];
    }
}
