<?php

namespace App\Models;

use App\Enums\OperationCategory;
use App\Enums\TrackingModel;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** What a farm can do (Poultry, Cattle, Fishery, Crops...). Groups species (livestock/fish) or crop types. */
class OperationType extends Model
{
    use HasUuidV7;

    protected $fillable = ['code', 'name', 'category', 'tracking_model', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'category' => OperationCategory::class,
            'tracking_model' => TrackingModel::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('code');
    }

    public function species(): HasMany
    {
        return $this->hasMany(Species::class);
    }

    public function cropTypes(): HasMany
    {
        return $this->hasMany(CropType::class);
    }
}
