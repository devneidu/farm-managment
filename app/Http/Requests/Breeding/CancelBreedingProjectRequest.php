<?php

namespace App\Http\Requests\Breeding;

use Illuminate\Foundation\Http\FormRequest;

class CancelBreedingProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
            /** How many eggs go back to available stock. Omit = nothing returns (the eggs are not assumed usable). At most what the project took and has not returned. Needs inventory.use. */
            'eggs_returned_to_stock' => ['sometimes', 'integer', 'min:1', 'max:999999999'],
            /** Store the returned eggs go to; defaults to the store they were taken from. */
            'egg_storage_location_id' => ['sometimes', 'uuid'],
        ];
    }
}
