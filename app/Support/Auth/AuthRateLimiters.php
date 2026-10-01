<?php

namespace App\Support\Auth;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Named limiters for security-sensitive endpoints. Numbers live in config/identity.php.
 * Exceeding a limit yields the standard 429 `too_many_requests` error envelope.
 */
class AuthRateLimiters
{
    public static function register(): void
    {
        RateLimiter::for('auth-register', fn (Request $r) => [
            self::limit('register.ip')->by('register:ip:'.$r->ip()),
        ]);

        RateLimiter::for('auth-login', fn (Request $r) => [
            self::limit('login.email_ip')->by('login:'.self::email($r).'|'.$r->ip()),
            self::limit('login.ip')->by('login:ip:'.$r->ip()),
        ]);

        RateLimiter::for('auth-otp-verify', fn (Request $r) => [
            self::limit('otp_verify.user')->by('otp-verify:'.self::actor($r)),
        ]);

        RateLimiter::for('auth-otp-resend', fn (Request $r) => [
            self::limit('otp_resend.user')->by('otp-resend:'.self::actor($r)),
        ]);

        RateLimiter::for('auth-forgot-password', fn (Request $r) => [
            self::limit('forgot_password.email_ip')->by('forgot:'.self::email($r).'|'.$r->ip()),
            self::limit('forgot_password.ip')->by('forgot:ip:'.$r->ip()),
        ]);

        RateLimiter::for('auth-reset-verify', fn (Request $r) => [
            self::limit('reset_verify.email_ip')->by('reset-verify:'.self::email($r).'|'.$r->ip()),
            self::limit('reset_verify.ip')->by('reset-verify:ip:'.$r->ip()),
        ]);

        RateLimiter::for('auth-reset-password', fn (Request $r) => [
            self::limit('reset_password.ip')->by('reset:ip:'.$r->ip()),
        ]);

        RateLimiter::for('auth-google', fn (Request $r) => [
            self::limit('google.ip')->by('google:ip:'.$r->ip()),
        ]);

        RateLimiter::for('account-password', fn (Request $r) => [
            self::limit('account_password.user')->by('account-password:'.self::actor($r)),
        ]);

        RateLimiter::for('invitation-accept', fn (Request $r) => [
            self::limit('invitation_accept.user')->by('invitation-accept:'.self::actor($r)),
        ]);

        RateLimiter::for('master-data-write', fn (Request $r) => [
            self::limit('master_data_write.user')->by('master-data-write:'.self::actor($r)),
        ]);

        RateLimiter::for('measurement-write', fn (Request $r) => [
            self::limit('measurement_write.user')->by('measurement-write:'.self::actor($r)),
        ]);

        RateLimiter::for('production-cycle-write', fn (Request $r) => [Limit::perHour(60)->by('production-cycle-write:'.self::actor($r))]);
        RateLimiter::for('record-write', fn (Request $r) => [Limit::perHour(120)->by('record-write:'.self::actor($r))]);
        RateLimiter::for('health-write', fn (Request $r) => [Limit::perHour(120)->by('health-write:'.self::actor($r))]);
        RateLimiter::for('inventory-write', fn (Request $r) => [Limit::perHour(240)->by('inventory-write:'.self::actor($r))]);

        RateLimiter::for('location-write', fn (Request $r) => [
            self::limit('location_write.user')->by('location-write:'.self::actor($r)),
        ]);

        RateLimiter::for('measurement-preview', fn (Request $r) => [
            self::limit('measurement_preview.user')->by('measurement-preview:'.self::actor($r)),
        ]);

        RateLimiter::for('team-invite', fn (Request $r) => [
            self::limit('team_invite.user')->by('team-invite:'.self::actor($r)),
        ]);
    }

    private static function limit(string $path): Limit
    {
        [$attempts, $minutes] = config('identity.rate_limits.'.$path);

        return Limit::perMinutes($minutes, $attempts);
    }

    private static function email(Request $request): string
    {
        return Str::lower(trim((string) $request->input('email')));
    }

    private static function actor(Request $request): string
    {
        return (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());
    }
}
