<?php

namespace Tests\Feature\Auth;

use App\Enums\OtpPurpose;
use App\Models\User;
use App\Notifications\OtpNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Base for auth feature tests: every request comes from the first-party frontend origin
 * (so Sanctum treats it as stateful) and OTP emails are captured instead of sent.
 */
abstract class AuthTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', 'http://localhost:3000');
        Notification::fake();
    }

    /** Switch the acting user mid-test (each real request is a fresh process; here guards cache users). */
    protected function signInAs(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web');
    }

    protected function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'farmer@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ], $overrides);
    }

    /** The plaintext code of the most recent OTP email of this purpose sent to the user. */
    protected function lastCode(User $user, OtpPurpose $purpose): ?string
    {
        return Notification::sent($user, OtpNotification::class)
            ->filter(fn (OtpNotification $n) => $n->purpose === $purpose)
            ->last()?->code;
    }

    protected function wrongCode(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }
}
