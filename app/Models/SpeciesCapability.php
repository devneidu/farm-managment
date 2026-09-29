<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpeciesCapability extends Model
{
    use HasUuidV7;

    protected $fillable = ['species_id', 'capability_id', 'enabled', 'reference_config'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'reference_config' => 'array'];
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class);
    }

    public function capability(): BelongsTo
    {
        return $this->belongsTo(MasterCapability::class, 'capability_id');
    }
}
