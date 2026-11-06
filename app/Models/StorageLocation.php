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

    /**
     * A copy is never a second main store and must never write the generated main_scope column (MySQL error 3105).
     *
     * @param  array<int, string>|null  $except
     */
    public function replicate(?array $except = null)
    {
        return parent::replicate(array_values(array_unique(array_merge($except ?? [], ['main_scope', 'is_default']))));
    }

    protected function casts(): array
    {
        return parent::casts() + ['is_default' => 'boolean'];
    }
}
