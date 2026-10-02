<?php

namespace App\Http\Requests\Work;

use App\Enums\DueState;
use App\Enums\TaskCategory;
use App\Enums\TaskStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            /** Derived: upcoming | due_today | overdue (open tasks) | completed | cancelled. */
            'due_state' => ['sometimes', Rule::enum(DueState::class)],
            'production_cycle_id' => ['sometimes', 'uuid'],
            'breeding_project_id' => ['sometimes', 'uuid'],
            'schedule_id' => ['sometimes', 'uuid'],
            'category' => ['sometimes', Rule::enum(TaskCategory::class)],
            /** "me" or a member's user id. */
            'assigned_to' => ['sometimes', 'string', 'max:36'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
