<?php

namespace App\Http\Requests\Work;

use Illuminate\Foundation\Http\FormRequest;

class ListWorkTemplatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source' => ['sometimes', 'in:platform,farm'],
            'applies_to' => ['sometimes', 'in:production_cycle,breeding_project'],
            'is_active' => ['sometimes', 'boolean'],
            /** Recommended endpoint: exactly one of these. */
            'production_cycle_id' => ['sometimes', 'uuid'],
            'breeding_project_id' => ['sometimes', 'uuid'],
        ];
    }
}
