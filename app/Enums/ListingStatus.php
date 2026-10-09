<?php

namespace App\Enums;

/** Marketplace listing lifecycle. `restricted` is set and lifted by platform administrators only. */
enum ListingStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Paused = 'paused';
    case Archived = 'archived';
    case Restricted = 'restricted';

    public function isPublished(): bool
    {
        return $this === self::Published;
    }
}
