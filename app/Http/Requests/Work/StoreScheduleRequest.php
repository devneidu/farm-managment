<?php

namespace App\Http\Requests\Work;

use Illuminate\Foundation\Http\FormRequest;

class StoreScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recurrence' => ['required', 'in:none,daily,weekly'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'production_cycle_id' => ['sometimes', 'nullable', 'uuid'],
            'breeding_project_id' => ['sometimes', 'nullable', 'uuid'],
            'assigned_user_id' => ['sometimes', 'nullable', 'uuid'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:80'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
            /** @ignoreParam */
            'status' => ['missing'],
            ...WorkRules::work(),
            ...WorkRules::recurrence(),
        ];
    }
}
