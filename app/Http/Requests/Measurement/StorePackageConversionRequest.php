<?php

namespace App\Http\Requests\Measurement;

use App\Enums\ConversionContextType;
use App\Http\Requests\Measurement\Concerns\ScalarQuantityRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePackageConversionRequest extends FormRequest
{
    use ScalarQuantityRule;

    public function authorize(): bool
    {
        return true; // authorised by the farm.permission:measurement.manage route middleware
    }

    public function rules(): array
    {
        return [
            'context_type' => ['required', 'string', Rule::enum(ConversionContextType::class)],
            // Existence/ownership is checked in PackageConversionService::resolveContext (crop type, or this farm's context).
            'context_id' => ['required', 'string', 'max:36'],
            'package_unit' => ['required', 'string', 'max:32'],
            'target_unit' => ['required', 'string', 'max:32'],
            'quantity_per_package' => ['required', $this->scalarNumber()],
            // The farm always comes from the caller's farm context; a context is chosen by id, never by typing a label here.
            'farm_id' => ['missing'],
            'context_label' => ['missing'],
            'crop_type_id' => ['missing'],
        ];
    }
}
