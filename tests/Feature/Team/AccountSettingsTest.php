<?php

namespace Tests\Feature\Team;

use App\Enums\FarmRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AccountSettingsTest extends TeamTestCase
{
    public function test_user_can_read_and_update_their_name(): void
    {
        $this->signInAs($this->owner)->getJson('/api/v1/account')
            ->assertOk()->assertJsonPath('data.email', $this->owner->email)->assertJsonPath('data.name', 'Olu Owner');

        $this->patchJson('/api/v1/account', ['name' => '  Olu   Adeyemi '])
            ->assertOk()->assertJsonPath('data.name', 'Olu Adeyemi');

        $this->assertSame('Olu Adeyemi', $this->owner->fresh()->name);
    }

    public function test_profile_update_validates_name(): void
    {
        $this->signInAs($this->owner);

        $this->patchJson('/api/v1/account', [])->assertJsonValidationErrors('name');
        $this->patchJson('/api/v1/account', ['name' => 'A'])->assertJsonValidationErrors('name');
        $this->patchJson('/api/v1/account', ['name' => str_repeat('x', 121)])->assertJsonValidationErrors('name');
    }

    public function test_protected_fields_cannot_be_mass_assigned_through_the_profile(): void
    {
        $user = User::factory()->unverified()->create(['name' => 'Unv']);
        $original = $user->only(['email', 'password']);

        // Verification is required for the account endpoints, so use a verified user for the write path.
        $verified = User::factory()->create(['name' => 'Ver']);
        $this->signInAs($verified)->patchJson('/api/v1/account', [
            'name' => 'Ver Two',
            'email' => 'hijack@example.com',
            'email_verified_at' => null,
            'onboarded_at' => now()->toIso8601String(),
            'suspended_at' => now()->toIso8601String(),
            'password' => 'Hacked12345',
            'id' => 'forged-id',
        ])->assertOk();

        $fresh = $verified->fresh();
        $this->assertSame('Ver Two', $fresh->name);
        $this->assertSame($verified->email, $fresh->email);
        $this->assertNotNull($fresh->email_verified_at);
        $this->assertNull($fresh->onboarded_at);
        $this->assertNull($fresh->suspended_at);
        $this->assertSame($verified->id, $fresh->id);
        $this->assertFalse(Hash::check('Hacked12345', $fresh->password));

        // An unverified user cannot use account endpoints at all (Phase 1 guarantee preserved).
        $this->signInAs($user)->patchJson('/api/v1/account', ['name' => 'Nope Nope'])
            ->assertForbidden()->assertJsonPath('code', 'email_verification_required');
        $this->assertSame($original['email'], $user->fresh()->email);
    }

    public function test_guest_cannot_use_account_endpoints(): void
    {
        $this->getJson('/api/v1/account')->assertUnauthorized();
        $this->patchJson('/api/v1/account', ['name' => 'Nobody'])->assertUnauthorized();
        $this->putJson('/api/v1/account/password', [])->assertUnauthorized();
    }

    public function test_user_can_change_password_with_current_password(): void
    {
        $user = User::factory()->create();
        $user->createToken('mobile');

        $this->signInAs($user)->putJson('/api/v1/account/password', [
            'current_password' => 'Password123',
            'password' => 'NewPassw0rd',
            'password_confirmation' => 'NewPassw0rd',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewPassw0rd', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_password_change_rejects_wrong_current_weak_unconfirmed_or_unchanged_passwords(): void
    {
        $user = User::factory()->create();
        $this->signInAs($user);

        $this->putJson('/api/v1/account/password', ['current_password' => 'WrongPass1', 'password' => 'NewPassw0rd', 'password_confirmation' => 'NewPassw0rd'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->putJson('/api/v1/account/password', ['current_password' => 'Password123', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertJsonValidationErrors('password');
        $this->putJson('/api/v1/account/password', ['current_password' => 'Password123', 'password' => 'NewPassw0rd', 'password_confirmation' => 'Different1'])
            ->assertJsonValidationErrors('password');
        $this->putJson('/api/v1/account/password', ['current_password' => 'Password123', 'password' => 'Password123', 'password_confirmation' => 'Password123'])
            ->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('Password123', $user->fresh()->password));
    }

    public function test_passwordless_google_account_is_told_to_use_forgot_password(): void
    {
        $user = User::factory()->create(['password' => null]);

        $this->signInAs($user)->putJson('/api/v1/account/password', [
            'current_password' => 'x', 'password' => 'NewPassw0rd', 'password_confirmation' => 'NewPassw0rd',
        ])->assertStatus(409)->assertJsonPath('code', 'password_not_set');
    }

    public function test_notification_preferences_default_to_on_and_are_per_member(): void
    {
        $owner = $this->signInAs($this->owner);
        $owner->getJson('/api/v1/settings/notifications')->assertOk()
            ->assertJsonPath('data.channels.in_app', true)->assertJsonPath('data.channels.email', true);

        $owner->putJson('/api/v1/settings/notifications', ['channels' => ['email' => false]])
            ->assertOk()->assertJsonPath('data.channels.email', false)->assertJsonPath('data.channels.in_app', true);
        $owner->getJson('/api/v1/settings/notifications')->assertJsonPath('data.channels.email', false);

        // Another member of the same farm is unaffected; the owner's row is only theirs.
        $worker = $this->member(FarmRole::FarmWorker);
        $this->signInAs($worker)->getJson('/api/v1/settings/notifications')->assertJsonPath('data.channels.email', true);
        $this->putJson('/api/v1/settings/notifications', ['channels' => ['in_app' => false]])->assertOk();

        $this->assertTrue($this->membershipOf($this->owner)->notification_preferences['channels']['in_app']);
        $this->assertFalse($this->membershipOf($worker)->notification_preferences['channels']['in_app']);
    }

    public function test_notification_preferences_reject_unknown_channels_and_non_booleans(): void
    {
        $this->signInAs($this->owner);

        $this->putJson('/api/v1/settings/notifications', ['channels' => ['whatsapp' => true]])->assertStatus(422)->assertJsonValidationErrors('channels');
        $this->putJson('/api/v1/settings/notifications', ['channels' => ['email' => 'maybe']])->assertStatus(422)->assertJsonValidationErrors('channels.email');
        $this->putJson('/api/v1/settings/notifications', [])->assertStatus(422)->assertJsonValidationErrors('channels');
    }

    public function test_notification_preferences_require_farm_membership(): void
    {
        $this->getJson('/api/v1/settings/notifications')->assertUnauthorized();

        $this->signInAs(User::factory()->create())->getJson('/api/v1/settings/notifications')->assertForbidden();
    }
}
