<?php

namespace App\Http\Requests\Inventory;

use App\Enums\InventoryMovementType;
use Illuminate\Validation\Rule;

class ListMovementsRequest extends InventoryRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'inventory_item_id' => ['sometimes', 'uuid'],
            'storage_location_id' => ['sometimes', 'uuid'],
            'inventory_lot_id' => ['sometimes', 'uuid'],
            'type' => ['sometimes', Rule::enum(InventoryMovementType::class)],
            'operational_record_id' => ['sometimes', 'uuid'],
            'health_record_id' => ['sometimes', 'uuid'],
            'purchase_id' => ['sometimes', 'uuid'],
            'sale_id' => ['sometimes', 'uuid'],
            'production_cycle_id' => ['sometimes', 'uuid'],
            'breeding_project_id' => ['sometimes', 'uuid'],
            /** A stored reason code (e.g. donation, incubation, production, sale). */
            'reason' => ['sometimes', 'string', 'max:30'],
            'recorded_from' => ['sometimes', 'date_format:Y-m-d'],
            'recorded_to' => ['sometimes', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
