<?php

namespace App\Http\Requests\Breeding;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBreedingProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Changing the start recalculates a reference-derived expectation (from the stored snapshot) until an outcome is recorded. */
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            /** On a project that took eggs from stock: raising it takes the extra eggs; lowering it returns nothing unless eggs_returned_to_stock says so. */
            'eggs_set' => ['sometimes', 'integer', 'min:1', 'max:999999999'],
            /** Only when eggs_set is reduced: how many of the removed eggs go back to available stock (at most the reduction). Omit = no inventory increase. */
            'eggs_returned_to_stock' => ['sometimes', 'integer', 'min:1', 'max:999999999'],
            /** Store the returned eggs go to; defaults to the store they were taken from. */
            'egg_storage_location_id' => ['sometimes', 'uuid'],
            'females_bred' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999999999'],
            'expected_offspring' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999'],
            'expected_date' => ['sometimes', 'date_format:Y-m-d', 'prohibits:expected_from,expected_to,revert_to_reference'],
            'expected_from' => ['sometimes', 'date_format:Y-m-d', 'required_with:expected_to', 'prohibits:expected_date,revert_to_reference'],
            'expected_to' => ['sometimes', 'date_format:Y-m-d', 'required_with:expected_from', 'after_or_equal:expected_from'],
            /** true drops a manual expectation and recalculates from the stored biological reference. */
            'revert_to_reference' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
            /** @ignoreParam */
            'workflow' => ['missing'],
            /** @ignoreParam */
            'production_cycle_id' => ['missing'],
            /** @ignoreParam */
            'status' => ['missing'],
            /** @ignoreParam */
            'reference_snapshot' => ['missing'],
        ];
    }
}
