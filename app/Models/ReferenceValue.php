<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

class ReferenceValue extends Model
{
    use HasUuidV7;

    public const PLANTING_MATERIAL_TYPE = 'planting_material_type';

    public const PLANTING_UNIT_TYPE = 'planting_unit_type';

    protected $fillable = ['list', 'code', 'name', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }
}
