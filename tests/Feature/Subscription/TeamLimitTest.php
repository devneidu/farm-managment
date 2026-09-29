<?php

namespace Tests\Feature\Subscription;

use App\Enums\FarmRole;
use App\Models\FarmInvitation;
use App\Models\FarmMembership;
use App\Models\User;
use App\Notifications\FarmInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Team\TeamTestCase;

/** Team capacity is the first real entitlement enforced in a business flow (Free plan = 3 seats). */
class TeamLimitTest extends TeamTestCase
{
    private function invite(string $email = 'new@example.com', string $role = 'farm_worker')
    {
        return $this->postJson('/api/v1/farm/invitations', ['email' => $email, 'role' => $role]);
    }

    private function usage(): array
    {
        return $this->getJson('/api/v1/subscription/usage')->assertOk()->json('data.limits.team_members');
    }

    public function test_below_the_limit_invites_work_and_at_the_limit_they_are_rejected_with_details(): void
    {
        $this->signInAs($this->owner);

        $this->invite('a@example.com')->assertCreated();
        $this->invite('b@example.com')->assertCreated(); // owner + 2 pending = 3 of 3

        $this->invite('c@example.com')->assertStatus(409)
            ->assertJsonPath('code', 'plan_limit_reached')
            ->assertJsonPath('details.entitlement_key', 'team_members')
            ->assertJsonPath('details.limit', 3)
            ->assertJsonPath('details.usage', 3)
            ->assertJsonPath('details.remaining', 0);

        $this->assertSame(2, FarmInvitation::count());
        Notification::assertSentOnDemandTimes(FarmInvitationNotification::class, 2);
    }

    public function test_usage_counts_active_members_including_owner_plus_pending_invitations_only(): void
    {
        $removed = $this->member(FarmRole::FarmWorker);
        $this->removeMembership($removed);
        $this->signInAs($this->owner);

        $this->assertSame(1, $this->usage()['usage']); // removed member does not count

        $this->invite('a@example.com')->assertCreated();
        $this->assertSame(['usage' => 2, 'remaining' => 1], array_intersect_key($this->usage(), array_flip(['usage', 'remaining'])));

        $this->member(FarmRole::Finance);
        $this->assertSame(3, $this->usage()['usage']);
    }

    public function test_revoked_and_expired_invitations_release_their_seat(): void
    {
        $this->signInAs($this->owner);
        $this->invite('a@example.com')->assertCreated();
        $this->invite('b@example.com')->assertCreated();
        $this->invite('c@example.com')->assertStatus(409);

        FarmInvitation::where('email', 'a@example.com')->update(['expires_at' => now()->subMinute()]);
        $this->invite('c@example.com')->assertCreated();
        $this->invite('d@example.com')->assertStatus(409);

        $id = FarmInvitation::where('email', 'b@example.com')->value('id');
        $this->deleteJson("/api/v1/farm/invitations/{$id}")->assertOk();
        $this->invite('d@example.com')->assertCreated();
    }

    public function test_removing_a_member_frees_capacity(): void
    {
        $a = $this->member(FarmRole::FarmWorker);
        $this->member(FarmRole::Finance);
        $this->signInAs($this->owner);

        $this->invite()->assertStatus(409)->assertJsonPath('code', 'plan_limit_reached');

        $this->deleteJson('/api/v1/farm/members/'.$this->membershipOf($a)->id)->assertOk();
        $this->invite()->assertCreated();
    }

    public function test_rbac_and_entitlements_are_independent_checks(): void
    {
        $this->member(FarmRole::FarmWorker);
        $manager = $this->member(FarmRole::Manager); // farm is now full: owner + 2

        // RBAC denial does not depend on the plan, even with free capacity
        $worker = User::whereHas('memberships', fn ($q) => $q->where('role', 'farm_worker'))->firstOrFail();
        $this->signInAs($worker)->invite()->assertForbidden()->assertJsonPath('code', 'forbidden');

        // RBAC passes, plan says no
        $this->signInAs($manager)->invite()->assertStatus(409)->assertJsonPath('code', 'plan_limit_reached');
        $this->signInAs($this->owner)->invite()->assertStatus(409)->assertJsonPath('code', 'plan_limit_reached');

        // a plan with capacity never grants a role a permission it does not have
        $this->onPlan('farm-business');
        $this->signInAs($worker)->invite()->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->signInAs($manager)->invite()->assertCreated();
    }

    public function test_upgrading_unlocks_capacity_and_unlimited_plans_have_no_cap(): void
    {
        $this->signInAs($this->owner);
        $this->invite('a@example.com')->assertCreated();
        $this->invite('b@example.com')->assertCreated();
        $this->invite('c@example.com')->assertStatus(409);

        $this->onPlan('farm-pro');
        $this->invite('c@example.com')->assertCreated();

        $this->onPlan('farm-business');
        foreach (range(1, 15) as $i) {
            $this->invite("bulk{$i}@example.com")->assertCreated();
        }
        $usage = $this->usage();
        $this->assertTrue($usage['unlimited']);
        $this->assertNull($usage['limit']);
        $this->assertNull($usage['remaining']);
        $this->assertFalse($usage['exceeded']);
    }

    public function test_accepting_a_pending_invitation_at_the_limit_still_works(): void
    {
        $this->signInAs($this->owner);
        $this->invite('a@example.com')->assertCreated();
        $this->invite('b@example.com')->assertCreated(); // exactly full
        $token = $this->tokenFor('a@example.com');

        $invitee = User::factory()->create(['email' => 'a@example.com', 'onboarded_at' => now()]);
        $this->signInAs($invitee)->postJson('/api/v1/invitations/accept', ['token' => $token])->assertOk();

        $this->assertTrue(FarmMembership::active()->where('farm_id', $this->farm->id)->where('user_id', $invitee->id)->exists());
        $this->assertSame(3, $this->signInAs($this->owner)->usage()['usage']);
    }

    public function test_resending_a_live_invitation_at_the_limit_works_but_an_expired_one_needs_a_free_seat(): void
    {
        $this->signInAs($this->owner);
        $this->invite('a@example.com')->assertCreated();
        $this->invite('b@example.com')->assertCreated();
        $live = FarmInvitation::where('email', 'a@example.com')->value('id');
        $stale = FarmInvitation::where('email', 'b@example.com')->value('id');

        $this->postJson("/api/v1/farm/invitations/{$live}/resend")->assertOk();

        FarmInvitation::whereKey($stale)->update(['expires_at' => now()->subMinute()]);
        $this->invite('c@example.com')->assertCreated(); // takes the seat the expired one released

        $this->postJson("/api/v1/farm/invitations/{$stale}/resend")->assertStatus(409)->assertJsonPath('code', 'plan_limit_reached');
    }

    public function test_downgrade_keeps_existing_members_readable_and_only_blocks_growth(): void
    {
        $this->onPlan('farm-pro');
        foreach ([FarmRole::Manager, FarmRole::FarmWorker, FarmRole::FarmWorker, FarmRole::Finance] as $role) {
            $this->member($role);
        }
        $this->assertSame(5, FarmMembership::active()->where('farm_id', $this->farm->id)->count());

        $this->onPlan('free'); // limit 3, usage 5

        $this->signInAs($this->owner);
        $this->assertCount(5, $this->getJson('/api/v1/farm/members')->assertOk()->json('data'));
        $this->assertSame(5, FarmMembership::active()->where('farm_id', $this->farm->id)->count());

        $usage = $this->usage();
        $this->assertSame([3, 5, 0, true], [$usage['limit'], $usage['usage'], $usage['remaining'], $usage['exceeded']]);

        $this->invite()->assertStatus(409)->assertJsonPath('code', 'plan_limit_reached')->assertJsonPath('details.remaining', 0);
    }

    private function tokenFor(string $email): string
    {
        $token = null;

        Notification::assertSentOnDemand(FarmInvitationNotification::class, function (FarmInvitationNotification $n, $channels, $notifiable) use (&$token, $email) {
            if ($notifiable->routes['mail'] === $email) {
                $token = $n->token;
            }

            return true;
        });

        return $token;
    }
}
