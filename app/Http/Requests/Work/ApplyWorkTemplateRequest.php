<?php

namespace App\Http\Requests\Work;

use Illuminate\Foundation\Http\FormRequest;

class ApplyWorkTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Required for applies_to=production_cycle templates. */
            'production_cycle_id' => ['sometimes', 'nullable', 'uuid'],
            /** Required for applies_to=breeding_project templates. */
            'breeding_project_id' => ['sometimes', 'nullable', 'uuid'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:80'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
        ];
    }
}
