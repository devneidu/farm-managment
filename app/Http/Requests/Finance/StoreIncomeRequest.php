<?php

namespace App\Http\Requests\Finance;

class StoreIncomeRequest extends StoreTransactionRequest
{
    public function rules(): array
    {
        return ['direction' => ['sometimes', 'in:income']] + parent::rules();
    }
}
