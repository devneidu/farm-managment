<?php

namespace App\Enums;

/** What a platform administrator may do. Entirely separate from FarmRole: farm Owner/Manager confer nothing here. */
enum PlatformRole: string
{
    case Admin = 'admin';
    case Support = 'support';

    public function canWrite(): bool
    {
        return $this === self::Admin;
    }
}
