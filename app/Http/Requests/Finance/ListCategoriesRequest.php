<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCategoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['direction' => ['sometimes', Rule::in(['income', 'expense'])]];
    }
}
