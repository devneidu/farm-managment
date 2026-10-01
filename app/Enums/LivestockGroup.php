<?php

namespace App\Enums;

/**
 * Product grouping of livestock species for selectors and forms (a presentation/product classification only: it never
 * drives biological behaviour, which comes from species capabilities). Fish/aquaculture species have no group.
 */
enum LivestockGroup: string
{
    case Poultry = 'poultry';
    case SmallRuminants = 'small_ruminants';
    case LargeRuminants = 'large_ruminants';
    case NonRuminantMammals = 'non_ruminant_mammals';
    case Equines = 'equines';
    case MicroLivestock = 'micro_livestock';

    public function label(): string
    {
        return match ($this) {
            self::Poultry => 'Poultry',
            self::SmallRuminants => 'Small ruminants',
            self::LargeRuminants => 'Large ruminants',
            self::NonRuminantMammals => 'Non-ruminant mammals',
            self::Equines => 'Equines',
            self::MicroLivestock => 'Micro-livestock',
        };
    }

    public function sortOrder(): int
    {
        return (array_search($this, self::cases(), true) + 1) * 10;
    }
}
