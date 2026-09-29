<?php

namespace App\Http\Requests\Measurement;

use App\Enums\ConversionContextType;
use App\Http\Requests\Measurement\Concerns\ScalarQuantityRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NormalizeQuantityRequest extends FormRequest
{
    use ScalarQuantityRule;

    public function authorize(): bool
    {
        return true; // authorised by the farm.permission:measurement.view route middleware
    }

    public function rules(): array
    {
        return [
            'components' => ['required', 'array', 'min:1', 'max:10'],
            'components.*' => ['array:quantity,unit'],
            'components.*.quantity' => ['required', $this->scalarNumber()],
            'components.*.unit' => ['required', 'string', 'max:32'],
            'context' => ['sometimes', 'nullable', 'array:type,id'],
            'context.type' => ['required_with:context', Rule::enum(ConversionContextType::class)],
            'context.id' => ['required_with:context', 'string', 'max:36'],
            'result_unit' => ['sometimes', 'nullable', 'string', 'max:32'],
            'farm_id' => ['missing'],
        ];
    }
}
