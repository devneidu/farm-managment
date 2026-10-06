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

    /**
     * Whether stock of this category can be sold through POST /sales. A category rule, not a free-for-all: produce (eggs, milk,
     * harvests) and feed (surplus sold to another farm or a distributor). Medicine, seed, agrochemicals and general supplies
     * leave the farm with a stock-out instead.
     */
    public function isSellable(): bool
    {
        return in_array($this, self::sellable(), true);
    }

    /** @return list<self> */
    public static function sellable(): array
    {
        return [self::Produce, self::Feed];
    }

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
