<?php

namespace App\Http\Requests\Health;

use Illuminate\Foundation\Http\FormRequest;

class ListMedicinesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:100'],
            'include_inactive' => ['sometimes', 'in:0,1'],
            'low_stock' => ['sometimes', 'in:0,1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
