<?php

namespace App\Services\Subscription;

use App\Enums\Limit;
use App\Models\Farm;
use App\Models\FarmInvitation;
use App\Models\FarmMembership;

/**
 * Current consumption of each capacity limit. The single place that defines what "usage" means.
 */
class UsageResolver
{
    public function usage(Farm $farm, Limit $limit): int
    {
        return match ($limit) {
            Limit::TeamMembers => $this->teamMembers($farm),
        };
    }

    /**
     * Team capacity = ACTIVE memberships (the Owner included) + PENDING invitations (unaccepted, unrevoked,
     * unexpired), because a pending invitation reserves a seat. Removed members and expired/revoked/accepted
     * invitations do not count (an accepted invitation is already an active membership).
     */
    private function teamMembers(Farm $farm): int
    {
        $members = FarmMembership::active()->where('farm_id', $farm->id)->count();

        $pending = FarmInvitation::where('farm_id', $farm->id)
            ->whereNull('accepted_at')->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->count();

        return $members + $pending;
    }
}
