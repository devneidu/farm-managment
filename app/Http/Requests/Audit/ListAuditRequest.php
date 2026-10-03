<?php

namespace App\Http\Requests\Audit;

use Illuminate\Foundation\Http\FormRequest;

class ListAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Inclusive farm-local days, applied to when the action was performed. */
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'actor_id' => ['sometimes', 'uuid'],
            /** Exact action code, for example `record.reversed` or `team.member_role_changed`. */
            'action' => ['sometimes', 'string', 'max:60'],
            'resource_type' => ['sometimes', 'string', 'max:40'],
            /** Everything that happened to one resource (including the reversal/correction rows that point at it). */
            'resource_id' => ['sometimes', 'uuid'],
            'request_id' => ['sometimes', 'string', 'max:100'],
            'production_cycle_id' => ['sometimes', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
