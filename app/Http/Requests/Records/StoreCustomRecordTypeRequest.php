<?php

namespace App\Http\Requests\Records;

use App\Services\Records\CustomRecordSchema;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomRecordTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorised by the farm.permission:master_data.manage route middleware
    }

    public function rules(): array
    {
        return [
            /** Unique among this farm's record types; never the name of a built-in record type. */
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            /** Which cycles it can be recorded against. */
            'applies_to' => ['required', Rule::in(['livestock', 'crop', 'both'])],
            /** Livestock types only: restrict to one species. */
            'species_id' => ['sometimes', 'nullable', 'uuid'],
            /** Crop types only: restrict to one crop. */
            'crop_type_id' => ['sometimes', 'nullable', 'uuid'],
            ...CustomRecordSchema::requestRules('fields'),
            /** @ignoreParam */
            'farm_id' => ['missing'],
            /** @ignoreParam */
            'source' => ['missing'],
            /** @ignoreParam */
            'version' => ['missing'],
        ];
    }
}
