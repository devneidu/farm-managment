<?php

namespace App\Http\Requests\Records;

use Illuminate\Foundation\Http\FormRequest;

class CopyCustomRecordTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorised by the farm.permission:master_data.manage route middleware
    }

    public function rules(): array
    {
        return [
            /** Default: the public type's own name. */
            'name' => ['sometimes', 'nullable', 'string', 'min:2', 'max:80'],
        ];
    }
}
