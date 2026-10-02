<?php

namespace App\Http\Requests\Work;

use Illuminate\Foundation\Http\FormRequest;

class EndScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Also cancel the schedule's open future tasks (due after today). Completed and past tasks are kept. */
            'cancel_future_tasks' => ['sometimes', 'boolean'],
        ];
    }
}
