<?php

namespace App\Enums;

enum SubscriptionEventType: string
{
    case Created = 'created';
    case PlanChanged = 'plan_changed';
    case CancellationScheduled = 'cancellation_scheduled';
    case Resumed = 'resumed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
