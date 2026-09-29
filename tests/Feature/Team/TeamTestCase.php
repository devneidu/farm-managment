<?php

namespace Tests\Feature\Team;

use App\Enums\FarmRole;
use App\Enums\MembershipStatus;
use App\Models\Farm;
use App\Models\FarmMembership;
use App\Models\Plan;
use App\Models\User;
use App\Services\Subscription\SubscriptionService;
use Tests\Feature\Auth\AuthTestCase;

abstract class TeamTestCase extends AuthTestCase
{
    protected Farm $farm;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->onboarded('Green Acres')->create(['name' => 'Olu Owner']);
        $this->farm = $this->owner->currentFarm();
    }

    /** A verified, onboarded user holding $role on $farm (default: the shared farm). */
    protected function member(FarmRole $role, ?Farm $farm = null, string $name = 'Member'): User
    {
        $farm ??= $this->farm;

        $user = User::factory()->create(['name' => $name, 'onboarded_at' => now()]);
        FarmMembership::create(['farm_id' => $farm->id, 'user_id' => $user->id, 'role' => $role->value]);

        return $user;
    }

    /** Moves a farm onto a plan by slug (test setup for plan-dependent behaviour). */
    protected function onPlan(string $slug, ?Farm $farm = null): void
    {
        app(SubscriptionService::class)->changePlan($farm ?? $this->farm, Plan::where('slug', $slug)->firstOrFail());
    }

    protected function membershipOf(User $user, ?Farm $farm = null): FarmMembership
    {
        return FarmMembership::where('farm_id', ($farm ?? $this->farm)->id)->where('user_id', $user->id)->firstOrFail();
    }

    protected function removeMembership(User $user, ?Farm $farm = null): void
    {
        $this->membershipOf($user, $farm)->forceFill(['status' => MembershipStatus::Removed, 'removed_at' => now()])->save();
    }

    /** A completely separate farm with its own owner. */
    protected function otherFarm(): array
    {
        $owner = User::factory()->onboarded('Other Farm')->create();

        return [$owner, $owner->currentFarm()];
    }
}
