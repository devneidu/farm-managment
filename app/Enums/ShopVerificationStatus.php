<?php

namespace App\Enums;

/** Trust badge, independent of the publishing lifecycle: an active shop may be unverified. */
enum ShopVerificationStatus: string
{
    case Unverified = 'unverified';
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
