<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\Work\StoreWorkTemplateRequest;
use Illuminate\Foundation\Http\FormRequest;

/** Same content shape as a farm template (items, anchors, recurrence), plus the permanent platform `code`. Created as a DRAFT; publish separately. */
class StorePlatformTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...(new StoreWorkTemplateRequest)->rules(),
            'code' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'is_active' => ['missing'],
        ];
    }
}
