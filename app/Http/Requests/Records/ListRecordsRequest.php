<?php

namespace App\Http\Requests\Records;

use App\Services\Records\RecordTypeRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListRecordsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['production_cycle_id' => ['sometimes', 'uuid'], 'type' => ['sometimes', Rule::in([...array_keys(app(RecordTypeRegistry::class)->definitions()), 'reversal'])],
            'recorded_from' => ['sometimes', 'date_format:Y-m-d'], 'recorded_to' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('recorded_from') ? ['after_or_equal:recorded_from'] : [])],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
