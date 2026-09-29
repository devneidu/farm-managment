<?php

namespace App\Http\Requests\MasterData;

use App\Http\Requests\MasterData\Concerns\NormalizesMasterName;
use Illuminate\Foundation\Http\FormRequest;

class StoreCustomBreedRequest extends FormRequest
{
    use NormalizesMasterName;

    public function authorize(): bool
    {
        return true; // authorised by the farm.permission:master_data.manage route middleware
    }

    public function rules(): array
    {
        return [
            'species_id' => ['required', 'string', 'exists:species,id'],
            'name' => $this->nameRules(true),
            // The farm always comes from the caller's farm context; system flags/codes are never client input.
            'farm_id' => ['missing'],
            'code' => ['missing'],
        ];
    }
}
