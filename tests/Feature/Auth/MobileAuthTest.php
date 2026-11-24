<?php

namespace Tests\Feature\Auth;

use App\Enums\OtpPurpose;
use App\Models\FarmMembership;
use App\Models\User;
use App\Notifications\OtpNotification;
use App\Services\Auth\Google\GoogleAuthUnavailableException;
use App\Services\Auth\Google\GoogleIdentity;
use App\Services\Auth\Google\GoogleIdentityVerifier;
use App\Services\Auth\Google\InvalidGoogleCredentialException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Reports\ReportsFixtures;
use Tests\Feature\Team\TeamTestCase;

/**
 * Native-app (Bearer token) authentication. Unlike the web tests there is NO Origin header, no cookie and no CSRF token: every request
 * is exactly what a mobile client sends.
 */
class MobileAuthTest extends TeamTestCase
{
    use ReportsFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->flushHeaders(); // a native client sends no Origin: the request is not "stateful"
    }

    private function login(string $device = 'Pixel 8', ?string $email = null, string $password = 'Password123')
    {
        $this->app['auth']->forgetGuards();

        return $this->withoutToken()->postJson('/api/v1/auth/mobile/login', ['email' => $email ?? $this->owner->email, 'password' => $password, 'device_name' => $device]);
    }

    /** Signs in and returns the plaintext token. */
    private function token(string $device = 'Pixel 8', ?User $user = null): string
    {
        return $this->login($device, ($user ?? $this->owner)->email)->assertOk()->json('data.token');
    }

    /** All following requests carry this token (guards cache the user between in-process requests, so reset them). */
    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

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

    private function google(string $device = 'iPhone 15')
    {
        $this->app['auth']->forgetGuards();

        return $this->withoutToken()->postJson('/api/v1/auth/mobile/google', ['credential' => 'opaque', 'device_name' => $device]);
    }

    // ------------------------------------------------------------------ login

    public function test_login_returns_a_named_expiring_token_and_the_auth_state_without_any_cookie(): void
    {
        $response = $this->login('Pixel 8')->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.authenticated', true)
            ->assertJsonPath('data.next_action', 'none')
            ->assertJsonPath('data.user.email', $this->owner->email)
            ->assertJsonPath('data.farm.name', 'Green Acres');

        $this->assertEmpty($response->headers->getCookies(), 'no session or XSRF cookie is set');

        $token = PersonalAccessToken::findToken($response->json('data.token'));
        $this->assertSame('Pixel 8', $token->name);
        $this->assertTrue($token->tokenable->is($this->owner));
        $this->assertEquals(now()->addDays(90)->timestamp, $token->expires_at->timestamp, '', 5);
        $this->assertSame($token->expires_at->toIso8601String(), $response->json('data.expires_at'));
    }

    public function test_token_lifetime_is_configurable(): void
    {
        config(['identity.mobile.token_ttl_days' => 7]);

        $this->assertEquals(now()->addDays(7)->timestamp, PersonalAccessToken::findToken($this->token())->expires_at->timestamp, '', 5);
    }

    public function test_wrong_credentials_and_unknown_email_are_refused_identically_and_issue_no_token(): void
    {
        $wrong = $this->login(password: 'WrongPass1')->assertStatus(401)->assertJsonPath('code', 'invalid_credentials')->json();
        $unknown = $this->login(email: 'nobody@example.com')->assertStatus(401)->json();

        $this->assertSame($wrong['message'], $unknown['message']);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_suspended_and_passwordless_accounts_cannot_get_a_token(): void
    {
        $suspended = User::factory()->suspended()->create(['email' => 's@example.com']);
        $this->login(email: $suspended->email)->assertForbidden()->assertJsonPath('code', 'account_suspended');

        User::factory()->create(['email' => 'g@example.com', 'password' => null]);
        $this->login(email: 'g@example.com')->assertStatus(401);

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_device_name_is_required_and_limited_to_100_characters(): void
    {
        $this->postJson('/api/v1/auth/mobile/login', ['email' => $this->owner->email, 'password' => 'Password123'])
            ->assertStatus(422)->assertJsonValidationErrors('device_name');
        $this->login(str_repeat('x', 101))->assertStatus(422)->assertJsonValidationErrors('device_name');
        $this->login(str_repeat('x', 100))->assertOk();
    }

    // ------------------------------------------------------------------ register

    public function test_register_creates_an_unverified_account_sends_the_otp_and_the_token_can_verify_it(): void
    {
        $response = $this->postJson('/api/v1/auth/mobile/register', [
            'email' => 'new@example.com', 'password' => 'Password123', 'password_confirmation' => 'Password123', 'device_name' => 'iPhone 15',
        ])->assertCreated()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.next_action', 'verify_email')
            ->assertJsonPath('data.email_verified', false);

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $code = $this->lastCode($user, OtpPurpose::EmailVerification);
        $this->assertNotNull($code, 'the verification OTP was sent exactly as in the web flow');
        $this->assertSame('iPhone 15', PersonalAccessToken::findToken($response->json('data.token'))->name);

        // Unverified: restricted routes are refused, verification itself works with the token.
        $this->as($response->json('data.token'))->getJson('/api/v1/farm')->assertForbidden();
        $this->postJson('/api/v1/auth/email/verify', ['code' => $this->wrongCode($code)])->assertStatus(422);
        $this->postJson('/api/v1/auth/email/verify', ['code' => $code])->assertOk()->assertJsonPath('data.next_action', 'complete_farm_setup');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_register_rejects_duplicates_and_bad_input_without_creating_a_token_or_sending_an_otp(): void
    {
        $payload = ['email' => $this->owner->email, 'password' => 'Password123', 'password_confirmation' => 'Password123', 'device_name' => 'Pixel'];

        $this->postJson('/api/v1/auth/mobile/register', $payload)->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/auth/mobile/register', ['email' => 'x@example.com', 'password' => 'Password123', 'password_confirmation' => 'Different1', 'device_name' => 'Pixel'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/auth/mobile/register', ['email' => 'x@example.com', 'password' => 'Password123', 'password_confirmation' => 'Password123'])
            ->assertStatus(422)->assertJsonValidationErrors('device_name');

        $this->assertSame(0, PersonalAccessToken::count());
        Notification::assertNothingSent();
        $this->assertNull(User::where('email', 'x@example.com')->first());
    }

    // ------------------------------------------------------------------ Google

    public function test_google_creates_links_and_signs_in_with_a_token(): void
    {
        $this->fakeGoogle(new GoogleIdentity('g-1', 'newfarmer@gmail.com', true, 'New Farmer'));
        $created = $this->google()->assertCreated()
            ->assertJsonPath('meta.created', true)
            ->assertJsonPath('data.next_action', 'complete_farm_setup')
            ->assertJsonPath('data.user.providers.0', 'google')
            ->assertJsonPath('data.token_type', 'Bearer');
        $this->assertSame('iPhone 15', PersonalAccessToken::findToken($created->json('data.token'))->name);
        Notification::assertNotSentTo(User::where('email', 'newfarmer@gmail.com')->first(), OtpNotification::class);

        // Repeat sign-in: 200, same account, one more token.
        $this->google('iPad')->assertOk()->assertJsonPath('meta.created', false);
        $this->assertSame(1, User::where('email', 'newfarmer@gmail.com')->count());
        $this->assertSame(2, PersonalAccessToken::count());

        // An existing email/password account with the same verified email is linked, not duplicated.
        $this->fakeGoogle(new GoogleIdentity('g-2', $this->owner->email, true, 'Olu'));
        $this->google()->assertOk()->assertJsonPath('data.user.id', $this->owner->id);
        $this->assertSame(1, User::where('email', $this->owner->email)->count());
    }

    public function test_google_failures_issue_no_token(): void
    {
        $this->fakeGoogle(new InvalidGoogleCredentialException('bad'));
        $this->google()->assertStatus(401)->assertJsonPath('code', 'invalid_google_credential');

        $this->fakeGoogle(new GoogleAuthUnavailableException('no keys'));
        $this->google()->assertStatus(503)->assertJsonPath('code', 'google_unavailable');

        $this->fakeGoogle(new GoogleIdentity('g-3', 'unverified@gmail.com', false, null));
        $this->google()->assertStatus(422);

        $suspended = User::factory()->suspended()->create(['email' => 'susp@gmail.com']);
        $this->fakeGoogle(new GoogleIdentity('g-4', $suspended->email, true, null));
        $this->google()->assertForbidden()->assertJsonPath('code', 'account_suspended');

        $this->withoutToken()->postJson('/api/v1/auth/mobile/google', ['credential' => 'opaque'])->assertStatus(422)->assertJsonValidationErrors('device_name');

        $this->assertSame(0, PersonalAccessToken::count());
    }

    // ------------------------------------------------------------------ protected routes with a Bearer token

    public function test_bearer_token_reaches_farm_records_reports_invoice_pdf_and_export_download_without_cookies(): void
    {
        Storage::fake('local');
        $this->onPlan('farm-business');
        $this->bootClock();
        $this->as($this->token());

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.email', $this->owner->email);
        $this->getJson('/api/v1/farm')->assertOk()->assertJsonPath('data.id', $this->farm->id);

        // Everything below is set up and read with the token only.
        $cycle = $this->layers(100);
        $this->mortality($cycle, 4);
        $this->getJson('/api/v1/records')->assertOk()->assertJsonPath('data.0.type', 'mortality');
        $this->getJson('/api/v1/reports/mortality')->assertOk();

        [, $invoice] = $this->invoicedSale('4500.75');
        $pdf = $this->get('/api/v1/invoices/'.$invoice['id'].'/pdf')->assertOk();
        $pdf->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());

        $export = $this->postJson('/api/v1/reports/exports', ['report' => 'mortality', 'format' => 'csv', 'filters' => (object) [], 'idempotency_key' => $this->key()])->assertStatus(202)->json('data');
        $done = $this->getJson('/api/v1/reports/exports/'.$export['id'])->assertOk()->json('data');
        $this->assertSame('completed', $done['status']);
        $download = $this->get($done['download_path'])->assertOk();
        $this->assertStringContainsString('text/csv', $download->headers->get('Content-Type'));
        $this->assertStringContainsString('Disease', $download->streamedContent());
    }

    public function test_requests_without_or_with_a_bad_token_are_unauthenticated(): void
    {
        $this->getJson('/api/v1/farm')->assertUnauthorized();
        $this->as('999|not-a-real-token')->getJson('/api/v1/farm')->assertUnauthorized();
        $this->as('garbage')->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_x_farm_id_selects_another_farm_with_a_token_and_foreign_farms_are_forbidden(): void
    {
        [, $other] = $this->otherFarm();
        FarmMembership::create(['farm_id' => $other->id, 'user_id' => $this->owner->id, 'role' => FarmMembership::ROLE_OWNER]);
        [, $stranger] = $this->otherFarm();

        $this->as($this->token());
        $this->getJson('/api/v1/farm')->assertOk()->assertJsonPath('data.id', $this->farm->id);
        $this->withHeader('X-Farm-Id', $other->id)->getJson('/api/v1/farm')->assertOk()->assertJsonPath('data.id', $other->id);
        $this->withHeader('X-Farm-Id', $stranger->id)->getJson('/api/v1/farm')->assertForbidden()->assertJsonPath('code', 'farm_access_denied');
    }

    public function test_an_expired_token_is_unauthenticated_and_a_fresh_login_works_again(): void
    {
        $token = $this->token();
        $this->as($token)->getJson('/api/v1/farm')->assertOk();

        $this->travel(89)->days();
        $this->as($token)->getJson('/api/v1/farm')->assertOk();

        $this->travel(2)->days();
        $this->as($token)->getJson('/api/v1/farm')->assertUnauthorized();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/auth/devices')->assertUnauthorized();

        $this->as($this->token('Pixel 8 (again)'))->getJson('/api/v1/farm')->assertOk();
    }

    public function test_suspending_the_account_cuts_off_its_tokens(): void
    {
        $token = $this->token();
        $this->owner->forceFill(['suspended_at' => now()])->save();

        $this->as($token)->getJson('/api/v1/farm')->assertForbidden()->assertJsonPath('code', 'account_suspended');
    }

    // ------------------------------------------------------------------ logout, devices

    public function test_logout_revokes_only_the_current_token(): void
    {
        $phone = $this->token('Phone');
        $tablet = $this->token('Tablet');

        $this->as($phone)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame(['Tablet'], $this->owner->tokens()->pluck('name')->all());

        $this->as($phone)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->as($tablet)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_devices_lists_own_live_tokens_and_flags_the_current_one(): void
    {
        $phone = $this->token('Phone');
        $tablet = $this->token('Tablet');
        $this->travel(1)->hours();
        $this->as($tablet)->getJson('/api/v1/auth/me')->assertOk(); // stamps last_used_at

        $stranger = User::factory()->create();
        $this->token('Strangers phone', $stranger);

        $devices = $this->as($tablet)->getJson('/api/v1/auth/devices')->assertOk()->json('data');
        $this->assertCount(2, $devices);
        $byName = collect($devices)->keyBy('device_name');
        $this->assertTrue($byName['Tablet']['is_current']);
        $this->assertFalse($byName['Phone']['is_current']);
        $this->assertNotNull($byName['Tablet']['last_used_at']);
        $this->assertNotNull($byName['Phone']['created_at']);
        $this->assertSame(['id', 'device_name', 'created_at', 'last_used_at', 'expires_at', 'is_current'], array_keys($devices[0]));
        $this->assertArrayNotHasKey('token', $devices[0]);

        $this->as($phone)->getJson('/api/v1/auth/devices')->assertOk();

        // Expired tokens are not devices any more.
        $this->travel(91)->days();
        $fresh = $this->token('Fresh');
        $this->assertSame(['Fresh'], collect($this->as($fresh)->getJson('/api/v1/auth/devices')->assertOk()->json('data'))->pluck('device_name')->all());
    }

    public function test_a_device_can_be_revoked_but_only_by_its_owner(): void
    {
        $phone = $this->token('Phone');
        $tablet = $this->token('Tablet');
        $phoneId = PersonalAccessToken::findToken($phone)->id;
        $tabletId = PersonalAccessToken::findToken($tablet)->id;

        $stranger = User::factory()->create();
        $strangerToken = $this->token('Other', $stranger);
        $strangerId = PersonalAccessToken::findToken($strangerToken)->id;

        $this->as($tablet)->deleteJson('/api/v1/auth/devices/'.$strangerId)->assertNotFound();
        $this->assertNotNull(PersonalAccessToken::find($strangerId));
        $this->deleteJson('/api/v1/auth/devices/999999')->assertNotFound();

        $this->deleteJson('/api/v1/auth/devices/'.$phoneId)->assertOk();
        $this->as($phone)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->as($tablet)->deleteJson('/api/v1/auth/devices/'.$tabletId)->assertOk(); // revoking yourself signs this device out
        $this->as($tablet)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->as($strangerToken)->getJson('/api/v1/auth/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withoutToken()->deleteJson('/api/v1/auth/devices/'.$phoneId)->assertUnauthorized();
    }

    public function test_logout_all_revokes_every_token_and_browser_session_of_the_account_only(): void
    {
        $phone = $this->token('Phone');
        $tablet = $this->token('Tablet');
        $stranger = User::factory()->create();
        $strangerToken = $this->token('Other', $stranger);

        config(['session.driver' => 'database']);
        \DB::table('sessions')->insert([
            ['id' => 'web-session-1', 'user_id' => $this->owner->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'web-session-2', 'user_id' => $stranger->id, 'payload' => '', 'last_activity' => time()],
        ]);

        $this->as($phone)->postJson('/api/v1/auth/logout-all')->assertOk();

        $this->assertSame(0, $this->owner->tokens()->count());
        $this->assertSame(['web-session-2'], \DB::table('sessions')->pluck('id')->all());
        $this->as($phone)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->as($tablet)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->as($strangerToken)->getJson('/api/v1/auth/me')->assertOk();
    }

    // ------------------------------------------------------------------ password change

    public function test_password_change_with_a_token_keeps_that_token_and_revokes_the_others(): void
    {
        $phone = $this->token('Phone');
        $tablet = $this->token('Tablet');

        config(['session.driver' => 'database']);
        \DB::table('sessions')->insert(['id' => 'web-session-1', 'user_id' => $this->owner->id, 'payload' => '', 'last_activity' => time()]);

        $this->as($phone)->putJson('/api/v1/account/password', [
            'current_password' => 'Password123', 'password' => 'NewPassw0rd', 'password_confirmation' => 'NewPassw0rd',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewPassw0rd', $this->owner->fresh()->password));
        $this->assertSame(['Phone'], $this->owner->tokens()->pluck('name')->all());
        $this->assertSame(0, \DB::table('sessions')->where('user_id', $this->owner->id)->count(), 'browser sessions end too');
        $this->as($phone)->getJson('/api/v1/auth/me')->assertOk();
        $this->as($tablet)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_web_password_change_still_revokes_every_token(): void
    {
        $this->token('Phone');

        $this->flushHeaders()->withHeader('Origin', 'http://localhost:3000');
        $this->signInAs($this->owner)->putJson('/api/v1/account/password', [
            'current_password' => 'Password123', 'password' => 'NewPassw0rd', 'password_confirmation' => 'NewPassw0rd',
        ])->assertOk();

        $this->assertSame(0, $this->owner->tokens()->count());
    }

    // ------------------------------------------------------------------ web is untouched

    public function test_web_login_still_requires_a_first_party_origin_and_never_returns_a_token(): void
    {
        $payload = ['email' => $this->owner->email, 'password' => 'Password123'];

        $this->postJson('/api/v1/auth/register', ['email' => 'w@example.com', 'password' => 'Password123', 'password_confirmation' => 'Password123'])
            ->assertStatus(400)->assertJsonPath('code', 'stateful_request_required');
        $this->assertNull(User::where('email', 'w@example.com')->first());

        $this->withHeader('Origin', 'http://localhost:3000')->postJson('/api/v1/auth/login', $payload)
            ->assertOk()->assertJsonMissingPath('data.token');
        $this->assertSame(0, PersonalAccessToken::count());
    }
}
