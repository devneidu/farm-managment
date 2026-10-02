<?php

namespace App\Http\Requests\Work;

use App\Enums\EvidenceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            /** When the work was finished (UTC ISO-8601, not in the future). Defaults to now. */
            'completed_at' => ['sometimes', 'date'],
            /** An ALREADY SAVED actual record to link. Completing never creates one. Required when the task requires evidence. */
            'evidence' => ['sometimes', 'array:type,id'],
            'evidence.type' => ['required_with:evidence', Rule::enum(EvidenceType::class)],
            'evidence.id' => ['required_with:evidence', 'uuid'],
        ];
    }
}
