<?php

namespace App\Http\Requests\Breeding;

use Illuminate\Foundation\Http\FormRequest;

class StoreBreedingCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'checked_on' => ['required', 'date_format:Y-m-d'],
            /** Pregnancy: confirmed / not confirmed. Incubation: fertile / not fertile. */
            'result' => ['required', 'in:positive,negative,inconclusive'],
            /** Incubation only (candling): number of eggs found fertile; at most eggs_set. */
            'fertile_count' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
        ];
    }
}
