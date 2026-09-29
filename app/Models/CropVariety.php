<?php

namespace App\Models;

use App\Models\Concerns\FarmScopedMasterRecord;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A variety of one crop type: a system default (farm_id NULL) or a farm custom variety. Optional everywhere. */
class CropVariety extends Model
{
    use FarmScopedMasterRecord, HasUuidV7;

    protected $fillable = ['crop_type_id', 'farm_id', 'code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public static function parentColumn(): string
    {
        return 'crop_type_id';
    }

    public function cropType(): BelongsTo
    {
        return $this->belongsTo(CropType::class);
    }
}
