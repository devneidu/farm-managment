<?php

namespace App\Enums;

/** Fundamental production behaviour of an operation type. */
enum OperationCategory: string
{
    case Livestock = 'livestock';
    case Aquaculture = 'aquaculture';
    case Crop = 'crop';

    public function trackingModel(): TrackingModel
    {
        return $this === self::Crop ? TrackingModel::PlantingUnits : TrackingModel::Population;
    }
}
