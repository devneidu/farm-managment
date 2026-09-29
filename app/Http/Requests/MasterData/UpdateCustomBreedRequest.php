<?php

namespace App\Http\Requests\MasterData;

use App\Http\Requests\MasterData\Concerns\NormalizesMasterName;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomBreedRequest extends FormRequest
{
    use NormalizesMasterName;

    public function authorize(): bool
    {
        return true; // authorised by the farm.permission:master_data.manage route middleware
    }

    public function rules(): array
    {
        return [
            'name' => $this->nameRules(false),
            'is_active' => ['sometimes', 'boolean'],
            // A record can never change farm, parent or code (no converting between system and custom).
            'farm_id' => ['missing'],
            'species_id' => ['missing'],
            'code' => ['missing'],
        ];
    }
}
