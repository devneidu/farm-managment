<?php

namespace App\Http\Requests\Locations;

class StoreProductionAreaRequest extends PlaceWriteRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'type' => ['required', 'string', 'in:house,pen,pond,field,plot,other'],
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
