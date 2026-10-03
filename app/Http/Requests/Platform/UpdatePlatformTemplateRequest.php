<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\Work\UpdateWorkTemplateRequest;
use Illuminate\Foundation\Http\FormRequest;

/** Every field optional; a supplied `items` list replaces the previous one. Editing a PUBLISHED template raises its version. `code` and `applies_to` are permanent. */
class UpdatePlatformTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [...(new UpdateWorkTemplateRequest)->rules(), 'code' => ['missing'], 'is_active' => ['missing']];
    }
}
