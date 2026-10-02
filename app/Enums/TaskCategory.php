<?php

namespace App\Enums;

/** Closed task vocabulary served by GET /master/task-categories. */
enum TaskCategory: string
{
    case FeedingWatering = 'feeding_watering';
    case EggCollection = 'egg_collection';
    case VaccinationMedication = 'vaccination_medication';
    case BreedingReproduction = 'breeding_reproduction';
    case GrowthMonitoring = 'growth_monitoring';
    case RecordKeeping = 'record_keeping';
    case MaintenanceRepair = 'maintenance_repair';
    case CleaningSanitation = 'cleaning_sanitation';
    case MovementRotation = 'movement_rotation';
    case ProcurementOrders = 'procurement_orders';
    case Irrigation = 'irrigation';
    case CropCare = 'crop_care';
    case Harvest = 'harvest';
    case Payment = 'payment';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::FeedingWatering => 'Feeding / watering', self::EggCollection => 'Egg collection', self::VaccinationMedication => 'Vaccination / medication',
            self::BreedingReproduction => 'Breeding / reproduction', self::GrowthMonitoring => 'Growth monitoring', self::RecordKeeping => 'Record keeping',
            self::MaintenanceRepair => 'Maintenance / repair', self::CleaningSanitation => 'Cleaning / sanitation', self::MovementRotation => 'Movement / rotation',
            self::ProcurementOrders => 'Procurement / orders', self::Irrigation => 'Irrigation', self::CropCare => 'Crop care', self::Harvest => 'Harvest',
            self::Payment => 'Payment', self::Other => 'Other',
        };
    }
}
