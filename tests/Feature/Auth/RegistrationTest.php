<?php

namespace Tests\Feature\Auth;

use App\Enums\OtpPurpose;
use App\Models\OneTimeCode;
use App\Models\User;
use App\Notifications\OtpNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class RegistrationTest extends AuthTestCase
{
    public function test_registration_creates_unverified_user_and_issues_otp(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->registerPayload(['email' => '  Farmer@Example.COM ']));

        $response->assertCreated()
            ->assertJsonPath('data.authenticated', true)
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonPath('data.onboarded', false)
            ->assertJsonPath('data.next_action', 'verify_email')
            ->assertJsonPath('data.farm', null)
            ->assertJsonPath('data.user.email', 'farmer@example.com');

        $user = User::firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->onboarded_at);
        $this->assertTrue(Str::isUuid($user->id, 7));
        $this->assertSame($user->id, $response->json('data.user.id'));
        $this->assertAuthenticatedAs($user, 'web');

        Notification::assertSentTo($user, OtpNotification::class, fn ($n) => $n->purpose === OtpPurpose::EmailVerification && preg_match('/^\d{6}$/', $n->code));
    }

    public function test_password_is_hashed_and_otp_is_not_stored_in_plaintext(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();

        $user = User::firstOrFail();
        $this->assertNotSame('Password123', $user->password);
        $this->assertTrue(Hash::check('Password123', $user->password));

        $code = $this->lastCode($user, OtpPurpose::EmailVerification);
        $record = OneTimeCode::firstOrFail();
        $this->assertNotSame($code, $record->secret_hash);
        $this->assertStringNotContainsString($code, json_encode($record->getAttributes()));
        $this->assertSame(0, $record->attempts);
    }

    public function test_duplicate_email_is_rejected_case_insensitively(): void
    {
        User::factory()->create(['email' => 'farmer@example.com']);

        $this->postJson('/api/v1/auth/register', $this->registerPayload(['email' => 'FARMER@example.com']))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors('email');

        $this->assertSame(1, User::count());
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload(['email' => 'not-an-email']))
            ->assertJsonValidationErrors('email');

        $this->postJson('/api/v1/auth/register', $this->registerPayload(['password_confirmation' => 'Different123']))
            ->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/auth/register', $this->registerPayload(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/auth/register', $this->registerPayload(['password' => 'onlyletterspassword', 'password_confirmation' => 'onlyletterspassword']))
            ->assertCreated(); // no letter/number composition rules; minimum is 6 characters
        $this->assertSame(1, User::count());
        User::query()->delete();

        $this->postJson('/api/v1/auth/register', [])->assertJsonValidationErrors(['email', 'password']);
        $this->assertSame(0, User::count());
    }

    public function test_client_cannot_mass_assign_verification_or_onboarding_state(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload([
            'email_verified_at' => now()->toDateTimeString(),
            'onboarded_at' => now()->toDateTimeString(),
            'id' => 'attacker-chosen-id',
        ]))->assertCreated();

        $user = User::firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->onboarded_at);
        $this->assertNotSame('attacker-chosen-id', $user->id);
    }

    public function test_registration_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/register', $this->registerPayload(['email' => "user{$i}@example.com"]))->assertCreated();
        }

        $this->postJson('/api/v1/auth/register', $this->registerPayload(['email' => 'one-more@example.com']))
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests');
    }

    public function test_request_without_first_party_origin_cannot_start_a_session(): void
    {
        $this->withoutHeader('Origin')->postJson('/api/v1/auth/register', $this->registerPayload())
            ->assertStatus(400)
            ->assertJsonPath('code', 'stateful_request_required');

        $this->assertSame(0, User::count());
    }
}
