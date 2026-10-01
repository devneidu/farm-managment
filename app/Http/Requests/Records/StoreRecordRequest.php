<?php

namespace App\Http\Requests\Records;

use App\Services\Records\RecordTypeRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(array_keys(app(RecordTypeRegistry::class)->definitions()))],
            'production_cycle_id' => ['required', 'uuid'],
            'recorded_at' => ['required', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/'],
            'idempotency_key' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:-]+$/'],
            /** Type-specific object; only fields returned by /record-types/{type}/schema are accepted.
             * @var object
             */
            'details' => ['present', 'array'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'corrects_record_id' => ['sometimes', 'nullable', 'uuid'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
            /** @ignoreParam */
            'population_delta' => ['missing'],
            /** @ignoreParam */
            'measurement' => ['missing'],
            /** @ignoreParam */
            'created_by' => ['missing'],
        ];
    }
}
