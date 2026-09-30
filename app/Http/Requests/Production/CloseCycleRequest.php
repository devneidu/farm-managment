<?php

namespace App\Http\Requests\Production;

use Illuminate\Foundation\Http\FormRequest;

class CloseCycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['end_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:2000']];
    }
}
