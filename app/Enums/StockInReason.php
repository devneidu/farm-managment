<?php

namespace App\Enums;

/** Descriptive for manual stock-in; purchases (Phase 14) book their own "purchase" stock-in with a source link and expense. */
enum StockInReason: string
{
    case OpeningBalance = 'opening_balance';
    case Purchase = 'purchase';
    case Donation = 'donation';
    case Other = 'other';
}
