<?php

use App\Http\Controllers\Api\V1\Auth\CsrfCookieController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\GoogleAuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\OnboardingController;
use Illuminate\Support\Facades\Route;

/*
| Versioned API routes. Loaded by bootstrap/app.php under the /api/v1 prefix.
|
| Access tiers (middleware):
|   public                      - no auth
|   auth:sanctum + account.active           - logged in (any verification/onboarding state)
|   ... + email.verified                    - verified email (onboarding endpoint)
|   app.access (group)                      - verified AND onboarded: every farm-management endpoint
*/

Route::get('/health', HealthController::class)->name('api.v1.health');

Route::prefix('auth')->group(function () {
    Route::get('/csrf-cookie', CsrfCookieController::class)->name('api.v1.auth.csrf-cookie');

    Route::post('/register', RegisterController::class)->middleware('throttle:auth-register')->name('api.v1.auth.register');
    Route::post('/login', [SessionController::class, 'login'])->middleware('throttle:auth-login')->name('api.v1.auth.login');
    Route::post('/google', GoogleAuthController::class)->middleware('throttle:auth-google')->name('api.v1.auth.google');

    Route::prefix('password')->group(function () {
        Route::post('/forgot', [PasswordResetController::class, 'forgot'])->middleware('throttle:auth-forgot-password')->name('api.v1.auth.password.forgot');
        Route::post('/verify-otp', [PasswordResetController::class, 'verify'])->middleware('throttle:auth-reset-verify')->name('api.v1.auth.password.verify');
        Route::post('/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:auth-reset-password')->name('api.v1.auth.password.reset');
    });

    Route::middleware(['auth:sanctum', 'account.active'])->group(function () {
        Route::get('/me', [SessionController::class, 'me'])->name('api.v1.auth.me');
        Route::post('/logout', [SessionController::class, 'logout'])->name('api.v1.auth.logout');

        Route::post('/email/verify', [EmailVerificationController::class, 'verify'])->middleware('throttle:auth-otp-verify')->name('api.v1.auth.email.verify');
        Route::post('/email/resend', [EmailVerificationController::class, 'resend'])->middleware('throttle:auth-otp-resend')->name('api.v1.auth.email.resend');
    });
});

Route::middleware(['auth:sanctum', 'account.active', 'email.verified'])->group(function () {
    Route::post('/onboarding/farm', [OnboardingController::class, 'createFarm'])->name('api.v1.onboarding.farm');
});

/*
| Phase 2+ farm-management routes go inside this group; nothing is reachable until the
| user has a verified email and has completed farm setup.
*/
Route::middleware('app.access')->group(function () {
    //
});
