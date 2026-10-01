<?php

namespace App\Http\Requests\Health;

use App\Http\Requests\Inventory\InventoryRules;
use App\Rules\PositiveWholeCount;
use App\Services\Health\HealthTypeRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHealthRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(array_keys(app(HealthTypeRegistry::class)->definitions()))],
            'production_cycle_id' => ['required', 'uuid'],
            ...InventoryRules::event(),
            /** Type-specific object; only fields returned by /master/health-record-types are accepted.
             * @var object
             */
            'details' => ['present', 'array'],
            'animals_affected' => ['sometimes', 'nullable', new PositiveWholeCount],
            'follow_up_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            /** An existing Phase 8 mortality record of the same cycle. Health never creates a mortality event itself. */
            'mortality_record_id' => ['sometimes', 'nullable', 'uuid'],
            /** Replace a reversed health record once (same type and cycle). */
            'corrects_record_id' => ['sometimes', 'nullable', 'uuid'],
            /** One entry per medicine used. Each consumes linked stock exactly once. */
            'medicines' => ['sometimes', 'array', 'max:10'],
            'medicines.*' => ['required', 'array:inventory_item_id,storage_location_id,lot_id,components,dose_per_animal,dosage_instructions,withdrawal_days'],
            'medicines.*.inventory_item_id' => ['required', 'uuid'],
            'medicines.*.storage_location_id' => ['required', 'uuid'],
            'medicines.*.lot_id' => ['sometimes', 'nullable', 'uuid'],
            ...InventoryRules::components('medicines.*.components'),
            ...InventoryRules::components('medicines.*.dose_per_animal', required: false),
            'medicines.*.dosage_instructions' => ['sometimes', 'nullable', 'string', 'max:500'],
            /** Overrides the medicine's default withdrawal period. 0 = none. */
            'medicines.*.withdrawal_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
            /** @ignoreParam */
            'created_by' => ['missing'],
            /** @ignoreParam */
            'withdrawal_ends_at' => ['missing'],
        ];
    }
}
