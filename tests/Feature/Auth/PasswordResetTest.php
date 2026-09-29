<?php

namespace Tests\Feature\Auth;

use App\Enums\OtpPurpose;
use App\Models\OneTimeCode;
use App\Models\User;
use App\Notifications\OtpNotification;
use App\Services\Auth\OtpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

class PasswordResetTest extends AuthTestCase
{
    private function forgot(string $email)
    {
        return $this->postJson('/api/v1/auth/password/forgot', ['email' => $email]);
    }

    private function verifyOtp(string $email, string $code)
    {
        return $this->postJson('/api/v1/auth/password/verify-otp', ['email' => $email, 'code' => $code]);
    }

    private function reset(string $email, string $token, string $password = 'NewPassword456', ?string $confirmation = null)
    {
        return $this->postJson('/api/v1/auth/password/reset', [
            'email' => $email,
            'reset_token' => $token,
            'password' => $password,
            'password_confirmation' => $confirmation ?? $password,
        ]);
    }

    /** @return array{0: User, 1: string} user and reset token */
    private function tokenFor(string $email = 'a@example.com'): array
    {
        $user = User::factory()->create(['email' => $email]);
        $this->forgot($email)->assertOk();
        $token = $this->verifyOtp($email, $this->lastCode($user, OtpPurpose::PasswordReset))->assertOk()->json('data.reset_token');

        return [$user, $token];
    }

    public function test_forgot_password_response_is_identical_for_known_and_unknown_emails(): void
    {
        $user = User::factory()->create(['email' => 'a@example.com']);

        $known = $this->forgot('a@example.com');
        $unknown = $this->forgot('nobody@example.com');

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json('message'), $unknown->json('message'));
        $this->assertSame($known->json('data'), $unknown->json('data'));

        Notification::assertSentToTimes($user, OtpNotification::class, 1);
        Notification::assertCount(1);
        $this->assertSame(OtpPurpose::PasswordReset, Notification::sent($user, OtpNotification::class)->first()->purpose);
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->forgot('a@example.com')->assertOk();
        }

        $this->forgot('a@example.com')->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
    }

    public function test_repeated_forgot_requests_inside_cooldown_do_not_send_more_emails(): void
    {
        $user = User::factory()->create(['email' => 'a@example.com']);

        $this->forgot('a@example.com')->assertOk();
        $this->forgot('a@example.com')->assertOk();

        Notification::assertSentToTimes($user, OtpNotification::class, 1);
    }

    public function test_correct_otp_returns_reset_token_and_stores_only_hashes(): void
    {
        [, $token] = $this->tokenFor();

        $this->assertNotEmpty($token);
        $this->assertSame(0, OneTimeCode::where('secret_hash', $token)->count());
        $this->assertSame(1, OneTimeCode::where('purpose', OtpPurpose::PasswordResetAuthorization->value)->count());
    }

    public function test_incorrect_and_unknown_email_otp_get_same_generic_error(): void
    {
        $user = User::factory()->create(['email' => 'a@example.com']);
        $this->forgot('a@example.com');
        $code = $this->lastCode($user, OtpPurpose::PasswordReset);

        $wrong = $this->verifyOtp('a@example.com', $this->wrongCode($code))->assertUnprocessable();
        $unknown = $this->verifyOtp('nobody@example.com', '123456')->assertUnprocessable();

        $this->assertSame($wrong->json('errors'), $unknown->json('errors'));
    }

    public function test_expired_otp_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'a@example.com']);
        $this->forgot('a@example.com');
        $code = $this->lastCode($user, OtpPurpose::PasswordReset);

        $this->travel((int) config('identity.otp.ttl_minutes') + 1)->minutes();

        $this->verifyOtp('a@example.com', $code)->assertUnprocessable();
    }

    public function test_otp_is_single_use_and_wrong_attempts_burn_it(): void
    {
        $user = User::factory()->create(['email' => 'a@example.com']);
        $this->forgot('a@example.com');
        $code = $this->lastCode($user, OtpPurpose::PasswordReset);

        $this->verifyOtp('a@example.com', $code)->assertOk();
        $this->verifyOtp('a@example.com', $code)->assertUnprocessable();

        $this->travel(2)->minutes();
        $this->forgot('a@example.com')->assertOk();
        $fresh = $this->lastCode($user, OtpPurpose::PasswordReset);

        for ($i = 0; $i < (int) config('identity.otp.max_attempts'); $i++) {
            $this->verifyOtp('a@example.com', $this->wrongCode($fresh))->assertUnprocessable();
        }
        $this->verifyOtp('a@example.com', $fresh)->assertUnprocessable();
    }

    public function test_email_verification_otp_cannot_be_used_for_password_reset(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'a@example.com']);
        $code = app(OtpService::class)->issueCode($user, OtpPurpose::EmailVerification);

        $this->verifyOtp('a@example.com', $code)->assertUnprocessable();
    }

    public function test_reset_authorization_cannot_be_used_as_email_otp_or_reset_with_wrong_email(): void
    {
        [, $token] = $this->tokenFor();
        User::factory()->create(['email' => 'other@example.com']);

        $this->reset('other@example.com', $token)->assertUnprocessable()->assertJsonValidationErrors('reset_token');
        $this->reset('a@example.com', 'x'.$token)->assertUnprocessable();
    }

    public function test_successful_reset_changes_password_and_old_password_stops_working(): void
    {
        [$user, $token] = $this->tokenFor();

        $this->reset('a@example.com', $token)->assertOk();

        $this->assertTrue(Hash::check('NewPassword456', $user->fresh()->password));

        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'Password123'])->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'NewPassword456'])->assertOk();
    }

    public function test_reset_authorization_is_single_use(): void
    {
        [, $token] = $this->tokenFor();

        $this->reset('a@example.com', $token)->assertOk();
        $this->reset('a@example.com', $token, 'AnotherPass789')->assertUnprocessable();
    }

    public function test_reset_authorization_expires(): void
    {
        [, $token] = $this->tokenFor();

        $this->travel((int) config('identity.reset_authorization_ttl_minutes') + 1)->minutes();

        $this->reset('a@example.com', $token)->assertUnprocessable();
    }

    public function test_reset_requires_password_confirmation_and_valid_password(): void
    {
        [, $token] = $this->tokenFor();

        $this->reset('a@example.com', $token, 'NewPassword456', 'Mismatch123')->assertJsonValidationErrors('password');
        $this->reset('a@example.com', $token, 'short')->assertJsonValidationErrors('password');

        // Validation failures must not burn the token.
        $this->reset('a@example.com', $token)->assertOk();
    }

    public function test_reset_revokes_existing_tokens_sessions_and_outstanding_codes(): void
    {
        config(['session.driver' => 'database']);
        [$user, $token] = $this->tokenFor();

        $user->createToken('mobile');
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        DB::table('sessions')->insert(['id' => 'def', 'user_id' => null, 'payload' => '', 'last_activity' => time()]);
        $this->assertSame(1, $user->tokens()->count());

        $this->reset('a@example.com', $token)->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('id', 'def')->count());
        $this->assertSame(0, OneTimeCode::whereNull('consumed_at')->whereNull('superseded_at')->count());
    }
}
