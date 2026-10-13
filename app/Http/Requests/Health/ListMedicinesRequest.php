<?php

namespace App\Http\Requests\Health;

use App\Enums\MedicineType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            /** antibiotic | vaccine | supplement | dewormer | other. Only medicines whose profile has that type; medicines with no type are excluded. Omit for all. */
            'medicine_type' => ['sometimes', Rule::enum(MedicineType::class)],
            'include_inactive' => ['sometimes', 'in:0,1'],
            'low_stock' => ['sometimes', 'in:0,1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
