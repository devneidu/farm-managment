<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

class ReferenceValue extends Model
{
    use HasUuidV7;

    public const PLANTING_MATERIAL_TYPE = 'planting_material_type';

    public const PLANTING_UNIT_TYPE = 'planting_unit_type';

    public static function livestockList(string $speciesCode, string $kind): string
    {
        return 'livestock_'.$kind.'_'.$speciesCode;
    }

    /** Lists accepted by the existing platform reference-data administration. */
    public static function managedLists(): array
    {
        return array_merge([self::PLANTING_MATERIAL_TYPE, self::PLANTING_UNIT_TYPE],
            Species::pluck('code')->flatMap(fn ($code) => [self::livestockList($code, 'purpose'), self::livestockList($code, 'stage')])->all());
    }

    protected $fillable = ['list', 'code', 'name', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }
}
