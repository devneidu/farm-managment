<?php

namespace App\Http\Requests\Work;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTaskRequest extends FormRequest
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

        return $rules + [
            'due_date' => ['sometimes', 'date_format:Y-m-d'],
            'assigned_user_id' => ['sometimes', 'nullable', 'uuid'],
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
