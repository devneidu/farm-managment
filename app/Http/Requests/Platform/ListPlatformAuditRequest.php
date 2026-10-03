<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class ListPlatformAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Inclusive UTC days. */
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'actor_id' => ['sometimes', 'uuid'],
            /** Exact action code, for example `platform.plan_updated`. */
            'action' => ['sometimes', 'string', 'max:60'],
            'resource_type' => ['sometimes', 'string', 'max:40'],
            'resource_id' => ['sometimes', 'uuid'],
            'request_id' => ['sometimes', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
