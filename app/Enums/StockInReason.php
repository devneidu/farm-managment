<?php

namespace App\Enums;

/**
 * Stable, domain-level reasons for stock coming IN. The manual endpoint accepts every case except the system-only ones;
 * purchases (Phase 14) book their own "purchase" stock-in with a source link and expense, crop harvests / egg collections /
 * milk records book their own stock-in through the operational record that explains it.
 */
enum StockInReason: string
{
    case OpeningBalance = 'opening_balance';
    case Purchase = 'purchase';
    case Donation = 'donation';
    case Aid = 'aid';
    case Received = 'received';
    /** Produced on the farm. Manual only for stock no record explains (e.g. home-mixed feed); produce comes from its record. */
    case Production = 'production';
    /** Eggs handed back to stock when an incubation is cancelled or reduced. Written only by the breeding workflow. */
    case Returned = 'returned';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::OpeningBalance => 'Opening balance',
            self::Purchase => 'Purchased',
            self::Donation => 'Gift / Donation',
            self::Aid => 'Aid / Support',
            self::Received => 'Received',
            self::Production => 'Produced on farm',
            self::Returned => 'Returned to stock',
            self::Other => 'Other',
        };
    }

    /** Never accepted on POST /inventory/stock-in. */
    public function isSystemOnly(): bool
    {
        return $this === self::Returned;
    }
}
