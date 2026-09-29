<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\Auth\CsrfCookieController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\GoogleAuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\CustomBreedController;
use App\Http\Controllers\Api\V1\CustomVarietyController;
use App\Http\Controllers\Api\V1\FarmController;
use App\Http\Controllers\Api\V1\FarmInvitationController;
use App\Http\Controllers\Api\V1\FarmOperationController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InvitationAcceptanceController;
use App\Http\Controllers\Api\V1\MasterDataController;
use App\Http\Controllers\Api\V1\MeasurementCatalogueController;
use App\Http\Controllers\Api\V1\MeasurementContextController;
use App\Http\Controllers\Api\V1\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\PackageConversionController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\QuantityNormalizationController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\TeamMemberController;
use App\Http\Controllers\Api\V1\UnitPreferenceController;
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

Route::get('/public/plans', [PlanController::class, 'index'])->middleware('throttle:60,1')->name('api.v1.public.plans');

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

    // Joining a farm by invitation does not need farm setup (the user may not have a farm yet).
    Route::post('/invitations/accept', InvitationAcceptanceController::class)->middleware('throttle:invitation-accept')->name('api.v1.invitations.accept');

    // My Account (user-level, not farm-scoped)
    Route::prefix('account')->group(function () {
        Route::get('/', [AccountController::class, 'show'])->name('api.v1.account.show');
        Route::patch('/', [AccountController::class, 'update'])->name('api.v1.account.update');
        Route::put('/password', [AccountController::class, 'password'])->middleware('throttle:account-password')->name('api.v1.account.password');
    });
});

/*
| Phase 2+ farm-management routes go inside this group; nothing is reachable until the
| user has a verified email and has completed farm setup.
*/
Route::middleware(['app.access', 'farm.context'])->group(function () {
    // Every route here acts on the caller's ACTIVE farm membership (see ResolveFarmContext) and declares
    // the permission it needs (see App\Enums\Permission / FarmRole::permissions()).
    Route::get('/farm', [FarmController::class, 'show'])->middleware('farm.permission:farm.view')->name('api.v1.farm.show');
    Route::patch('/farm', [FarmController::class, 'update'])->middleware('farm.permission:farm.update')->name('api.v1.farm.update');

    Route::get('/roles', [RoleController::class, 'index'])->middleware('farm.permission:team.view')->name('api.v1.roles.index');

    Route::prefix('farm/members')->group(function () {
        Route::get('/', [TeamMemberController::class, 'index'])->middleware('farm.permission:team.view')->name('api.v1.farm.members.index');
        Route::get('/{membership}', [TeamMemberController::class, 'show'])->middleware('farm.permission:team.view')->name('api.v1.farm.members.show');
        Route::patch('/{membership}', [TeamMemberController::class, 'update'])->middleware('farm.permission:team.update_role')->name('api.v1.farm.members.update');
        Route::delete('/{membership}', [TeamMemberController::class, 'destroy'])->middleware('farm.permission:team.remove')->name('api.v1.farm.members.destroy');
    });

    Route::prefix('farm/invitations')->group(function () {
        Route::get('/', [FarmInvitationController::class, 'index'])->middleware('farm.permission:team.view')->name('api.v1.farm.invitations.index');
        Route::post('/', [FarmInvitationController::class, 'store'])->middleware(['farm.permission:team.invite', 'throttle:team-invite'])->name('api.v1.farm.invitations.store');
        Route::post('/{invitation}/resend', [FarmInvitationController::class, 'resend'])->middleware(['farm.permission:team.invite', 'throttle:team-invite'])->name('api.v1.farm.invitations.resend');
        Route::delete('/{invitation}', [FarmInvitationController::class, 'destroy'])->middleware('farm.permission:team.invite')->name('api.v1.farm.invitations.destroy');
    });

    // Subscription (RBAC decides who may see/manage billing; entitlements decide what the plan allows)
    Route::prefix('subscription')->group(function () {
        Route::get('/', [SubscriptionController::class, 'show'])->middleware('farm.permission:subscription.view')->name('api.v1.subscription.show');
        Route::get('/entitlements', [SubscriptionController::class, 'entitlements'])->middleware('farm.permission:farm.view')->name('api.v1.subscription.entitlements');
        Route::get('/usage', [SubscriptionController::class, 'usage'])->middleware('farm.permission:subscription.view')->name('api.v1.subscription.usage');
        Route::post('/cancel', [SubscriptionController::class, 'cancel'])->middleware('farm.permission:subscription.manage')->name('api.v1.subscription.cancel');
        Route::post('/resume', [SubscriptionController::class, 'resume'])->middleware('farm.permission:subscription.manage')->name('api.v1.subscription.resume');
    });

    // Agricultural master data (Phase 4). Not plan-gated. Reads: master_data.view (every role);
    // farm custom breeds/varieties: master_data.manage; the optional farm operation selection: farm.view / farm.update.
    Route::prefix('master')->middleware('farm.permission:master_data.view')->group(function () {
        Route::get('/farm-operations', [MasterDataController::class, 'operations'])->name('api.v1.master.farm-operations');
        Route::get('/species', [MasterDataController::class, 'species'])->name('api.v1.master.species');
        Route::get('/species/{species}/capabilities', [MasterDataController::class, 'capabilities'])->name('api.v1.master.species.capabilities');
        Route::get('/species/{species}/breeds', [MasterDataController::class, 'breeds'])->name('api.v1.master.species.breeds');
        Route::get('/crops', [MasterDataController::class, 'crops'])->name('api.v1.master.crops');
        Route::get('/crops/{crop}/varieties', [MasterDataController::class, 'varieties'])->name('api.v1.master.crops.varieties');
        Route::get('/planting-reference', [MasterDataController::class, 'plantingReference'])->name('api.v1.master.planting-reference');
    });

    Route::get('/custom-breeds', [CustomBreedController::class, 'index'])->middleware('farm.permission:master_data.view')->name('api.v1.custom-breeds.index');
    Route::post('/custom-breeds', [CustomBreedController::class, 'store'])->middleware(['farm.permission:master_data.manage', 'throttle:master-data-write'])->name('api.v1.custom-breeds.store');
    Route::patch('/custom-breeds/{breed}', [CustomBreedController::class, 'update'])->middleware(['farm.permission:master_data.manage', 'throttle:master-data-write'])->name('api.v1.custom-breeds.update');

    Route::get('/custom-varieties', [CustomVarietyController::class, 'index'])->middleware('farm.permission:master_data.view')->name('api.v1.custom-varieties.index');
    Route::post('/custom-varieties', [CustomVarietyController::class, 'store'])->middleware(['farm.permission:master_data.manage', 'throttle:master-data-write'])->name('api.v1.custom-varieties.store');
    Route::patch('/custom-varieties/{variety}', [CustomVarietyController::class, 'update'])->middleware(['farm.permission:master_data.manage', 'throttle:master-data-write'])->name('api.v1.custom-varieties.update');

    Route::get('/farm/operations', [FarmOperationController::class, 'show'])->middleware('farm.permission:farm.view')->name('api.v1.farm.operations.show');
    Route::put('/farm/operations', [FarmOperationController::class, 'update'])->middleware('farm.permission:farm.update')->name('api.v1.farm.operations.update');

    // Measurements (Phase 5). Not plan-gated. Reads: measurement.view (every role); farm unit preferences and package
    // conversions are written with measurement.manage. Units are only ever listed per dimension.
    Route::get('/master/measurement-dimensions', [MeasurementCatalogueController::class, 'dimensions'])->middleware('farm.permission:measurement.view')->name('api.v1.master.measurement-dimensions');
    Route::get('/master/units', [MeasurementCatalogueController::class, 'units'])->middleware('farm.permission:measurement.view')->name('api.v1.master.units');

    Route::get('/settings/units', [UnitPreferenceController::class, 'show'])->middleware('farm.permission:measurement.view')->name('api.v1.settings.units.show');
    Route::put('/settings/units', [UnitPreferenceController::class, 'update'])->middleware(['farm.permission:measurement.manage', 'throttle:measurement-write'])->name('api.v1.settings.units.update');

    Route::get('/settings/measurement-contexts', [MeasurementContextController::class, 'index'])->middleware('farm.permission:measurement.view')->name('api.v1.settings.measurement-contexts.index');
    Route::post('/settings/measurement-contexts', [MeasurementContextController::class, 'store'])->middleware(['farm.permission:measurement.manage', 'throttle:measurement-write'])->name('api.v1.settings.measurement-contexts.store');
    Route::patch('/settings/measurement-contexts/{context}', [MeasurementContextController::class, 'update'])->middleware(['farm.permission:measurement.manage', 'throttle:measurement-write'])->name('api.v1.settings.measurement-contexts.update');

    Route::get('/settings/package-conversions', [PackageConversionController::class, 'index'])->middleware('farm.permission:measurement.view')->name('api.v1.settings.package-conversions.index');
    Route::post('/settings/package-conversions', [PackageConversionController::class, 'store'])->middleware(['farm.permission:measurement.manage', 'throttle:measurement-write'])->name('api.v1.settings.package-conversions.store');
    Route::patch('/settings/package-conversions/{conversion}', [PackageConversionController::class, 'update'])->middleware(['farm.permission:measurement.manage', 'throttle:measurement-write'])->name('api.v1.settings.package-conversions.update');

    Route::post('/measurements/normalize', [QuantityNormalizationController::class, '__invoke'])->middleware(['farm.permission:measurement.view', 'throttle:measurement-preview'])->name('api.v1.measurements.normalize');

    // Per-user, per-farm notification switches (shell only)
    Route::get('/settings/notifications', [NotificationPreferenceController::class, 'show'])->middleware('farm.permission:farm.view')->name('api.v1.settings.notifications.show');
    Route::put('/settings/notifications', [NotificationPreferenceController::class, 'update'])->middleware('farm.permission:farm.view')->name('api.v1.settings.notifications.update');
});
