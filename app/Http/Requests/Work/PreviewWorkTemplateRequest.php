<?php

namespace App\Http\Requests\Work;

use Illuminate\Foundation\Http\FormRequest;

class PreviewWorkTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** The batch: a production cycle id, or leave out / null for a general routine. */
            'target' => ['sometimes', 'nullable', 'uuid'],
            /** Breeding routines: the breeding project instead of a batch. */
            'breeding_project_id' => ['sometimes', 'nullable', 'uuid'],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
        ];
    }
}
