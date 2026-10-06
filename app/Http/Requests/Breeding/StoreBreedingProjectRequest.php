<?php

namespace App\Http\Requests\Breeding;

use App\Enums\BreedingWorkflow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBreedingProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'production_cycle_id' => ['required', 'uuid'],
            /** incubation needs the species capability supports_incubation; pregnancy needs supports_pregnancy. */
            'workflow' => ['required', Rule::enum(BreedingWorkflow::class)],
            /** Incubation start / service date (farm-local day), between the cycle start and today. */
            'start_date' => ['required', 'date_format:Y-m-d'],
            /** Incubation only (required there, rejected for pregnancy). Eggs set are not live population. */
            'eggs_set' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999999999'],
            /** Incubation only. true takes eggs_set eggs out of the farm's available egg stock in the same request (stock-out reason incubation, linked to this project). 409 insufficient_stock when too few; needs inventory.use. Omit to leave stock untouched. */
            'consume_egg_stock' => ['sometimes', 'boolean'],
            /** Store the eggs are taken from. Optional when the farm has exactly one active storage location; required when it has several. */
            'egg_storage_location_id' => ['sometimes', 'uuid'],
            /** Pregnancy only: how many females were bred. */
            'females_bred' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999999999'],
            /** Expected offspring is an estimate and never changes population. */
            'expected_offspring' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999'],
            /** Optional manual expected date (exact). Provide this OR expected_from + expected_to. Overrides the biological reference without altering its snapshot. */
            'expected_date' => ['sometimes', 'date_format:Y-m-d', 'prohibits:expected_from,expected_to'],
            'expected_from' => ['sometimes', 'date_format:Y-m-d', 'required_with:expected_to', 'prohibits:expected_date'],
            'expected_to' => ['sometimes', 'date_format:Y-m-d', 'required_with:expected_from', 'after_or_equal:expected_from'],
            'parents' => ['sometimes', 'array', 'max:10'],
            'parents.*' => ['required', 'array:role,production_cycle_id,head_count'],
            'parents.*.role' => ['required', 'in:dam,sire'],
            'parents.*.production_cycle_id' => ['required', 'uuid'],
            'parents.*.head_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999999999'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'idempotency_key' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:-]+$/'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
            /** @ignoreParam */
            'status' => ['missing'],
            /** @ignoreParam */
            'reference' => ['missing'],
            /** @ignoreParam */
            'reference_snapshot' => ['missing'],
        ];
    }
}
