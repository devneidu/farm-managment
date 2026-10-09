<?php

namespace App\Enums;

/**
 * Marketplace deal lifecycle. A deal exists only once both sides have agreed, so it starts `accepted` (active). `completed` and `cancelled` are
 * terminal. Reports are NOT a state: they never change a deal's status.
 */
enum DealStatus: string
{
    case Accepted = 'accepted';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return $this === self::Accepted;
    }
}
