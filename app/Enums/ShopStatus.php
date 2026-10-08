<?php

namespace App\Enums;

/** Publishing lifecycle of a seller shop. Only `active` shops are visible to the public. */
enum ShopStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Rejected = 'rejected';
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';

    public function isPublic(): bool
    {
        return $this === self::Active;
    }
}
