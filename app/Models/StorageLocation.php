<?php

namespace App\Models;

use App\Enums\PlaceKind;

/**
 * `is_default` marks the farm's main store (at most one per farm, enforced by a unique index). It is not mass-assignable:
 * only PlaceService / StoreResolver change it, under the farm lock.
 */
class StorageLocation extends Place
{
    public function kind(): PlaceKind
    {
        return PlaceKind::StorageLocation;
    }

    protected function casts(): array
    {
        return parent::casts() + ['is_default' => 'boolean'];
    }
}
