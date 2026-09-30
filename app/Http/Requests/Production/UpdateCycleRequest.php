<?php

namespace App\Http\Requests\Production;

use App\Services\Production\CycleService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'production_area_id' => ['sometimes', 'nullable', 'uuid'],
            'expected_end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'expected_germination_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            /** @var array{quantity: string|int|float, unit: string}|null */
            'area' => ['sometimes', 'nullable', 'array:quantity,unit'],
            /** @var string|int|float */
            'area.quantity' => ['required_with:area', 'numeric', 'gt:0'],
            'area.unit' => ['required_with:area', 'string', 'max:32'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
            /** @ignoreParam */
            'id' => ['missing'],
            /** @ignoreParam */
            'status' => ['missing'],
            /** @ignoreParam */
            'current_population' => ['missing'],
            /** @ignoreParam */
            'reference' => ['missing'],
            /** @ignoreParam */
            'material_quantity' => ['missing'],
            /** @ignoreParam */
            'end_date' => ['missing'],
            /** @ignoreParam */
            'is_active' => ['missing'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function () {
            app(CycleService::class)->assertNoBaselineInput($this->all());
        });
    }
}
