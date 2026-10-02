<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class ReverseTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
            /** Farm-local day of the reversal (between the original day and today); defaults to today. */
            'occurred_on' => ['sometimes', 'date_format:Y-m-d'],
            'idempotency_key' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:-]+$/'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
        ];
    }
}
