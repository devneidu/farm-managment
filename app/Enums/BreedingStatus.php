<?php

namespace App\Enums;

/** active -> completed (an outcome exists) | cancelled (abandoned); reversing the only outcome returns completed -> active. */
enum BreedingStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
