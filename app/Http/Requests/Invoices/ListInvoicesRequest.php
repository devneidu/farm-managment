<?php

namespace App\Http\Requests\Invoices;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListInvoicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(['issued', 'void'])],
            'payment_status' => ['sometimes', Rule::in(['unpaid', 'partially_paid', 'paid'])],
            /** true = issued, not fully paid and past its due date. */
            'overdue' => ['sometimes', 'boolean'],
            'contact_id' => ['sometimes', 'uuid'],
            'sale_id' => ['sometimes', 'uuid'],
            'search' => ['sometimes', 'string', 'max:100'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
