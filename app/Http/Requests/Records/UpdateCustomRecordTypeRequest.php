<?php

namespace App\Http\Requests\Records;

use App\Services\Records\CustomRecordSchema;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomRecordTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorised by the farm.permission:master_data.manage route middleware
    }

    public function rules(): array
    {
        $fields = array_map(fn ($rules) => array_values(array_diff($rules, ['required'])), CustomRecordSchema::requestRules('fields'));
        // Only the list itself is optional; inside it every field still needs its label and kind.
        foreach ($fields as $key => $rules) {
            $fields[$key] = $key === 'fields' ? ['sometimes', ...$rules] : (in_array($key, ['fields.*', 'fields.*.label', 'fields.*.kind', 'fields.*.options.*'], true) ? ['required', ...$rules] : $rules);
        }

        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            /** Frozen once records use the type (409 custom_type_in_use). */
            'applies_to' => ['sometimes', Rule::in(['livestock', 'crop', 'both'])],
            'species_id' => ['sometimes', 'nullable', 'uuid'],
            'crop_type_id' => ['sometimes', 'nullable', 'uuid'],
            /** The COMPLETE field list. Existing fields carry their `key`; a field left out is deleted (refused once records exist). */
            ...$fields,
            /** @ignoreParam */
            'farm_id' => ['missing'],
            /** @ignoreParam */
            'source' => ['missing'],
            /** @ignoreParam */
            'version' => ['missing'],
        ];
    }
}
