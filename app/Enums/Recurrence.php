<?php

namespace App\Enums;

/** none = a single task; daily = every N days; weekly = every N weeks on the chosen ISO weekdays. */
enum Recurrence: string
{
    case None = 'none';
    case Daily = 'daily';
    case Weekly = 'weekly';
}
