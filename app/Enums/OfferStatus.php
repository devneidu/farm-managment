<?php

namespace App\Enums;

/** Marketplace offer lifecycle. Every state except `pending` is terminal. */
enum OfferStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    /** Invalidated by a material change to the listing. Does not count against the buyer's attempts. */
    case Voided = 'voided';
}
