<?php

namespace App\Http\Requests\Locations;

class UpdateStorageLocationRequest extends PlaceWriteRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'type' => ['sometimes', 'string', 'in:store,other'],
            // Always references a location, never a production area or storage location.
            'parent_id' => ['sometimes', 'nullable', 'uuid'],
            'is_active' => ['sometimes', 'boolean'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
            /** @ignoreParam */
            'id' => ['missing'],
            /** @ignoreParam */
            'location_id' => ['missing'],
            /** @ignoreParam */
            'normalized_name' => ['missing'],
            /** @ignoreParam */
            'parent_scope' => ['missing'],
            /** @ignoreParam */
            'operation' => ['missing'],
            /** @ignoreParam */
            'operation_id' => ['missing'],
            /** @ignoreParam */
            'area' => ['missing'],
            /** @ignoreParam */
            'capacity' => ['missing'],
        ];
    }
}
