<?php

namespace Tests\Feature\Auth;

use App\Models\User;

class LoginTest extends AuthTestCase
{
    private function login(string $email, string $password = 'Password123')
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    public function test_verified_and_onboarded_user_gets_application_state(): void
    {
        $user = User::factory()->onboarded('Green Acres')->create(['email' => 'a@example.com']);

        $this->login('A@Example.com')
            ->assertOk()
            ->assertJsonPath('data.authenticated', true)
            ->assertJsonPath('data.email_verified', true)
            ->assertJsonPath('data.onboarded', true)
            ->assertJsonPath('data.next_action', 'none')
            ->assertJsonPath('data.farm.name', 'Green Acres')
            ->assertJsonPath('data.farm.role', 'owner')
            ->assertJsonPath('data.farm.currency', 'NGN')
            ->assertJsonPath('data.farm.timezone', 'Africa/Lagos');

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_verified_but_not_onboarded_user_is_sent_to_farm_setup(): void
    {
        User::factory()->create(['email' => 'a@example.com']);

        $this->login('a@example.com')
            ->assertOk()
            ->assertJsonPath('data.email_verified', true)
            ->assertJsonPath('data.onboarded', false)
            ->assertJsonPath('data.next_action', 'complete_farm_setup')
            ->assertJsonPath('data.farm', null);
    }

    public function test_unverified_user_is_told_to_verify_email(): void
    {
        User::factory()->unverified()->create(['email' => 'a@example.com']);

        $this->login('a@example.com')
            ->assertOk()
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonPath('data.next_action', 'verify_email');
    }

    public function test_wrong_password_and_unknown_email_get_identical_generic_failure(): void
    {
        User::factory()->create(['email' => 'a@example.com']);

        $wrong = $this->login('a@example.com', 'WrongPassword1');
        $unknown = $this->login('nobody@example.com');

        $wrong->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
        $unknown->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
        $this->assertSame($wrong->json('message'), $unknown->json('message'));
        $this->assertGuest('web');
    }

    public function test_google_only_account_cannot_password_login(): void
    {
        User::factory()->create(['email' => 'a@example.com', 'password' => null]);

        $this->login('a@example.com', 'anything123')->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_suspended_account_cannot_login(): void
    {
        User::factory()->suspended()->create(['email' => 'a@example.com']);

        $this->login('a@example.com')->assertForbidden()->assertJsonPath('code', 'account_suspended');
        $this->assertGuest('web');
    }

    public function test_login_is_throttled_per_email_and_ip(): void
    {
        User::factory()->create(['email' => 'a@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->login('a@example.com', 'WrongPassword1')->assertUnauthorized();
        }

        // Even the correct password is refused once the limit is hit.
        $this->login('a@example.com')
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests')
            ->assertHeader('Retry-After');
    }

    public function test_me_reports_state_and_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');

        $user = User::factory()->unverified()->create();
        $this->actingAs($user, 'web');
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.next_action', 'verify_email')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.has_password', true)
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.onboarded_at');
    }

    public function test_me_reflects_farm_for_onboarded_user(): void
    {
        $user = User::factory()->onboarded('My Farm')->create();

        $this->actingAs($user, 'web')->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.onboarded', true)
            ->assertJsonPath('data.farm.name', 'My Farm')
            ->assertJsonPath('data.next_action', 'none');
    }

    public function test_logout_ends_the_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        $this->postJson('/api/v1/auth/logout')->assertOk()->assertJsonPath('message', 'Logged out.');

        $this->assertGuest('web');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }

    public function test_suspended_session_is_rejected_by_protected_routes(): void
    {
        $user = User::factory()->suspended()->create();

        $this->actingAs($user, 'web')->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'account_suspended');
    }

    public function test_csrf_cookie_endpoint_is_available(): void
    {
        $this->get('/api/v1/auth/csrf-cookie')->assertNoContent();
    }
}
