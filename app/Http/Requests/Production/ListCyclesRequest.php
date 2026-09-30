<?php

namespace App\Http\Requests\Production;

use App\Enums\CycleKind;
use App\Enums\CycleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCyclesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kind' => ['sometimes', Rule::enum(CycleKind::class)],
            'status' => ['sometimes', Rule::enum(CycleStatus::class)],
            'operation_type_id' => ['sometimes', 'uuid'],
            'species_id' => ['sometimes', 'uuid'],
            'crop_type_id' => ['sometimes', 'uuid'],
            'production_area_id' => ['sometimes', 'uuid'],
            'search' => ['sometimes', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
        ];
    }
}
