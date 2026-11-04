<?php

namespace App\Enums;

/** none = a single task; daily = every N days; weekly = every N weeks on the chosen ISO weekdays; monthly = every N months on the start day (clamped to the month's last day). */
enum Recurrence: string
{
    case None = 'none';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
}
