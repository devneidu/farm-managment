<?php

namespace App\Http\Requests\Breeding;

use App\Enums\BreedingStatus;
use App\Enums\BreedingWorkflow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListBreedingProjectsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'production_cycle_id' => ['sometimes', 'uuid'],
            'workflow' => ['sometimes', Rule::enum(BreedingWorkflow::class)],
            'status' => ['sometimes', Rule::enum(BreedingStatus::class)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
