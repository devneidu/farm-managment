<?php

namespace App\Models;

use App\Enums\PlaceKind;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Place
{
    public function kind(): PlaceKind
    {
        return PlaceKind::Location;
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function productionAreas(): HasMany
    {
        return $this->hasMany(ProductionArea::class);
    }

    public function storageLocations(): HasMany
    {
        return $this->hasMany(StorageLocation::class);
    }
}
