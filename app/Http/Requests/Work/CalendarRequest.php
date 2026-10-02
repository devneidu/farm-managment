<?php

namespace App\Http\Requests\Work;

use App\Enums\TaskCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Farm-local dates, inclusive; at most 92 days. */
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'production_cycle_id' => ['sometimes', 'uuid'],
            'breeding_project_id' => ['sometimes', 'uuid'],
            'category' => ['sometimes', Rule::enum(TaskCategory::class)],
            'assigned_to' => ['sometimes', 'string', 'max:36'],
            /** Set to false to list tasks only. */
            'include_milestones' => ['sometimes', 'boolean'],
        ];
    }
}
