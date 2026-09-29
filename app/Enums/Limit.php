<?php

namespace App\Enums;

/**
 * Registry of numeric plan entitlements (capacity limits). Each limit needs a usage rule in
 * App\Services\Subscription\UsageResolver. To add one: case + label here, a usage rule there,
 * and plan values in the catalogue (DB).
 */
enum Limit: string
{
    case TeamMembers = 'team_members';

    public function label(): string
    {
        return match ($this) {
            self::TeamMembers => 'Team members',
        };
    }
}
