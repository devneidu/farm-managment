<?php

namespace Tests\Feature\Auth;

use App\Enums\OtpPurpose;
use App\Models\User;
use App\Notifications\OtpNotification;
use App\Services\Auth\OtpService;
use Illuminate\Support\Facades\Notification;

class EmailVerificationTest extends AuthTestCase
{
    private function registered(): User
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();

        return User::firstOrFail();
    }

    public function test_correct_code_verifies_email_and_points_to_farm_setup(): void
    {
        $user = $this->registered();
        $code = $this->lastCode($user, OtpPurpose::EmailVerification);

        $this->postJson('/api/v1/auth/email/verify', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.email_verified', true)
            ->assertJsonPath('data.onboarded', false)
            ->assertJsonPath('data.next_action', 'complete_farm_setup');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_incorrect_code_is_rejected_with_uniform_error(): void
    {
        $user = $this->registered();
        $code = $this->lastCode($user, OtpPurpose::EmailVerification);

        $this->postJson('/api/v1/auth/email/verify', ['code' => $this->wrongCode($code)])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'The code is invalid or has expired.');

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_malformed_code_fails_validation(): void
    {
        $this->registered();

        $this->postJson('/api/v1/auth/email/verify', ['code' => '12ab'])->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/auth/email/verify', [])->assertJsonValidationErrors('code');
    }

    public function test_expired_code_is_rejected(): void
    {
        $user = $this->registered();
        $code = $this->lastCode($user, OtpPurpose::EmailVerification);

        $this->travel((int) config('identity.otp.ttl_minutes') + 1)->minutes();

        $this->postJson('/api/v1/auth/email/verify', ['code' => $code])->assertUnprocessable();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_code_is_single_use(): void
    {
        $user = $this->registered();
        $code = $this->lastCode($user, OtpPurpose::EmailVerification);

        $this->postJson('/api/v1/auth/email/verify', ['code' => $code])->assertOk();

        // Force the account back to unverified: the consumed code must still not work.
        User::whereKey($user->id)->update(['email_verified_at' => null]);
        $this->signInAs($user->fresh());

        $this->postJson('/api/v1/auth/email/verify', ['code' => $code])->assertUnprocessable();
    }

    public function test_already_verified_account_gets_conflict(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        $this->postJson('/api/v1/auth/email/verify', ['code' => '123456'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_verified');
    }

    public function test_password_reset_code_cannot_verify_email(): void
    {
        $user = $this->registered();
        $resetCode = app(OtpService::class)->issueCode($user, OtpPurpose::PasswordReset);

        $this->postJson('/api/v1/auth/email/verify', ['code' => $resetCode])->assertUnprocessable();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_code_is_burned_after_too_many_wrong_attempts(): void
    {
        $user = $this->registered();
        $code = $this->lastCode($user, OtpPurpose::EmailVerification);

        for ($i = 0; $i < (int) config('identity.otp.max_attempts'); $i++) {
            $this->postJson('/api/v1/auth/email/verify', ['code' => $this->wrongCode($code)])->assertUnprocessable();
        }

        // Even the correct code no longer works; a new one must be requested.
        $this->postJson('/api/v1/auth/email/verify', ['code' => $code])->assertUnprocessable();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_verify_attempts_are_rate_limited(): void
    {
        $this->registered();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/email/verify', ['code' => '000000']);
        }

        $this->postJson('/api/v1/auth/email/verify', ['code' => '000000'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests');
    }

    public function test_resend_respects_cooldown_and_replacement_invalidates_previous_code(): void
    {
        $user = $this->registered();
        $first = $this->lastCode($user, OtpPurpose::EmailVerification);

        $this->postJson('/api/v1/auth/email/resend')
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests')
            ->assertHeader('Retry-After');

        $this->travel((int) config('identity.otp.resend_cooldown_seconds') + 1)->seconds();

        $this->postJson('/api/v1/auth/email/resend')->assertOk();
        Notification::assertSentToTimes($user, OtpNotification::class, 2);

        $second = $this->lastCode($user, OtpPurpose::EmailVerification);

        if ($first !== $second) {
            $this->postJson('/api/v1/auth/email/verify', ['code' => $first])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/email/verify', ['code' => $second])->assertOk();
    }

    public function test_replaced_code_is_invalid_even_when_still_unexpired(): void
    {
        $user = $this->registered();
        $otps = app(OtpService::class);

        $old = $otps->issueCode($user, OtpPurpose::EmailVerification);
        $new = $otps->issueCode($user, OtpPurpose::EmailVerification);
        $this->assertNotSame($old, $new, 'astronomically unlikely collision; rerun');

        $this->postJson('/api/v1/auth/email/verify', ['code' => $old])->assertUnprocessable();
        $this->postJson('/api/v1/auth/email/verify', ['code' => $new])->assertOk();
    }

    public function test_verification_endpoints_require_authentication(): void
    {
        $this->postJson('/api/v1/auth/email/verify', ['code' => '123456'])->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->postJson('/api/v1/auth/email/resend')->assertUnauthorized();
    }
}
