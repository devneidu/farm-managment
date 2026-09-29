<?php

namespace App\Http\Requests\MasterData;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFarmOperationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorised by the farm.permission:farm.update route middleware
    }

    public function rules(): array
    {
        return [
            // The full desired selection; an empty list clears it (farm becomes unconfigured again).
            'operation_ids' => ['present', 'array', 'max:50'],
            'operation_ids.*' => ['string', 'distinct', Rule::exists('operation_types', 'id')->where('is_active', true)],
        ];
    }
}
