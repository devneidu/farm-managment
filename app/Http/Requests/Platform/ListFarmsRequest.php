<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class ListFarmsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Matches the farm name or a member name/email. */
            'q' => ['sometimes', 'string', 'max:100'],
            /** Plan slug. */
            'plan' => ['sometimes', 'string', 'max:64'],
            'subscription_status' => ['sometimes', 'in:active,past_due,cancelled,expired'],
            'sort' => ['sometimes', 'in:name,created_at'],
            'direction' => ['sometimes', 'in:asc,desc'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
