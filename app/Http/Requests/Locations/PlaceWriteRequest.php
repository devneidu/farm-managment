<?php

namespace App\Http\Requests\Locations;

use App\Models\Place;
use Illuminate\Foundation\Http\FormRequest;

abstract class PlaceWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Central route permission; service also enforces location.manage.
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Place::cleanName($this->input('name'))]);
        }
    }
}
