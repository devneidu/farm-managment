<?php

namespace App\Http\Requests\Measurement;

use App\Http\Requests\Measurement\Concerns\ScalarQuantityRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePackageConversionRequest extends FormRequest
{
    use ScalarQuantityRule;

    public function authorize(): bool
    {
        return true; // authorised by the farm.permission:measurement.manage route middleware
    }

    public function rules(): array
    {
        return [
            'quantity_per_package' => ['sometimes', $this->scalarNumber()],
            'target_unit' => ['sometimes', 'string', 'max:32'],
            'is_active' => ['sometimes', 'boolean'],
            // What a definition is ABOUT never changes: create another one instead. (Rename a context on the context.)
            'farm_id' => ['missing'],
            'package_unit' => ['missing'],
            'context_type' => ['missing'],
            'context_id' => ['missing'],
            'context_label' => ['missing'],
            'crop_type_id' => ['missing'],
        ];
    }
}
