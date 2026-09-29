<?php

namespace App\Enums;

/** How a production cycle is baselined: livestock/fish by head count, crops by planting units (never seed quantity). */
enum TrackingModel: string
{
    case Population = 'population';
    case PlantingUnits = 'planting_units';
}
