<?php

namespace App\Http\Requests\Locations;

use Illuminate\Foundation\Http\FormRequest;

class ListPlacesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Include inactive rows for management/history; omitted means active only.
            'include_inactive' => ['sometimes', 'boolean'],
            // A stable code from GET /master/location-types, appropriate to this endpoint's kind.
            'type' => ['sometimes', 'string', 'in:site,building,house,field,pen,pond,plot,store,other'],
            // Direct children only; UUID of a location in this farm. Mutually exclusive with top_level=true.
            'parent_id' => ['sometimes', 'required', 'uuid', 'prohibited_if:top_level,1,true'],
            'top_level' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
            /** @ignoreParam */
            'operation' => ['missing'],
        ];
    }
}
