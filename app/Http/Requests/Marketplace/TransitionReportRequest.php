<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->platformRole()?->canWrite();
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['in_review', 'dismissed', 'resolved'])],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'enforcement_action' => ['sometimes', Rule::in(['suspend_shop', 'restrict_listing'])],
            'enforcement_id' => ['required_with:enforcement_action', 'uuid'],
        ];
    }
}
