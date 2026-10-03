<?php

namespace App\Http\Requests\Notifications;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** true = unread only, false = read only. */
            'unread' => ['sometimes', 'boolean'],
            'type' => ['sometimes', 'string', 'max:40'],
            'severity' => ['sometimes', Rule::in(['critical', 'warning', 'info'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
