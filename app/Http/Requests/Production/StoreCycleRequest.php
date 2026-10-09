<?php

namespace App\Http\Requests\Production;

use App\Enums\CycleKind;
use App\Models\Place;
use App\Rules\MoneyAmount;
use App\Rules\PositiveWholeCount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Place::cleanName($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(CycleKind::class)],
            'name' => ['required', 'string', 'max:100'],
            'operation_type_id' => ['required', 'uuid'],
            'production_area_id' => ['sometimes', 'nullable', 'uuid'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'expected_end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'start_date' => ['required_if:kind,livestock', 'prohibited_unless:kind,livestock', 'date_format:Y-m-d'],
            'species_id' => ['required_if:kind,livestock', 'prohibited_unless:kind,livestock', 'uuid'],
            'breed_id' => ['sometimes', 'nullable', 'prohibited_unless:kind,livestock', 'uuid'],
            /** Active purpose code from the selected species' batch-reference response. */
            'production_purpose' => ['required_if:kind,livestock', 'prohibited_unless:kind,livestock', 'string', 'max:64'],
            /** Optional biological growth-stage code; a starting snapshot, not a live stage tracker. */
            'growth_stage' => ['sometimes', 'nullable', 'prohibited_unless:kind,livestock', 'string', 'max:64'],
            /**
             * Informational NGN unit price, non-negative with at most two decimals; no financial booking.
             *
             * @var string|int|float|null
             */
            'acquisition_price_per_animal' => ['sometimes', 'nullable', 'prohibited_unless:kind,livestock', new MoneyAmount(allowZero: true)],
            'supplier_contact_id' => ['sometimes', 'nullable', 'prohibited_unless:kind,livestock', 'uuid'],
            'initial_population' => ['required_if:kind,livestock', 'prohibited_unless:kind,livestock', 'integer', 'min:1', 'max:999999999999', new PositiveWholeCount],
            'planting_date' => ['required_if:kind,crop', 'prohibited_unless:kind,crop', 'date_format:Y-m-d'],
            'crop_type_id' => ['required_if:kind,crop', 'prohibited_unless:kind,crop', 'uuid'],
            'crop_variety_id' => ['sometimes', 'nullable', 'prohibited_unless:kind,crop', 'uuid'],
            'planting_material_type' => ['required_if:kind,crop', 'prohibited_unless:kind,crop', 'string', 'max:40'],
            'planting_unit_type' => ['required_if:kind,crop', 'prohibited_unless:kind,crop', 'string', 'max:40'],
            'initial_planting_units' => ['required_if:kind,crop', 'prohibited_unless:kind,crop', 'integer', 'min:1', 'max:999999999999', new PositiveWholeCount],
            'expected_germination_date' => ['sometimes', 'nullable', 'prohibited_unless:kind,crop', 'date_format:Y-m-d'],
            /** @var array{quantity: string|int|float, unit: string}|null */
            'area' => ['sometimes', 'nullable', 'prohibited_unless:kind,crop', 'array:quantity,unit'],
            /** @var string|int|float */
            'area.quantity' => ['required_with:area', 'numeric', 'gt:0'],
            'area.unit' => ['required_with:area', 'string', 'max:32'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
            /** @ignoreParam */
            'id' => ['missing'],
            /** @ignoreParam */
            'reference' => ['missing'],
            /** @ignoreParam */
            'status' => ['missing'],
            /** @ignoreParam */
            'current_population' => ['missing'],
            /** @ignoreParam */
            'material_quantity' => ['missing'],
            /** @ignoreParam */
            'end_date' => ['missing'],
            /** @ignoreParam */
            'is_active' => ['missing'],
        ];
    }
}
