<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeasurementDimension extends Model
{
    use HasUuidV7;

    protected $fillable = ['code', 'name', 'supports_preference', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['supports_preference' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class, 'dimension_id');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('code');
    }
}
