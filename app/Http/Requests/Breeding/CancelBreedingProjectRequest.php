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
        return ['reason' => ['required', 'string', 'max:2000']];
    }
}
