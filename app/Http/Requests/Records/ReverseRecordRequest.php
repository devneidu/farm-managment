<?php

namespace App\Http\Requests\Records;

use Illuminate\Foundation\Http\FormRequest;

class ReverseRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
            'recorded_at' => (new StoreRecordRequest)->rules()['recorded_at'],
            'idempotency_key' => (new StoreRecordRequest)->rules()['idempotency_key'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
            /** @ignoreParam */
            'population_delta' => ['missing'],
        ];
    }
}
