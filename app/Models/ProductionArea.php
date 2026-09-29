<?php

namespace App\Models;

use App\Enums\PlaceKind;

class ProductionArea extends Place
{
    public function kind(): PlaceKind
    {
        return PlaceKind::ProductionArea;
    }
}
