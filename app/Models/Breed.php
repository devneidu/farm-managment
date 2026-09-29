<?php

namespace App\Models;

use App\Models\Concerns\FarmScopedMasterRecord;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A breed of one species: a system default (farm_id NULL) or a farm custom breed. Never hard-deleted once referenced. */
class Breed extends Model
{
    use FarmScopedMasterRecord, HasUuidV7;

    protected $fillable = ['species_id', 'farm_id', 'code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public static function parentColumn(): string
    {
        return 'species_id';
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class);
    }
}
