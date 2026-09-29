<?php

namespace App\Http\Requests\MasterData;

use App\Http\Requests\MasterData\Concerns\NormalizesMasterName;
use Illuminate\Foundation\Http\FormRequest;

class StoreCustomVarietyRequest extends FormRequest
{
    use NormalizesMasterName;

    public function authorize(): bool
    {
        return true; // authorised by the farm.permission:master_data.manage route middleware
    }

    public function rules(): array
    {
        return [
            'crop_type_id' => ['required', 'string', 'exists:crop_types,id'],
            'name' => $this->nameRules(true),
            'farm_id' => ['missing'],
            'code' => ['missing'],
        ];
    }
}
