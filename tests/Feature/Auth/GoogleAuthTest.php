<?php

namespace Tests\Feature\Auth;

use App\Models\OneTimeCode;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\OtpNotification;
use App\Services\Auth\Google\GoogleAuthUnavailableException;
use App\Services\Auth\Google\GoogleIdentity;
use App\Services\Auth\Google\GoogleIdentityVerifier;
use App\Services\Auth\Google\InvalidGoogleCredentialException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class GoogleAuthTest extends AuthTestCase
{
    /** Replaces the Google verification boundary; no external calls are ever made. */
    private function fakeGoogle(GoogleIdentity|\Throwable $result): void
    {
        $this->app->instance(GoogleIdentityVerifier::class, new class($result) implements GoogleIdentityVerifier
        {
            public function __construct(private GoogleIdentity|\Throwable $result) {}

            public function verify(string $credential): GoogleIdentity
            {
                if ($this->result instanceof \Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }
        });
    }

    private function google()
    {
        return $this->postJson('/api/v1/auth/google', ['credential' => 'opaque-token-from-frontend']);
    }

    private function identity(string $sub = 'g-123', string $email = 'farmer@gmail.com', bool $verified = true): GoogleIdentity
    {
        return new GoogleIdentity($sub, $email, $verified, 'Farmer Joe');
    }

    public function test_new_google_user_is_created_verified_without_otp_and_needs_farm_setup(): void
    {
        $this->fakeGoogle($this->identity());

        $this->google()
            ->assertCreated()
            ->assertJsonPath('meta.created', true)
            ->assertJsonPath('data.email_verified', true)
            ->assertJsonPath('data.onboarded', false)
            ->assertJsonPath('data.next_action', 'complete_farm_setup')
            ->assertJsonPath('data.user.has_password', false)
            ->assertJsonPath('data.user.providers.0', 'google');

        $user = User::firstOrFail();
        $this->assertTrue(Str::isUuid($user->id, 7));
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->password);
        $this->assertAuthenticatedAs($user, 'web');

        Notification::assertNothingSent();
        $this->assertSame(0, OneTimeCode::count());
        $this->assertSame('g-123', SocialAccount::firstOrFail()->provider_user_id);
    }

    public function test_repeat_google_login_reuses_the_account(): void
    {
        $this->fakeGoogle($this->identity());
        $this->google()->assertCreated();

        $this->google()->assertOk()->assertJsonPath('meta.created', false);

        $this->assertSame(1, User::count());
        $this->assertSame(1, SocialAccount::count());
    }

    public function test_repeat_login_matches_on_subject_even_if_google_email_changed(): void
    {
        $this->fakeGoogle($this->identity('g-123', 'old@gmail.com'));
        $this->google()->assertCreated();

        $this->fakeGoogle($this->identity('g-123', 'new@gmail.com'));
        $this->google()->assertOk()->assertJsonPath('data.user.email', 'old@gmail.com');

        $this->assertSame(1, User::count());
    }

    public function test_verified_password_account_is_linked_not_duplicated_and_keeps_password(): void
    {
        $existing = User::factory()->onboarded('Existing Farm')->create(['email' => 'farmer@gmail.com']);
        $this->fakeGoogle($this->identity());

        $this->google()
            ->assertOk()
            ->assertJsonPath('meta.created', false)
            ->assertJsonPath('data.user.id', $existing->id)
            ->assertJsonPath('data.next_action', 'none')
            ->assertJsonPath('data.user.has_password', true);

        $this->assertSame(1, User::count());
        $this->assertSame($existing->id, SocialAccount::firstOrFail()->user_id);
    }

    public function test_linking_an_unverified_password_account_verifies_it_and_discards_the_unproven_password(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload(['email' => 'farmer@gmail.com']))->assertCreated();
        $squatted = User::firstOrFail();
        $this->assertSame(1, OneTimeCode::count());

        $this->fakeGoogle($this->identity());
        $this->google()->assertOk()->assertJsonPath('data.user.id', $squatted->id)->assertJsonPath('data.email_verified', true);

        $squatted->refresh();
        $this->assertNotNull($squatted->email_verified_at);
        $this->assertNull($squatted->password, 'password set by an unverified registrant must not survive');
        $this->assertSame(0, OneTimeCode::whereNull('superseded_at')->whereNull('consumed_at')->count());
        $this->assertSame(1, User::count());
    }

    public function test_invalid_credential_is_rejected(): void
    {
        $this->fakeGoogle(new InvalidGoogleCredentialException('bad'));

        $this->google()->assertUnauthorized()->assertJsonPath('code', 'invalid_google_credential');
        $this->assertSame(0, User::count());
        $this->assertGuest('web');
    }

    public function test_linking_unverified_account_revokes_registrant_sessions_and_tokens(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->unverified()->create(['email' => 'farmer@gmail.com', 'remember_token' => 'untrusted-remember-token']);
        DB::table('sessions')->insert(['id' => 'untrusted-session', 'user_id' => $user->id, 'payload' => base64_encode(''), 'last_activity' => time()]);
        $user->createToken('untrusted-device');
        $this->fakeGoogle($this->identity());
        $this->google()->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'untrusted-session']);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertNull($user->fresh()->remember_token);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_unverified_or_missing_google_email_is_rejected(): void
    {
        $this->fakeGoogle($this->identity(verified: false));
        $this->google()->assertUnprocessable()->assertJsonValidationErrors('credential');

        $this->fakeGoogle(new GoogleIdentity('g-1', null, true));
        $this->google()->assertUnprocessable();

        $this->assertSame(0, User::count());
    }

    public function test_unconfigured_google_returns_503(): void
    {
        $this->fakeGoogle(new GoogleAuthUnavailableException('no client id'));

        $this->google()->assertStatus(503)->assertJsonPath('code', 'google_unavailable');
    }

    public function test_suspended_google_user_is_blocked(): void
    {
        User::factory()->suspended()->create(['email' => 'farmer@gmail.com']);
        $this->fakeGoogle($this->identity());

        $this->google()->assertForbidden()->assertJsonPath('code', 'account_suspended');
        $this->assertGuest('web');
    }

    public function test_credential_is_required(): void
    {
        $this->postJson('/api/v1/auth/google', [])->assertJsonValidationErrors('credential');
    }

    public function test_google_user_completes_onboarding_and_never_receives_an_app_otp(): void
    {
        $this->fakeGoogle($this->identity());
        $this->google()->assertCreated();

        $this->postJson('/api/v1/onboarding/farm', ['name' => 'Joe Farms'])
            ->assertCreated()
            ->assertJsonPath('data.next_action', 'none');

        Notification::assertNotSentTo(User::firstOrFail(), OtpNotification::class);
    }
}
