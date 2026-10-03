<?php

namespace App\Providers;

use App\Services\Audit\AuditSubscriber;
use App\Services\Auth\Google\GoogleIdentityVerifier;
use App\Services\Auth\Google\JwtGoogleIdentityVerifier;
use App\Services\Notifications\EmailDeliveryTracker;
use App\Support\Auth\AuthRateLimiters;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GoogleIdentityVerifier::class, JwtGoogleIdentityVerifier::class);
    }

    public function boot(): void
    {
        AuthRateLimiters::register();

        // Phase 17: audit entries for the Access events; delivery state of notification emails.
        Event::subscribe(AuditSubscriber::class);
        Event::listen(NotificationSent::class, [EmailDeliveryTracker::class, 'sent']);
        Event::listen(NotificationFailed::class, [EmailDeliveryTracker::class, 'failed']);

        // First-party SPA authentication is the Sanctum session cookie. Public endpoints opt out
        // with the @unauthenticated tag on the controller method.
        Scramble::afterOpenApiGenerated(function (OpenApi $openApi) {
            $openApi->secure(
                SecurityScheme::apiKey('cookie', config('session.cookie'))
                    ->setDescription('Sanctum session cookie set by /auth/login, /auth/register or /auth/google. Send requests with credentials and, for POST/PUT/PATCH/DELETE, the X-XSRF-TOKEN header (see docs/api/README.md).')
            );
        });
    }
}
