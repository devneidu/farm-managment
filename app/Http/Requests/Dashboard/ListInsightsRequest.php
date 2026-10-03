<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListInsightsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'severity' => ['sometimes', Rule::in(['critical', 'warning', 'info'])],
            /** Most insights to return, 1-50 (default 50). */
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
