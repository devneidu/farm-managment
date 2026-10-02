<?php

namespace App\Http\Requests\Work;

use Illuminate\Foundation\Http\FormRequest;

class CancelTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:2000']];
    }
}
