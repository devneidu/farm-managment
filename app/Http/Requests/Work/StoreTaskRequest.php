<?php

namespace App\Http\Requests\Work;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...WorkRules::work(),
            /** Farm-local date. Past dates are allowed (the task is simply overdue). */
            'due_date' => ['required', 'date_format:Y-m-d'],
            'production_cycle_id' => ['sometimes', 'nullable', 'uuid'],
            /** Implies the project's cycle. */
            'breeding_project_id' => ['sometimes', 'nullable', 'uuid'],
            /** An active member of this farm. */
            'assigned_user_id' => ['sometimes', 'nullable', 'uuid'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:80'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
            /** @ignoreParam */
            'status' => ['missing'],
            /** @ignoreParam */
            'completed_at' => ['missing'],
        ];
    }
}
