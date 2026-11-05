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
            /** Day 1 of the routine (offset 0). Defaults to the batch start date for batch routines and today for general ones. */
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            /** Required (true) to apply a routine that is already applied to the same target. */
            'confirm_reapply' => ['sometimes', 'boolean'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:80'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
        ];
    }
}
