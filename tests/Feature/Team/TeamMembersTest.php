<?php

namespace Tests\Feature\Team;

use App\Enums\FarmRole;
use App\Enums\MembershipStatus;
use App\Events\Access\MemberRemoved;
use App\Events\Access\MemberRoleChanged;
use App\Models\FarmMembership;
use Illuminate\Support\Facades\Event;

class TeamMembersTest extends TeamTestCase
{
    private function url(FarmMembership|string $m): string
    {
        return '/api/v1/farm/members/'.($m instanceof FarmMembership ? $m->id : $m);
    }

    public function test_owner_and_manager_can_list_members_owner_first(): void
    {
        $manager = $this->member(FarmRole::Manager, name: 'Mary Manager');
        $this->member(FarmRole::FarmWorker, name: 'Wale Worker');

        $rows = $this->signInAs($manager)->getJson('/api/v1/farm/members')->assertOk()->json('data');

        $this->assertCount(3, $rows);
        $this->assertSame('owner', $rows[0]['role']);
        $this->assertSame($this->owner->email, $rows[0]['user']['email']);
        $this->assertTrue(collect($rows)->firstWhere('user.name', 'Mary Manager')['is_current_user']);
        $this->assertArrayNotHasKey('password', $rows[0]['user']);

        $this->signInAs($this->owner)->getJson('/api/v1/farm/members')->assertOk();
    }

    public function test_worker_and_finance_cannot_view_the_team(): void
    {
        foreach ([FarmRole::FarmWorker, FarmRole::Finance] as $role) {
            $this->signInAs($this->member($role))->getJson('/api/v1/farm/members')->assertForbidden()->assertJsonPath('code', 'forbidden');
        }
    }

    public function test_members_of_other_farms_and_removed_members_are_not_listed_or_reachable(): void
    {
        [$otherOwner, $otherFarm] = $this->otherFarm();
        $otherMembership = $this->membershipOf($otherOwner, $otherFarm);
        $removed = $this->member(FarmRole::FarmWorker);
        $this->removeMembership($removed);

        $this->signInAs($this->owner);

        $ids = array_column($this->getJson('/api/v1/farm/members')->json('data'), 'id');
        $this->assertNotContains($otherMembership->id, $ids);
        $this->assertNotContains($this->membershipOf($removed)->id, $ids);

        // Guessing another farm's membership UUID is a 404, not a 403 (no existence leak) - for every verb.
        $this->getJson($this->url($otherMembership))->assertNotFound();
        $this->patchJson($this->url($otherMembership), ['role' => 'finance'])->assertNotFound();
        $this->deleteJson($this->url($otherMembership))->assertNotFound();
        $this->getJson($this->url($this->membershipOf($removed)))->assertNotFound();

        $this->assertTrue($otherMembership->fresh()->isActive());
    }

    public function test_owner_can_change_roles(): void
    {
        Event::fake([MemberRoleChanged::class]);
        $worker = $this->member(FarmRole::FarmWorker);

        $this->signInAs($this->owner)->patchJson($this->url($this->membershipOf($worker)), ['role' => 'manager'])
            ->assertOk()->assertJsonPath('data.role', 'manager')->assertJsonPath('data.role_label', 'Manager');

        $this->assertSame(FarmRole::Manager, $this->membershipOf($worker)->role);
        Event::assertDispatched(MemberRoleChanged::class, fn ($e) => $e->from === FarmRole::FarmWorker && $e->to === FarmRole::Manager && $e->actor->is($this->owner));
    }

    public function test_manager_can_only_manage_workers_and_finance(): void
    {
        $manager = $this->member(FarmRole::Manager);
        $worker = $this->member(FarmRole::FarmWorker);
        $otherManager = $this->member(FarmRole::Manager);

        $this->signInAs($manager);

        $this->patchJson($this->url($this->membershipOf($worker)), ['role' => 'finance'])->assertOk();
        // cannot promote a worker to manager, cannot touch a peer manager or the owner
        $this->patchJson($this->url($this->membershipOf($worker)), ['role' => 'manager'])->assertForbidden()->assertJsonPath('code', 'insufficient_role');
        $this->patchJson($this->url($this->membershipOf($otherManager)), ['role' => 'farm_worker'])->assertForbidden()->assertJsonPath('code', 'insufficient_role');
        $this->patchJson($this->url($this->membershipOf($this->owner)), ['role' => 'farm_worker'])->assertForbidden()->assertJsonPath('code', 'insufficient_role');

        $this->assertSame(FarmRole::Owner, $this->membershipOf($this->owner)->role);
        $this->assertSame(FarmRole::Manager, $this->membershipOf($otherManager)->role);
    }

    public function test_nobody_can_promote_to_owner_including_self_promotion(): void
    {
        $manager = $this->member(FarmRole::Manager);
        $worker = $this->member(FarmRole::FarmWorker);

        $this->signInAs($manager)->patchJson($this->url($this->membershipOf($manager)), ['role' => 'owner'])
            ->assertStatus(422)->assertJsonPath('code', 'ownership_transfer_unsupported');
        $this->signInAs($this->owner)->patchJson($this->url($this->membershipOf($worker)), ['role' => 'owner'])
            ->assertStatus(422)->assertJsonPath('code', 'ownership_transfer_unsupported');

        $this->assertSame(FarmRole::Manager, $this->membershipOf($manager)->role);
        $this->assertSame(1, FarmMembership::where('farm_id', $this->farm->id)->where('role', 'owner')->count());
    }

    public function test_worker_cannot_change_own_or_anyone_elses_role(): void
    {
        $worker = $this->member(FarmRole::FarmWorker);
        $other = $this->member(FarmRole::Finance);

        $this->signInAs($worker);
        $this->patchJson($this->url($this->membershipOf($worker)), ['role' => 'manager'])->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->patchJson($this->url($this->membershipOf($other)), ['role' => 'manager'])->assertForbidden();

        $this->assertSame(FarmRole::FarmWorker, $this->membershipOf($worker)->role);
    }

    public function test_manager_cannot_change_own_role(): void
    {
        $manager = $this->member(FarmRole::Manager);

        $this->signInAs($manager)->patchJson($this->url($this->membershipOf($manager)), ['role' => 'finance'])->assertForbidden();
        $this->assertSame(FarmRole::Manager, $this->membershipOf($manager)->role);
    }

    public function test_arbitrary_roles_are_rejected(): void
    {
        $worker = $this->member(FarmRole::FarmWorker);
        $this->signInAs($this->owner);

        foreach (['admin', 'superuser', 'Owner ', '', null] as $bad) {
            $this->patchJson($this->url($this->membershipOf($worker)), ['role' => $bad])->assertStatus(422)->assertJsonValidationErrors('role');
        }

        $this->assertSame(FarmRole::FarmWorker, $this->membershipOf($worker)->role);
    }

    public function test_only_owner_exists_so_owner_cannot_demote_or_remove_themselves(): void
    {
        $this->signInAs($this->owner);
        $ownerMembership = $this->membershipOf($this->owner);

        $this->patchJson($this->url($ownerMembership), ['role' => 'manager'])->assertStatus(409)->assertJsonPath('code', 'last_owner');
        $this->deleteJson($this->url($ownerMembership))->assertStatus(409)->assertJsonPath('code', 'last_owner');

        $this->assertTrue($ownerMembership->fresh()->isActive());
        $this->assertSame(FarmRole::Owner, $ownerMembership->fresh()->role);
    }

    public function test_manager_cannot_remove_the_owner(): void
    {
        $manager = $this->member(FarmRole::Manager);

        $this->signInAs($manager)->deleteJson($this->url($this->membershipOf($this->owner)))->assertForbidden()->assertJsonPath('code', 'insufficient_role');
        $this->assertTrue($this->membershipOf($this->owner)->isActive());
    }

    public function test_worker_cannot_remove_anyone(): void
    {
        $worker = $this->member(FarmRole::FarmWorker);
        $other = $this->member(FarmRole::Finance);

        $this->signInAs($worker)->deleteJson($this->url($this->membershipOf($other)))->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->assertTrue($this->membershipOf($other)->isActive());
    }

    public function test_owner_can_remove_a_member_who_then_loses_farm_access(): void
    {
        Event::fake([MemberRemoved::class]);
        $manager = $this->member(FarmRole::Manager);

        $this->signInAs($manager)->getJson('/api/v1/farm/members')->assertOk();

        $this->signInAs($this->owner)->deleteJson($this->url($this->membershipOf($manager)))->assertOk();

        $membership = $this->membershipOf($manager);
        $this->assertSame(MembershipStatus::Removed, $membership->status);
        $this->assertNotNull($membership->removed_at);
        $this->assertSame($this->owner->id, $membership->removed_by_user_id);
        Event::assertDispatched(MemberRemoved::class);

        // Access is gone at once; onboarding is NOT undone (see the dedicated test below).
        $this->signInAs($manager->fresh())->getJson('/api/v1/farm/members')->assertForbidden()->assertJsonPath('code', 'no_active_farm');
        $this->assertNull($manager->fresh()->currentFarm());
    }

    public function test_removing_the_last_membership_keeps_onboarded_at_and_only_removes_farm_access(): void
    {
        $manager = $this->member(FarmRole::Manager);
        $onboardedAt = $manager->onboarded_at->toDateTimeString();

        $this->signInAs($this->owner)->deleteJson($this->url($this->membershipOf($manager)))->assertOk();

        $this->assertSame($onboardedAt, $manager->fresh()->onboarded_at->toDateTimeString());

        $this->signInAs($manager->fresh());
        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.authenticated', true)
            ->assertJsonPath('data.email_verified', true)
            ->assertJsonPath('data.onboarded', true)
            ->assertJsonPath('data.has_active_farm', false)
            ->assertJsonPath('data.farm', null)
            ->assertJsonPath('data.next_action', 'no_active_farm');

        foreach (['/api/v1/farm', '/api/v1/farm/members', '/api/v1/farm/invitations', '/api/v1/roles'] as $uri) {
            $this->getJson($uri)->assertForbidden()->assertJsonPath('code', 'no_active_farm');
        }
        $this->patchJson('/api/v1/farm', ['name' => 'Sneaky'])->assertForbidden()->assertJsonPath('code', 'no_active_farm');
        $this->assertSame('Green Acres', $this->farm->fresh()->name);

        // They must not be sent through the original Farm Name onboarding again.
        $this->postJson('/api/v1/onboarding/farm', ['name' => 'Second Farm'])->assertStatus(409)->assertJsonPath('code', 'already_onboarded');
    }

    public function test_removed_member_keeps_onboarding_when_they_belong_to_another_farm(): void
    {
        [, $otherFarm] = $this->otherFarm();
        $user = $this->member(FarmRole::FarmWorker);
        FarmMembership::create(['farm_id' => $otherFarm->id, 'user_id' => $user->id, 'role' => 'finance']);

        $this->signInAs($this->owner)->deleteJson($this->url($this->membershipOf($user)))->assertOk();

        $this->assertNotNull($user->fresh()->onboarded_at);
        $this->assertSame($otherFarm->id, $user->fresh()->currentFarm()->id);
    }

    public function test_manager_can_remove_a_worker_but_not_a_peer_manager(): void
    {
        $manager = $this->member(FarmRole::Manager);
        $worker = $this->member(FarmRole::FarmWorker);
        $peer = $this->member(FarmRole::Manager);

        $this->signInAs($manager);
        $this->deleteJson($this->url($this->membershipOf($peer)))->assertForbidden()->assertJsonPath('code', 'insufficient_role');
        $this->deleteJson($this->url($this->membershipOf($manager)))->assertForbidden();
        $this->deleteJson($this->url($this->membershipOf($worker)))->assertOk();
    }

    public function test_show_returns_a_single_member(): void
    {
        $worker = $this->member(FarmRole::FarmWorker, name: 'Wale');

        $this->signInAs($this->owner)->getJson($this->url($this->membershipOf($worker)))
            ->assertOk()->assertJsonPath('data.user.name', 'Wale')->assertJsonPath('data.role', 'farm_worker');
    }
}
