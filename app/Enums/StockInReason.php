<?php

namespace App\Enums;

/** Descriptive only: no purchasing, supplier or expense workflow exists in Phase 9. */
enum StockInReason: string
{
    case OpeningBalance = 'opening_balance';
    case Purchase = 'purchase';
    case Donation = 'donation';
    case Other = 'other';
}
