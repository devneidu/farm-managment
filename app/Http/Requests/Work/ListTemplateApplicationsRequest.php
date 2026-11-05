<?php

namespace App\Http\Requests\Work;

use Illuminate\Foundation\Http\FormRequest;

class ListTemplateApplicationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'production_cycle_id' => ['sometimes', 'uuid'],
            'breeding_project_id' => ['sometimes', 'uuid'],
            /** general = routines applied with no batch. */
            'target' => ['sometimes', 'in:general'],
            'work_template_id' => ['sometimes', 'uuid'],
            /** Defaults to active. */
            'status' => ['sometimes', 'in:active,removed,all'],
        ];
    }
}
