<?php

namespace App\Http\Requests\Platform;

use App\Models\ReferenceValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListMasterRecordsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Matches name or code. */
            'q' => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            /** Filter by the parent record (operation type / species / crop type id). */
            'parent_id' => ['sometimes', 'uuid'],
            /** Operation types only. */
            'category' => ['sometimes', Rule::in(['livestock', 'aquaculture', 'crop'])],
            /** Reference values only. */
            'list' => ['sometimes', Rule::in(ReferenceValue::managedLists())],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
