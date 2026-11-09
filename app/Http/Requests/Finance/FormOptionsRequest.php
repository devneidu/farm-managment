<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FormOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Which form: the Income form or the Expense form. */
            'direction' => ['required', Rule::in(['income', 'expense'])],
        ];
    }
}
