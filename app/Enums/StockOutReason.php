<?php

namespace App\Enums;

enum StockOutReason: string
{
    case Use = 'use';
    case Damaged = 'damaged';
    case Expired = 'expired';
    case Wasted = 'wasted';
    case Other = 'other';
}
