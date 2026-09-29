<?php

namespace App\Models;

use App\Enums\PlaceKind;

class StorageLocation extends Place
{
    public function kind(): PlaceKind
    {
        return PlaceKind::StorageLocation;
    }
}
