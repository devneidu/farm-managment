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

    /** A short plain-language explanation for the "Add task" form. */
    public function description(): string
    {
        return match ($this) {
            self::FeedingWatering => 'Giving feed and clean water to your animals.',
            self::EggCollection => 'Collecting eggs from the laying houses or nests.',
            self::VaccinationMedication => 'Vaccinating or treating animals, including follow-up doses.',
            self::BreedingReproduction => 'Mating, egg setting, candling, hatching and birthing checks.',
            self::GrowthMonitoring => 'Weighing a sample or checking how well the animals are growing.',
            self::RecordKeeping => 'Writing down counts, notes or measurements so your records stay complete.',
            self::MaintenanceRepair => 'Fixing or servicing pens, equipment, pumps and buildings.',
            self::CleaningSanitation => 'Cleaning houses, drinkers, feeders and footbaths.',
            self::MovementRotation => 'Moving animals between houses or pens, or rotating pasture.',
            self::ProcurementOrders => 'Buying or ordering feed, medicine, seed and other supplies.',
            self::Irrigation => 'Watering crops by hand, drip or sprinkler.',
            self::CropCare => 'Weeding, spraying, pruning and checking crops for pests.',
            self::Harvest => 'Picking or cutting crops when they are ready.',
            self::Payment => 'Paying a bill, wages or a supplier, or collecting money owed.',
            self::Other => 'Any other job that does not fit the groups above.',
        };
    }

    /** Which colour family the frontend should use for this category on the calendar. */
    public function colorGroup(): string
    {
        return match ($this) {
            self::FeedingWatering, self::EggCollection, self::GrowthMonitoring => 'feeding_eggs_growth',
            self::VaccinationMedication => 'health_vaccines',
            self::BreedingReproduction => 'breeding',
            self::CleaningSanitation, self::MaintenanceRepair, self::MovementRotation => 'cleaning_repairs_moves',
            self::Irrigation, self::CropCare, self::Harvest => 'crops',
            self::RecordKeeping, self::ProcurementOrders, self::Payment, self::Other => 'orders_records_other',
        };
    }
}
