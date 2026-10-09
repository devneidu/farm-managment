<?php

namespace App\Models;

use App\Enums\LivestockGroup;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Species extends Model
{
    use HasUuidV7;

    protected $table = 'species';

    protected $fillable = ['operation_type_id', 'code', 'name', 'livestock_group', 'breed_field_label', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer', 'livestock_group' => LivestockGroup::class];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('code');
    }

    public function operationType(): BelongsTo
    {
        return $this->belongsTo(OperationType::class);
    }

    public function breeds(): HasMany
    {
        return $this->hasMany(Breed::class);
    }

    public function speciesCapabilities(): HasMany
    {
        return $this->hasMany(SpeciesCapability::class);
    }
}
