<?php

namespace Tests\Feature\Auth;

use App\Enums\FarmRole;
use App\Models\Farm;
use App\Models\FarmMembership;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;

class OnboardingTest extends AuthTestCase
{
    private function setup_(string $name = 'Green Acres')
    {
        return $this->postJson('/api/v1/onboarding/farm', ['name' => $name]);
    }

    public function test_verified_user_creates_farm_and_becomes_owner(): void
    {
        $user = User::factory()->create();
        $this->signInAs($user);

        $this->setup_('  Green   Acres  ')
            ->assertCreated()
            ->assertJsonPath('data.onboarded', true)
            ->assertJsonPath('data.next_action', 'none')
            ->assertJsonPath('data.farm.name', 'Green Acres')
            ->assertJsonPath('data.farm.role', 'owner')
            ->assertJsonPath('data.farm.country_code', 'NG')
            ->assertJsonPath('data.farm.currency', 'NGN')
            ->assertJsonPath('data.farm.timezone', 'Africa/Lagos')
            ->assertJsonPath('data.farm.locale', 'en');

        $farm = Farm::firstOrFail();
        $this->assertTrue(Str::isUuid($farm->id, 7));
        $this->assertNotNull($user->fresh()->onboarded_at);

        $membership = FarmMembership::firstOrFail();
        $this->assertTrue(Str::isUuid($membership->id, 7));
        $this->assertSame($farm->id, $membership->farm_id);
        $this->assertSame($user->id, $membership->user_id);
        $this->assertSame(FarmRole::Owner, $membership->role);
        $this->assertSame(0, $farm->locations()->count());
        $this->assertSame(0, $farm->productionAreas()->count());
        $this->assertSame(0, $farm->storageLocations()->count());
        $this->assertSame(0, $farm->productionCycles()->count());
        $this->getJson('/api/v1/farm')->assertOk();
    }

    public function test_unverified_user_cannot_onboard(): void
    {
        $this->signInAs(User::factory()->unverified()->create());

        $this->setup_()->assertForbidden()->assertJsonPath('code', 'email_verification_required');

        $this->assertSame(0, Farm::count());
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->setup_()->assertUnauthorized();
    }

    public function test_farm_name_is_required_and_bounded(): void
    {
        $this->signInAs($user = User::factory()->create());

        $this->postJson('/api/v1/onboarding/farm', [])->assertJsonValidationErrors('name');
        $this->setup_('   ')->assertJsonValidationErrors('name');
        $this->setup_('A')->assertJsonValidationErrors('name');
        $this->setup_(str_repeat('x', 121))->assertJsonValidationErrors('name');

        $this->assertNull($user->fresh()->onboarded_at);
        $this->assertSame(0, Farm::count());
    }

    public function test_extra_fields_are_ignored_and_cannot_choose_ownership(): void
    {
        $this->signInAs(User::factory()->create());

        $this->postJson('/api/v1/onboarding/farm', [
            'name' => 'Real Farm',
            'id' => 'chosen', 'currency' => 'USD', 'timezone' => 'UTC', 'user_id' => 'someone-else',
        ])->assertCreated()->assertJsonPath('data.farm.currency', 'NGN');

        $this->assertSame('NGN', Farm::firstOrFail()->currency);
        $this->assertNotSame('chosen', Farm::firstOrFail()->id);
    }

    public function test_repeated_onboarding_does_not_create_a_second_farm(): void
    {
        $this->signInAs(User::factory()->create());

        $this->setup_('First')->assertCreated();
        $this->setup_('Second')->assertStatus(409)->assertJsonPath('code', 'already_onboarded');

        $this->assertSame(1, Farm::count());
        $this->assertSame(1, FarmMembership::count());
        $this->assertSame('First', Farm::firstOrFail()->name);
    }

    public function test_onboarding_is_atomic(): void
    {
        $user = User::factory()->create();
        $this->signInAs($user);

        FarmMembership::creating(fn () => throw new RuntimeException('boom'));

        $this->setup_()->assertStatus(500);

        $this->assertSame(0, Farm::count());
        $this->assertSame(0, FarmMembership::count());
        $this->assertNull($user->fresh()->onboarded_at);
    }

    public function test_blocker_rejects_unverified_and_non_onboarded_but_admits_onboarded_users(): void
    {
        Route::middleware(['api', 'app.access'])->get('/api/v1/_probe/app', fn () => response()->json(['ok' => true]));

        $this->signInAs(User::factory()->unverified()->create());
        $this->getJson('/api/v1/_probe/app')->assertForbidden()->assertJsonPath('code', 'email_verification_required');

        $this->signInAs(User::factory()->create());
        $this->getJson('/api/v1/_probe/app')->assertForbidden()->assertJsonPath('code', 'onboarding_required');

        $this->signInAs(User::factory()->onboarded()->create());
        $this->getJson('/api/v1/_probe/app')->assertOk();
    }

    public function test_blocker_rejects_guests(): void
    {
        Route::middleware(['api', 'app.access'])->get('/api/v1/_probe/app', fn () => response()->json(['ok' => true]));

        $this->getJson('/api/v1/_probe/app')->assertUnauthorized();
    }

    public function test_user_cannot_access_another_users_farm(): void
    {
        $owner = User::factory()->onboarded('Owner Farm')->create();
        $stranger = User::factory()->onboarded('Stranger Farm')->create();
        $ownerFarm = $owner->currentFarm();

        $this->assertTrue(Gate::forUser($owner)->allows('view', $ownerFarm));
        $this->assertFalse(Gate::forUser($stranger)->allows('view', $ownerFarm));
        $this->assertSame('Stranger Farm', $stranger->currentFarm()->name);

        // Farm scoping through a route: resolve the farm only through the user's own memberships.
        Route::middleware(['api', 'app.access'])->get('/api/v1/_probe/farms/{farm}', function (Farm $farm) {
            Gate::authorize('view', $farm);

            return response()->json(['name' => $farm->name]);
        });

        $this->signInAs($owner)->getJson("/api/v1/_probe/farms/{$ownerFarm->id}")->assertOk()->assertJsonPath('name', 'Owner Farm');
        $this->signInAs($stranger)->getJson("/api/v1/_probe/farms/{$ownerFarm->id}")->assertForbidden();
    }
}
