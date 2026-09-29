<?php

namespace App\Enums;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';

    public function addTo(CarbonInterface $from): Carbon
    {
        $date = Carbon::instance($from);

        return match ($this) {
            self::Monthly => $date->addMonthNoOverflow(),
            self::Annual => $date->addYearNoOverflow(),
        };
    }
}
