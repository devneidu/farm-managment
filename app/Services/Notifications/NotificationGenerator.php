<?php

namespace App\Services\Notifications;

use App\Enums\MembershipStatus;
use App\Models\Farm;
use App\Models\FarmMembership;
use App\Services\Dashboard\DashboardClock;
use App\Support\Access\FarmContext;
use Carbon\CarbonImmutable;

/** Evaluates every active member of one farm and records the notifications they should get. Idempotent: running it again creates nothing new. */
class NotificationGenerator
{
    public function __construct(private NotificationDecider $decider, private NotificationService $notifications) {}

    /** @return array{members: int, created: int} */
    public function forFarm(Farm $farm, ?CarbonImmutable $now = null): array
    {
        $clock = new DashboardClock($farm, $now);
        $created = 0;
        $members = FarmMembership::where('farm_id', $farm->id)->where('status', MembershipStatus::Active->value)->with('user')->orderBy('created_at')->orderBy('id')->get();
        foreach ($members as $membership) {
            $membership->setRelation('farm', $farm);
            $created += $this->notifications->deliver($membership, $this->decider->decide(new FarmContext($farm, $membership), $clock));
        }

        return ['members' => $members->count(), 'created' => $created];
    }
}
