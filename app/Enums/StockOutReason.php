<?php

namespace App\Enums;

/**
 * Stable, domain-level reasons for stock going OUT (never channel-specific codes such as "farmers_market": those belong to
 * the sale). `use`, `expired` and `wasted` are kept for existing clients and records.
 *
 * System-only reasons are written by the workflow that owns the real-world event and are refused on POST /inventory/stock-out
 * so one event can never be entered twice: sale -> POST /sales, production_use -> POST /records (feed_use),
 * incubation -> POST /breeding-projects.
 */
enum StockOutReason: string
{
    case Use = 'use';
    case Damaged = 'damaged';
    case Expired = 'expired';
    case Wasted = 'wasted';
    case Other = 'other';
    case Sale = 'sale';
    case ProductionUse = 'production_use';
    case Incubation = 'incubation';
    case Donation = 'donation';
    case InternalUse = 'internal_use';
    case Spoiled = 'spoiled';
    case Lost = 'lost';
    case Disposal = 'disposal';

    public function label(): string
    {
        return match ($this) {
            self::Use => 'Other use',
            self::Damaged => 'Damaged / Broken',
            self::Expired => 'Expired',
            self::Wasted => 'Wasted',
            self::Other => 'Other',
            self::Sale => 'Sold',
            self::ProductionUse => 'Used for livestock',
            self::Incubation => 'Put into incubation',
            self::Donation => 'Gift / Donation',
            self::InternalUse => 'Personal / Internal use',
            self::Spoiled => 'Spoiled',
            self::Lost => 'Lost / Stolen',
            self::Disposal => 'Disposal / Compost',
        };
    }

    public function isSystemOnly(): bool
    {
        return in_array($this, [self::Sale, self::ProductionUse, self::Incubation], true);
    }

    /** Kept so existing clients keep working; new clients use the per-item-kind reason lists. */
    public function isLegacy(): bool
    {
        return in_array($this, [self::Use, self::Expired, self::Wasted], true);
    }
}
