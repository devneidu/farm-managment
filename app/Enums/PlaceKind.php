<?php

namespace App\Enums;

use App\Models\Location;
use App\Models\ProductionArea;
use App\Models\StorageLocation;

/** Distinct ERD entities share lifecycle/validation, not identity or future domain references. */
enum PlaceKind: string
{
    case Location = 'location';
    case ProductionArea = 'production_area';
    case StorageLocation = 'storage_location';

    public function model(): string
    {
        return match ($this) {
            self::Location => Location::class,
            self::ProductionArea => ProductionArea::class,
            self::StorageLocation => StorageLocation::class,
        };
    }

    public function parentColumn(): string
    {
        return $this === self::Location ? 'parent_id' : 'location_id';
    }

    /** Stable codes, shared read-only catalogue. No biological implications. */
    public function types(): array
    {
        return match ($this) {
            self::Location => ['site' => 'Site', 'building' => 'Building', 'house' => 'House', 'field' => 'Field', 'other' => 'Other'],
            self::ProductionArea => ['house' => 'House', 'pen' => 'Pen', 'pond' => 'Pond', 'field' => 'Field', 'plot' => 'Plot', 'other' => 'Other'],
            self::StorageLocation => ['store' => 'Store', 'other' => 'Other'],
        };
    }
}
