<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /** Statuses that may still grant their plan's entitlements (subject to the period end). */
    public function grantsAccess(): bool
    {
        return in_array($this, [self::Active, self::PastDue], true);
    }
}
