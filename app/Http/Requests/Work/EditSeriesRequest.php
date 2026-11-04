<?php

namespace App\Http\Requests\Work;

use Illuminate\Foundation\Http\FormRequest;

/** "Edit this and all future": every field is optional and overrides what the old schedule had; omitted fields (and repeat) carry over. */
class EditSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [];
        foreach (WorkRules::work() as $field => $rule) {
            $rules[$field] = array_map(fn ($r) => $r === 'required' ? 'sometimes' : $r, $rule);
        }

        return $rules + WorkRules::reminders() + WorkRules::repeat() + [
            /** New first date of the series; defaults to this task's occurrence date and may not be earlier. */
            'due_date' => ['sometimes', 'date_format:Y-m-d'],
            'assigned_user_id' => ['sometimes', 'nullable', 'uuid'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:80'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
            /** @ignoreParam */
            'status' => ['missing'],
            /** @ignoreParam */
            'production_cycle_id' => ['missing'],
            /** @ignoreParam */
            'breeding_project_id' => ['missing'],
        ];
    }
}
