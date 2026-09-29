<?php

namespace App\Http\Requests\Measurement;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUnitPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorised by the farm.permission:measurement.manage route middleware
    }

    public function rules(): array
    {
        return [
            // dimension code => unit code, or null to go back to the default. Omitted dimensions are unchanged.
            'preferences' => ['required', 'array', 'min:1', 'max:8'],
            'preferences.weight' => ['sometimes', 'nullable', 'string', 'max:32'],
            'preferences.volume' => ['sometimes', 'nullable', 'string', 'max:32'],
            'preferences.area' => ['sometimes', 'nullable', 'string', 'max:32'],
            'preferences.temperature' => ['sometimes', 'nullable', 'string', 'max:32'],
            'farm_id' => ['missing'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            $unknown = array_diff(array_keys((array) $this->input('preferences', [])), ['weight', 'volume', 'area', 'temperature']);
            foreach ($unknown as $key) {
                $validator->errors()->add("preferences.{$key}", 'This dimension does not support a unit preference.');
            }
        }];
    }
}
