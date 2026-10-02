<?php

namespace App\Enums;

/** Shared movement infrastructure; categories carry no extra schema (produce receives crop harvests in Phase 13). */
enum InventoryCategory: string
{
    case Feed = 'feed';
    case Medicine = 'medicine';
    case SeedPlantingMaterial = 'seed_planting_material';
    case FertilizerAgrochemical = 'fertilizer_agrochemical';
    case GeneralSupply = 'general_supply';
    case Produce = 'produce';

    public function label(): string
    {
        return match ($this) {
            self::Feed => 'Feed',
            self::Medicine => 'Medicine',
            self::SeedPlantingMaterial => 'Seed / planting material',
            self::FertilizerAgrochemical => 'Fertilizer / agrochemical',
            self::GeneralSupply => 'General supply',
            self::Produce => 'Produce',
        };
    }
}
