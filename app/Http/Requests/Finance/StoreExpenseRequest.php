<?php

namespace App\Http\Requests\Finance;

class StoreExpenseRequest extends StoreTransactionRequest
{
    public function rules(): array
    {
        return ['direction' => ['sometimes', 'in:expense']] + parent::rules();
    }
}
