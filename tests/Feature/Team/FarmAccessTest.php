<?php

namespace Tests\Feature\Team;

use App\Enums\FarmRole;
use App\Events\Access\FarmSettingsUpdated;
use App\Models\FarmMembership;
use App\Models\User;
use Illuminate\Support\Facades\Event;

class FarmAccessTest extends TeamTestCase
{
    public function test_owner_sees_farm_with_role_and_permissions(): void
    {
        $this->signInAs($this->owner)->getJson('/api/v1/farm')
            ->assertOk()
            ->assertJsonPath('data.id', $this->farm->id)
            ->assertJsonPath('data.name', 'Green Acres')
            ->assertJsonPath('data.membership.role', 'owner')
            ->assertJsonPath('data.membership.role_label', 'Owner')
            ->assertJsonPath('data.currency', 'NGN');

        $this->assertContains('team.invite', $this->getJson('/api/v1/farm')->json('data.membership.permissions'));
    }

    public function test_every_mvp_role_can_view_the_farm_with_their_own_permissions(): void
    {
        foreach ([FarmRole::Manager, FarmRole::FarmWorker, FarmRole::Finance] as $role) {
            $this->signInAs($this->member($role))->getJson('/api/v1/farm')
                ->assertOk()
                ->assertJsonPath('data.membership.role', $role->value)
                ->assertJsonCount(count($role->permissions()), 'data.membership.permissions');
        }
    }

    public function test_guest_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/farm')->assertUnauthorized();
    }

    public function test_user_without_a_farm_is_stopped_by_onboarding_gate(): void
    {
        $this->signInAs(User::factory()->create())->getJson('/api/v1/farm')
            ->assertForbidden()->assertJsonPath('code', 'onboarding_required');
    }

    public function test_user_only_ever_sees_their_own_farm_never_another(): void
    {
        [$otherOwner, $otherFarm] = $this->otherFarm();

        $this->signInAs($otherOwner)->getJson('/api/v1/farm')->assertOk()->assertJsonPath('data.id', $otherFarm->id);
        $this->signInAs($this->owner)->getJson('/api/v1/farm')->assertOk()->assertJsonPath('data.id', $this->farm->id);
    }

    public function test_changing_the_farm_id_header_cannot_reach_another_farm(): void
    {
        [, $otherFarm] = $this->otherFarm();

        $this->signInAs($this->owner)
            ->withHeader('X-Farm-Id', $otherFarm->id)
            ->getJson('/api/v1/farm')
            ->assertForbidden()->assertJsonPath('code', 'farm_access_denied');

        $this->patchJson('/api/v1/farm', ['name' => 'Hijacked'])->assertForbidden();
        $this->assertSame('Other Farm', $otherFarm->fresh()->name);
    }

    public function test_farm_header_selects_a_farm_the_user_actively_belongs_to(): void
    {
        [, $otherFarm] = $this->otherFarm();
        $user = $this->member(FarmRole::Finance);
        $this->member(FarmRole::Manager, $otherFarm); // different user; ensure no bleed
        FarmMembership::create(['farm_id' => $otherFarm->id, 'user_id' => $user->id, 'role' => 'farm_worker']);

        $this->signInAs($user)->getJson('/api/v1/farm')->assertJsonPath('data.membership.role', 'finance');
        $this->withHeader('X-Farm-Id', $otherFarm->id)->getJson('/api/v1/farm')
            ->assertOk()->assertJsonPath('data.id', $otherFarm->id)->assertJsonPath('data.membership.role', 'farm_worker');
    }

    public function test_removed_membership_loses_access(): void
    {
        $worker = $this->member(FarmRole::FarmWorker);
        $this->signInAs($worker)->getJson('/api/v1/farm')->assertOk();

        $this->removeMembership($worker);

        $this->signInAs($worker)->getJson('/api/v1/farm')->assertForbidden()->assertJsonPath('code', 'no_active_farm');
        $this->withHeader('X-Farm-Id', $this->farm->id)->getJson('/api/v1/farm')->assertForbidden()->assertJsonPath('code', 'farm_access_denied');
    }

    public function test_owner_and_manager_can_update_the_farm_name(): void
    {
        Event::fake([FarmSettingsUpdated::class]);

        $this->signInAs($this->owner)->patchJson('/api/v1/farm', ['name' => '  Sunrise   Farm '])
            ->assertOk()->assertJsonPath('data.name', 'Sunrise Farm');
        $this->assertSame('Sunrise Farm', $this->farm->fresh()->name);
        Event::assertDispatched(FarmSettingsUpdated::class, fn ($e) => $e->changes['name']['from'] === 'Green Acres' && $e->changes['name']['to'] === 'Sunrise Farm');

        $this->signInAs($this->member(FarmRole::Manager))->patchJson('/api/v1/farm', ['name' => 'Managed Farm'])->assertOk();
        $this->assertSame('Managed Farm', $this->farm->fresh()->name);
    }

    public function test_worker_and_finance_cannot_update_the_farm(): void
    {
        foreach ([FarmRole::FarmWorker, FarmRole::Finance] as $role) {
            $this->signInAs($this->member($role))->patchJson('/api/v1/farm', ['name' => 'Nope Farm'])
                ->assertForbidden()->assertJsonPath('code', 'forbidden');
        }

        $this->assertSame('Green Acres', $this->farm->fresh()->name);
    }

    public function test_farm_update_validates_name_and_ignores_protected_fields(): void
    {
        $this->signInAs($this->owner);

        $this->patchJson('/api/v1/farm', [])->assertJsonValidationErrors('name');
        $this->patchJson('/api/v1/farm', ['name' => 'A'])->assertJsonValidationErrors('name');
        $this->patchJson('/api/v1/farm', ['name' => str_repeat('x', 121)])->assertJsonValidationErrors('name');

        $this->patchJson('/api/v1/farm', ['name' => 'Fine Farm', 'currency' => 'USD', 'id' => 'x', 'timezone' => 'UTC'])->assertOk();
        $farm = $this->farm->fresh();
        $this->assertSame('NGN', $farm->currency);
        $this->assertSame('Africa/Lagos', $farm->timezone);
        $this->assertSame($this->farm->id, $farm->id);
    }

    public function test_roles_endpoint_lists_presets_and_what_the_caller_may_assign(): void
    {
        $roles = $this->signInAs($this->member(FarmRole::Manager))->getJson('/api/v1/roles')->assertOk()->json('data');

        $this->assertSame(['owner', 'manager', 'farm_worker', 'finance'], array_column($roles, 'id'));
        $this->assertSame([false, false, true, true], array_column($roles, 'assignable'));

        $this->signInAs($this->member(FarmRole::FarmWorker))->getJson('/api/v1/roles')->assertForbidden();
    }
}
