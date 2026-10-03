<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class ChangeFarmPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'uuid', 'exists:plans,id'],
            /** Used for a paid plan (default monthly); ignored for the default plan. */
            'interval' => ['sometimes', 'nullable', 'in:monthly,annual'],
            /** Why support is changing this farm's plan (kept in the audit trail). */
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
