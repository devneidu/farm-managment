<?php

namespace App\Http\Requests\Health;

use App\Enums\HealthProblemType;
use App\Services\Health\HealthTypeRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListHealthRecordsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'production_cycle_id' => ['sometimes', 'uuid'],
            'type' => ['sometimes', Rule::in([...array_keys(app(HealthTypeRegistry::class)->definitions()), HealthTypeRegistry::REVERSAL])],
            /** Filter by the problem type dropdown (codes in /master/health-record-types → problem_types). */
            'problem_type' => ['sometimes', Rule::enum(HealthProblemType::class)],
            'inventory_item_id' => ['sometimes', 'uuid'],
            'recorded_from' => ['sometimes', 'date_format:Y-m-d'],
            'recorded_to' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('recorded_from') ? ['after_or_equal:recorded_from'] : [])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
