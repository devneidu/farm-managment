<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\Auth\CsrfCookieController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\GoogleAuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\BreedingProjectController;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\CropProjectController;
use App\Http\Controllers\Api\V1\CustomBreedController;
use App\Http\Controllers\Api\V1\CustomVarietyController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\FarmController;
use App\Http\Controllers\Api\V1\FarmInvitationController;
use App\Http\Controllers\Api\V1\FarmOperationController;
use App\Http\Controllers\Api\V1\FeedFormulaController;
use App\Http\Controllers\Api\V1\FinanceController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\HealthRecordController;
use App\Http\Controllers\Api\V1\InsightController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\InvitationAcceptanceController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\LocaleController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\LocationTypeController;
use App\Http\Controllers\Api\V1\Marketplace\MarketplaceCatalogueController;
use App\Http\Controllers\Api\V1\Marketplace\MarketplaceListingController;
use App\Http\Controllers\Api\V1\Marketplace\MarketplaceListingImageController;
use App\Http\Controllers\Api\V1\Marketplace\MarketplaceOfferController;
use App\Http\Controllers\Api\V1\Marketplace\MarketplacePublicController;
use App\Http\Controllers\Api\V1\Marketplace\MarketplacePublicListingController;
use App\Http\Controllers\Api\V1\Marketplace\MarketplaceShopController;
use App\Http\Controllers\Api\V1\Marketplace\MarketplaceShopMemberController;
use App\Http\Controllers\Api\V1\Marketplace\MarketplaceShopOfferController;
use App\Http\Controllers\Api\V1\MasterDataController;
use App\Http\Controllers\Api\V1\MeasurementCatalogueController;
use App\Http\Controllers\Api\V1\MeasurementContextController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\OperationalRecordController;
use App\Http\Controllers\Api\V1\PackageConversionController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\Platform\PlatformAdminController;
use App\Http\Controllers\Api\V1\Platform\PlatformConfigController;
use App\Http\Controllers\Api\V1\Platform\PlatformMarketplaceController;
use App\Http\Controllers\Api\V1\Platform\PlatformMarketplaceListingController;
use App\Http\Controllers\Api\V1\Platform\PlatformMasterDataController;
use App\Http\Controllers\Api\V1\Platform\PlatformPlanController;
use App\Http\Controllers\Api\V1\Platform\PlatformSupportController;
use App\Http\Controllers\Api\V1\Platform\PlatformTemplateController;
use App\Http\Controllers\Api\V1\ProductionAreaController;
use App\Http\Controllers\Api\V1\ProductionCycleController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\QuantityNormalizationController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ReportExportController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\StorageLocationController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TeamMemberController;
use App\Http\Controllers\Api\V1\UnitPreferenceController;
use App\Http\Controllers\Api\V1\UserPreferenceController;
use App\Http\Controllers\Api\V1\WorkTemplateController;
use App\Services\Platform\PlatformMasterDataService;
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

Route::get('/locales', [LocaleController::class, 'index'])->middleware('throttle:60,1')->name('api.v1.locales.index');
Route::get('/translations/{locale}', [LocaleController::class, 'bundle'])->middleware('throttle:60,1')->name('api.v1.translations.show');

// Marketplace discovery (Phase 22): anonymous, ACTIVE shops only.
Route::get('/public/marketplace/shops', [MarketplacePublicController::class, 'index'])->middleware('throttle:60,1')->name('api.v1.public.marketplace.shops.index');
Route::get('/public/marketplace/shops/{slug}', [MarketplacePublicController::class, 'show'])->where('slug', '[a-z0-9-]+')->middleware('throttle:60,1')->name('api.v1.public.marketplace.shops.show');

// Marketplace listings (Phase 23): anonymous, PUBLISHED listings of ACTIVE shops only. Files are streamed by the application from a private disk.
Route::get('/public/marketplace/listings', [MarketplacePublicListingController::class, 'index'])->middleware('throttle:60,1')->name('api.v1.public.marketplace.listings.index');
Route::get('/public/marketplace/listings/{slug}', [MarketplacePublicListingController::class, 'show'])->where('slug', '[a-z0-9-]+')->middleware('throttle:60,1')->name('api.v1.public.marketplace.listings.show');
Route::get('/public/marketplace/listings/{slug}/price-preview', [MarketplacePublicListingController::class, 'preview'])->where('slug', '[a-z0-9-]+')->middleware('throttle:60,1')->name('api.v1.public.marketplace.listings.preview');
Route::get('/public/marketplace/images/{image}', [MarketplacePublicListingController::class, 'image'])->whereUuid('image')->middleware('throttle:240,1')->name('api.v1.public.marketplace.images.show');
Route::get('/public/marketplace/catalogue-images/{code}', [MarketplacePublicListingController::class, 'catalogueImage'])->where('code', '[a-z0-9-]+')->middleware('throttle:240,1')->name('api.v1.public.marketplace.catalogue-images.show');

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

    // My UI preferences (user-level): language
    Route::get('/me/preferences', [UserPreferenceController::class, 'show'])->name('api.v1.me.preferences.show');
    Route::patch('/me/preferences', [UserPreferenceController::class, 'update'])->name('api.v1.me.preferences.update');

    // Marketplace seller shops (Phase 22). User-level like /account: no farm, no onboarding, no farm context. A shop is reached only through the
    // caller's shop membership; the shop role (not any farm role) authorises each action.
    Route::prefix('marketplace')->group(function () {
        Route::get('/my/shops', [MarketplaceShopController::class, 'mine'])->name('api.v1.marketplace.my-shops');
        Route::get('/product-options', [MarketplaceCatalogueController::class, 'productOptions'])->name('api.v1.marketplace.product-options');
        // Buyer offers and purchase intents (Phase 24): any signed-in user; the listing must be public.
        Route::prefix('/listings/{slug}')->where(['slug' => '[a-z0-9-]+'])->group(function () {
            Route::post('/offers', [MarketplaceOfferController::class, 'store'])->middleware('throttle:marketplace-write')->name('api.v1.marketplace.listings.offers.store');
            Route::get('/offer-status', [MarketplaceOfferController::class, 'status'])->name('api.v1.marketplace.listings.offer-status');
            Route::post('/purchase-intent', [MarketplaceOfferController::class, 'intent'])->middleware('throttle:marketplace-write')->name('api.v1.marketplace.listings.purchase-intent');
        });
        Route::get('/my/enquiries', [MarketplaceOfferController::class, 'index'])->name('api.v1.marketplace.my-enquiries');
        Route::get('/my/offers/{offer}', [MarketplaceOfferController::class, 'show'])->whereUuid('offer')->name('api.v1.marketplace.my-offers.show');
        Route::get('/image-library', [MarketplaceCatalogueController::class, 'imageLibrary'])->name('api.v1.marketplace.image-library');
        Route::post('/shops', [MarketplaceShopController::class, 'store'])->middleware('throttle:marketplace-write')->name('api.v1.marketplace.shops.store');
        Route::prefix('/shops/{shop}')->whereUuid('shop')->group(function () {
            Route::get('/', [MarketplaceShopController::class, 'show'])->name('api.v1.marketplace.shops.show');
            Route::patch('/', [MarketplaceShopController::class, 'update'])->middleware('throttle:marketplace-write')->name('api.v1.marketplace.shops.update');
            Route::get('/contact', [MarketplaceShopController::class, 'contact'])->name('api.v1.marketplace.shops.contact');
            Route::patch('/contact', [MarketplaceShopController::class, 'updateContact'])->middleware('throttle:marketplace-write')->name('api.v1.marketplace.shops.contact.update');
            Route::post('/submit', [MarketplaceShopController::class, 'submit'])->middleware('throttle:marketplace-write')->name('api.v1.marketplace.shops.submit');
            Route::post('/close', [MarketplaceShopController::class, 'close'])->middleware('throttle:marketplace-write')->name('api.v1.marketplace.shops.close');
            Route::post('/reopen', [MarketplaceShopController::class, 'reopen'])->middleware('throttle:marketplace-write')->name('api.v1.marketplace.shops.reopen');
            Route::post('/request-verification', [MarketplaceShopController::class, 'requestVerification'])->middleware('throttle:marketplace-write')->name('api.v1.marketplace.shops.request-verification');
            Route::prefix('listings')->group(function () {
                Route::get('/', [MarketplaceListingController::class, 'index'])->name('api.v1.marketplace.shops.listings.index');
                Route::post('/', [MarketplaceListingController::class, 'store'])->middleware('throttle:marketplace-write')->name('api.v1.marketplace.shops.listings.store');
                Route::get('/eligible-inventory', [MarketplaceListingController::class, 'eligibleInventory'])->name('api.v1.marketplace.shops.listings.eligible-inventory');
                Route::prefix('{listing}')->whereUuid('listing')->group(function () {
                    Route::get('/', [MarketplaceListingController::class, 'show'])->name('api.v1.marketplace.shops.listings.show');
                    Route::patch('/', [MarketplaceListingController::class, 'update'])->middleware('throttle:marketplace-write')->name('api.v1.marketplace.shops.listings.update');
                    Route::delete('/', [MarketplaceListingController::class, 'destroy'])->middleware('throttle:marketplace-write')->name('api.v1.marketplace.shops.listings.destroy');
                    Route::post('/publish', [MarketplaceListingController::class, 'publish'])->middleware('throttle:marketplace-write')->name('api.v1.marketplace.shops.listings.publish');
                    Route::post('/pause', [MarketplaceListingController::class, 'pause'])->middleware('throttle:marketplace-write')->name('api.v1.marketplace.shops.listings.pause');
                    Route::post('/archive', [MarketplaceListingController::class, 'archive'])->middleware('throttle:marketplace-write')->name('api.v1.marketplace.shops.listings.archive');
                    Route::post('/restore', [MarketplaceListingController::class, 'restore'])->middleware('throttle:marketplace-write')->name('api.v1.marketplace.shops.listings.restore');
                    Route::post('/price-preview', [MarketplaceListingController::class, 'preview'])->middleware('throttle:marketplace-write')->name('api.v1.marketplace.shops.listings.price-preview');
                    Route::post('/images', [MarketplaceListingImageController::class, 'store'])->middleware(['throttle:marketplace-write', 'throttle:marketplace-upload'])->name('api.v1.marketplace.shops.listings.images.store');
                    Route::patch('/images/{image}', [MarketplaceListingImageController::class, 'update'])->whereUuid('image')->middleware('throttle:marketplace-write')->name('api.v1.marketplace.shops.listings.images.update');
                    Route::delete('/images/{image}', [MarketplaceListingImageController::class, 'destroy'])->whereUuid('image')->middleware('throttle:marketplace-write')->name('api.v1.marketplace.shops.listings.images.destroy');
                    Route::get('/images/{image}/file', [MarketplaceListingImageController::class, 'file'])->whereUuid('image')->name('api.v1.marketplace.shops.listings.images.file');
                });
            });
            Route::get('/offers', [MarketplaceShopOfferController::class, 'index'])->name('api.v1.marketplace.shops.offers.index');
            Route::get('/offers/{offer}', [MarketplaceShopOfferController::class, 'show'])->whereUuid('offer')->name('api.v1.marketplace.shops.offers.show');
            Route::post('/offers/{offer}/accept', [MarketplaceShopOfferController::class, 'accept'])->whereUuid('offer')->middleware('throttle:marketplace-write')->name('api.v1.marketplace.shops.offers.accept');
            Route::post('/offers/{offer}/reject', [MarketplaceShopOfferController::class, 'reject'])->whereUuid('offer')->middleware('throttle:marketplace-write')->name('api.v1.marketplace.shops.offers.reject');
            Route::get('/purchase-intents', [MarketplaceShopOfferController::class, 'intents'])->name('api.v1.marketplace.shops.purchase-intents.index');
            Route::get('/members', [MarketplaceShopMemberController::class, 'index'])->name('api.v1.marketplace.shops.members.index');
            Route::post('/members', [MarketplaceShopMemberController::class, 'store'])->middleware('throttle:marketplace-write')->name('api.v1.marketplace.shops.members.store');
            Route::patch('/members/{member}', [MarketplaceShopMemberController::class, 'update'])->whereUuid('member')->middleware('throttle:marketplace-write')->name('api.v1.marketplace.shops.members.update');
            Route::delete('/members/{member}', [MarketplaceShopMemberController::class, 'destroy'])->whereUuid('member')->middleware('throttle:marketplace-write')->name('api.v1.marketplace.shops.members.destroy');
        });
    });

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

    // Phase 6: distinct ERD entities; parent is optional for every collection. No plan gate or DELETE.
    Route::get('/master/location-types', [LocationTypeController::class, 'index'])->middleware('farm.permission:location.view')->name('api.v1.master.location-types');
    foreach (['locations' => LocationController::class, 'production-areas' => ProductionAreaController::class, 'storage-locations' => StorageLocationController::class] as $path => $controller) {
        Route::get('/'.$path, [$controller, 'index'])->middleware('farm.permission:location.view')->name('api.v1.'.$path.'.index');
        Route::get('/'.$path.'/{place}', [$controller, 'show'])->middleware('farm.permission:location.view')->name('api.v1.'.$path.'.show');
        Route::post('/'.$path, [$controller, 'store'])->middleware(['farm.permission:location.manage', 'throttle:location-write'])->name('api.v1.'.$path.'.store');
        Route::patch('/'.$path.'/{place}', [$controller, 'update'])->middleware(['farm.permission:location.manage', 'throttle:location-write'])->name('api.v1.'.$path.'.update');
    }

    Route::prefix('production-cycles')->group(function () {
        $controller = ProductionCycleController::class;
        Route::get('/', [$controller, 'index'])->middleware('farm.permission:production_cycle.view')->name('api.v1.production-cycles.index');
        Route::get('/{cycle}', [$controller, 'show'])->middleware('farm.permission:production_cycle.view')->name('api.v1.production-cycles.show');
        Route::get('/{cycle}/summary', [$controller, 'summary'])->middleware('farm.permission:production_cycle.view')->name('api.v1.production-cycles.summary');
        Route::get('/{cycle}/crop', [CropProjectController::class, 'show'])->middleware('farm.permission:production_cycle.view')->name('api.v1.production-cycles.crop');
        Route::get('/{cycle}/activity', [$controller, 'activity'])->middleware('farm.permission:production_cycle.view')->name('api.v1.production-cycles.activity');
        foreach (['store' => 'create', 'update' => 'update', 'close' => 'close', 'reopen' => 'reopen'] as $action => $permission) {
            $path = match ($action) {
                'store' => '/', 'update' => '/{cycle}', default => '/{cycle}/'.$action
            };
            Route::match([$action === 'update' ? 'PATCH' : 'POST'], $path, [$controller, $action])->middleware(['farm.permission:production_cycle.'.$permission, 'throttle:production-cycle-write'])->name('api.v1.production-cycles.'.$action);
        }
    });

    Route::get('/master/record-types', [OperationalRecordController::class, 'types'])->middleware('farm.permission:record.view')->name('api.v1.record-types.index');
    Route::get('/record-types/{type}/schema', [OperationalRecordController::class, 'schema'])->middleware('farm.permission:record.view')->name('api.v1.record-types.schema');
    Route::prefix('records')->group(function () {
        $controller = OperationalRecordController::class;
        Route::get('/', [$controller, 'index'])->middleware('farm.permission:record.view')->name('api.v1.records.index');
        Route::get('/{record}', [$controller, 'show'])->middleware('farm.permission:record.view')->name('api.v1.records.show');
        Route::post('/', [$controller, 'store'])->middleware(['farm.permission:record.create', 'throttle:record-write'])->name('api.v1.records.store');
        Route::post('/{record}/reverse', [$controller, 'reverse'])->middleware(['farm.permission:record.reverse', 'throttle:record-write'])->name('api.v1.records.reverse');
        Route::post('/{record}/attachments', [$controller, 'attach'])->middleware(['farm.permission:record.create', 'throttle:record-write'])->name('api.v1.records.attachments.store');
        Route::get('/{record}/attachments/{attachment}', [$controller, 'download'])->middleware('farm.permission:record.view')->name('api.v1.records.attachments.download');
    });

    Route::get('/master/health-record-types', [HealthRecordController::class, 'types'])->middleware('farm.permission:health.view')->name('api.v1.health-record-types.index');
    Route::prefix('health-records')->group(function () {
        $c = HealthRecordController::class;
        Route::get('/', [$c, 'index'])->middleware('farm.permission:health.view')->name('api.v1.health-records.index');
        Route::post('/', [$c, 'store'])->middleware(['farm.permission:health.create', 'throttle:health-write'])->name('api.v1.health-records.store');
        Route::get('/{record}', [$c, 'show'])->middleware('farm.permission:health.view')->name('api.v1.health-records.show');
        Route::post('/{record}/reverse', [$c, 'reverse'])->middleware(['farm.permission:health.reverse', 'throttle:health-write'])->name('api.v1.health-records.reverse');
    });
    Route::prefix('breeding-projects')->group(function () {
        $c = BreedingProjectController::class;
        Route::get('/', [$c, 'index'])->middleware('farm.permission:breeding.view')->name('api.v1.breeding-projects.index');
        Route::post('/', [$c, 'store'])->middleware(['farm.permission:breeding.create', 'throttle:breeding-write'])->name('api.v1.breeding-projects.store');
        Route::get('/{project}', [$c, 'show'])->middleware('farm.permission:breeding.view')->name('api.v1.breeding-projects.show');
        Route::patch('/{project}', [$c, 'update'])->middleware(['farm.permission:breeding.create', 'throttle:breeding-write'])->name('api.v1.breeding-projects.update');
        Route::get('/{project}/milestones', [$c, 'milestones'])->middleware('farm.permission:breeding.view')->name('api.v1.breeding-projects.milestones');
        Route::post('/{project}/checks', [$c, 'check'])->middleware(['farm.permission:breeding.create', 'throttle:breeding-write'])->name('api.v1.breeding-projects.checks.store');
        Route::post('/{project}/cancel', [$c, 'cancel'])->middleware(['farm.permission:breeding.create', 'throttle:breeding-write'])->name('api.v1.breeding-projects.cancel');
        Route::post('/{project}/outcomes', [$c, 'outcome'])->middleware(['farm.permission:breeding.create', 'throttle:breeding-write'])->name('api.v1.breeding-projects.outcomes.store');
        Route::post('/{project}/outcomes/{outcome}/reverse', [$c, 'reverseOutcome'])->middleware(['farm.permission:breeding.reverse', 'throttle:breeding-write'])->name('api.v1.breeding-projects.outcomes.reverse');
    });
    // Phase 12: tasks (work that should happen), schedules (recurrence rules that generate tasks), templates and the calendar read model.
    // Tasks never create operational records; completion can only link an already saved record as evidence.
    Route::get('/master/task-categories', [TaskController::class, 'categories'])->middleware('farm.permission:task.view')->name('api.v1.master.task-categories');
    Route::prefix('tasks')->group(function () {
        $c = TaskController::class;
        Route::get('/', [$c, 'index'])->middleware('farm.permission:task.view')->name('api.v1.tasks.index');
        Route::post('/', [$c, 'store'])->middleware(['farm.permission:task.manage', 'throttle:work-write'])->name('api.v1.tasks.store');
        Route::get('/{task}', [$c, 'show'])->middleware('farm.permission:task.view')->name('api.v1.tasks.show');
        Route::patch('/{task}', [$c, 'update'])->middleware(['farm.permission:task.manage', 'throttle:work-write'])->name('api.v1.tasks.update');
        Route::post('/{task}/complete', [$c, 'complete'])->middleware(['farm.permission:task.complete', 'throttle:work-write'])->name('api.v1.tasks.complete');
        Route::post('/{task}/cancel', [$c, 'cancel'])->middleware(['farm.permission:task.manage', 'throttle:work-write'])->name('api.v1.tasks.cancel');
        Route::get('/{task}/record-prefill', [$c, 'recordPrefill'])->middleware('farm.permission:task.view')->name('api.v1.tasks.record-prefill');
    });
    Route::prefix('schedules')->middleware('farm.permission:task.manage')->group(function () {
        $c = ScheduleController::class;
        Route::get('/', [$c, 'index'])->name('api.v1.schedules.index');
        Route::post('/', [$c, 'store'])->middleware('throttle:work-write')->name('api.v1.schedules.store');
        Route::get('/{schedule}', [$c, 'show'])->name('api.v1.schedules.show');
        Route::post('/{schedule}/end', [$c, 'end'])->middleware('throttle:work-write')->name('api.v1.schedules.end');
    });
    Route::get('/calendar', CalendarController::class)->middleware('farm.permission:task.view')->name('api.v1.calendar');
    // Phase 16: dashboard and insights are read models for any active member; each block is gated by the viewer's own permissions.
    Route::get('/dashboard', [DashboardController::class, 'show'])->name('api.v1.dashboard');
    Route::get('/dashboard/calendar', [DashboardController::class, 'calendar'])->middleware('farm.permission:task.view')->name('api.v1.dashboard.calendar');
    Route::get('/insights', [InsightController::class, 'index'])->name('api.v1.insights');
    Route::prefix('work-templates')->group(function () {
        $c = WorkTemplateController::class;
        Route::get('/', [$c, 'index'])->middleware('farm.permission:task.view')->name('api.v1.work-templates.index');
        Route::get('/recommended', [$c, 'recommended'])->middleware('farm.permission:task.view')->name('api.v1.work-templates.recommended');
        Route::post('/', [$c, 'store'])->middleware(['farm.permission:task.manage', 'throttle:work-write'])->name('api.v1.work-templates.store');
        Route::get('/{template}', [$c, 'show'])->middleware('farm.permission:task.view')->name('api.v1.work-templates.show');
        Route::patch('/{template}', [$c, 'update'])->middleware(['farm.permission:task.manage', 'throttle:work-write'])->name('api.v1.work-templates.update');
        Route::post('/{template}/clone', [$c, 'clone'])->middleware(['farm.permission:task.manage', 'throttle:work-write'])->name('api.v1.work-templates.clone');
        Route::post('/{template}/apply', [$c, 'apply'])->middleware(['farm.permission:task.manage', 'throttle:work-write'])->name('api.v1.work-templates.apply');
    });

    Route::prefix('health')->group(function () {
        $c = HealthRecordController::class;
        Route::get('/withdrawals', [$c, 'withdrawals'])->middleware('farm.permission:health.view')->name('api.v1.health.withdrawals');
        Route::get('/medicines', [$c, 'medicines'])->middleware('farm.permission:health.view')->name('api.v1.health.medicines.index');
        Route::get('/medicines/{item}', [$c, 'medicine'])->middleware('farm.permission:health.view')->name('api.v1.health.medicines.show');
        Route::put('/medicines/{item}/profile', [$c, 'updateProfile'])->middleware(['farm.permission:health.manage', 'throttle:health-write'])->name('api.v1.health.medicines.profile');
    });

    Route::get('/master/inventory-options', [InventoryController::class, 'options'])->middleware('farm.permission:inventory.view')->name('api.v1.inventory.options');
    Route::prefix('inventory')->group(function () {
        $c = InventoryController::class;
        Route::get('/output-balances', [$c, 'outputBalances'])->middleware('farm.permission:inventory.view')->name('api.v1.inventory.output-balances');
        Route::get('/items', [$c, 'items'])->middleware('farm.permission:inventory.view')->name('api.v1.inventory.items.index');
        Route::post('/items', [$c, 'storeItem'])->middleware(['farm.permission:inventory.manage', 'throttle:inventory-write'])->name('api.v1.inventory.items.store');
        Route::get('/items/{item}', [$c, 'showItem'])->middleware('farm.permission:inventory.view')->name('api.v1.inventory.items.show');
        Route::patch('/items/{item}', [$c, 'updateItem'])->middleware(['farm.permission:inventory.manage', 'throttle:inventory-write'])->name('api.v1.inventory.items.update');
        Route::get('/items/{item}/movements', [$c, 'itemMovements'])->middleware('farm.permission:inventory.view')->name('api.v1.inventory.items.movements');
        Route::get('/movements', [$c, 'movements'])->middleware('farm.permission:inventory.view')->name('api.v1.inventory.movements.index');
        Route::get('/movements/{movement}', [$c, 'showMovement'])->middleware('farm.permission:inventory.view')->name('api.v1.inventory.movements.show');
        Route::post('/movements/{movement}/reverse', [$c, 'reverse'])->middleware(['farm.permission:inventory.adjust', 'throttle:inventory-write'])->name('api.v1.inventory.movements.reverse');
        Route::get('/lots', [$c, 'lots'])->middleware('farm.permission:inventory.view')->name('api.v1.inventory.lots.index');
        Route::post('/stock-in', [$c, 'stockIn'])->middleware(['farm.permission:inventory.manage', 'throttle:inventory-write'])->name('api.v1.inventory.stock-in');
        Route::post('/stock-out', [$c, 'stockOut'])->middleware(['farm.permission:inventory.use', 'throttle:inventory-write'])->name('api.v1.inventory.stock-out');
        Route::post('/adjustments', [$c, 'adjust'])->middleware(['farm.permission:inventory.adjust', 'throttle:inventory-write'])->name('api.v1.inventory.adjustments');
        Route::post('/transfers', [$c, 'transfer'])->middleware(['farm.permission:inventory.manage', 'throttle:inventory-write'])->name('api.v1.inventory.transfers');
    });
    Route::prefix('feed-formulas')->group(function () {
        $c = FeedFormulaController::class;
        Route::get('/', [$c, 'index'])->middleware('farm.permission:inventory.view')->name('api.v1.feed-formulas.index');
        Route::post('/', [$c, 'store'])->middleware(['farm.permission:inventory.manage', 'throttle:inventory-write'])->name('api.v1.feed-formulas.store');
        Route::get('/{formula}', [$c, 'show'])->middleware('farm.permission:inventory.view')->name('api.v1.feed-formulas.show');
        Route::patch('/{formula}', [$c, 'update'])->middleware(['farm.permission:inventory.manage', 'throttle:inventory-write'])->name('api.v1.feed-formulas.update');
    });

    // Phase 14: contacts, purchases and the money ledger. A purchase is the only path that books stock + expense together;
    // finance transactions never touch stock.
    Route::prefix('contacts')->group(function () {
        $c = ContactController::class;
        Route::get('/', [$c, 'index'])->middleware('farm.permission:contact.view')->name('api.v1.contacts.index');
        Route::post('/', [$c, 'store'])->middleware(['farm.permission:contact.manage', 'throttle:finance-write'])->name('api.v1.contacts.store');
        Route::get('/{contact}', [$c, 'show'])->middleware('farm.permission:contact.view')->name('api.v1.contacts.show');
        Route::patch('/{contact}', [$c, 'update'])->middleware(['farm.permission:contact.manage', 'throttle:finance-write'])->name('api.v1.contacts.update');
    });
    Route::prefix('purchases')->group(function () {
        $c = PurchaseController::class;
        Route::get('/', [$c, 'index'])->middleware('farm.permission:purchase.view')->name('api.v1.purchases.index');
        Route::post('/', [$c, 'store'])->middleware(['farm.permission:purchase.create', 'throttle:finance-write'])->name('api.v1.purchases.store');
        Route::get('/{purchase}', [$c, 'show'])->middleware('farm.permission:purchase.view')->name('api.v1.purchases.show');
        Route::post('/{purchase}/cancel', [$c, 'cancel'])->middleware(['farm.permission:purchase.cancel', 'throttle:finance-write'])->name('api.v1.purchases.cancel');
    });
    Route::prefix('finance')->group(function () {
        $c = FinanceController::class;
        Route::get('/categories', [$c, 'categories'])->middleware('farm.permission:finance.view')->name('api.v1.finance.categories');
        Route::get('/summary', [$c, 'summary'])->middleware('farm.permission:finance.view')->name('api.v1.finance.summary');
        Route::get('/transactions', [$c, 'index'])->middleware('farm.permission:finance.view')->name('api.v1.finance.transactions.index');
        Route::post('/transactions', [$c, 'store'])->middleware(['farm.permission:finance.create', 'throttle:finance-write'])->name('api.v1.finance.transactions.store');
        Route::get('/transactions/{transaction}', [$c, 'show'])->middleware('farm.permission:finance.view')->name('api.v1.finance.transactions.show');
        Route::post('/transactions/{transaction}/reverse', [$c, 'reverse'])->middleware(['farm.permission:finance.reverse', 'throttle:finance-write'])->name('api.v1.finance.transactions.reverse');
    });
    Route::post('/expenses', [FinanceController::class, 'expense'])->middleware(['farm.permission:finance.create', 'throttle:finance-write'])->name('api.v1.expenses.store');
    Route::post('/income', [FinanceController::class, 'income'])->middleware(['farm.permission:finance.create', 'throttle:finance-write'])->name('api.v1.income.store');
    // Phase 15: sales (the event), invoices (the customer document) and payments (money received) are three separate resources.
    Route::prefix('sales')->group(function () {
        $c = SaleController::class;
        Route::get('/', [$c, 'index'])->middleware('farm.permission:sale.view')->name('api.v1.sales.index');
        Route::post('/', [$c, 'store'])->middleware(['farm.permission:sale.create', 'throttle:finance-write'])->name('api.v1.sales.store');
        Route::get('/{sale}', [$c, 'show'])->middleware('farm.permission:sale.view')->name('api.v1.sales.show');
        Route::post('/{sale}/cancel', [$c, 'cancel'])->middleware(['farm.permission:sale.cancel', 'throttle:finance-write'])->name('api.v1.sales.cancel');
        Route::post('/{sale}/invoice', [$c, 'invoice'])->middleware(['farm.permission:invoice.create', 'throttle:finance-write'])->name('api.v1.sales.invoice');
    });
    Route::prefix('invoices')->group(function () {
        $c = InvoiceController::class;
        Route::get('/', [$c, 'index'])->middleware('farm.permission:invoice.view')->name('api.v1.invoices.index');
        Route::post('/', [$c, 'store'])->middleware(['farm.permission:invoice.create', 'throttle:finance-write'])->name('api.v1.invoices.store');
        Route::get('/{invoice}', [$c, 'show'])->middleware('farm.permission:invoice.view')->name('api.v1.invoices.show');
        Route::get('/{invoice}/pdf', [$c, 'pdf'])->middleware(['farm.permission:invoice.view', 'throttle:report-download'])->name('api.v1.invoices.pdf');
        Route::post('/{invoice}/void', [$c, 'void'])->middleware(['farm.permission:invoice.void', 'throttle:finance-write'])->name('api.v1.invoices.void');
        Route::post('/{invoice}/payments', [$c, 'pay'])->middleware(['farm.permission:payment.create', 'throttle:finance-write'])->name('api.v1.invoices.payments.store');
    });
    Route::prefix('payments')->group(function () {
        $c = PaymentController::class;
        Route::get('/', [$c, 'index'])->middleware('farm.permission:payment.view')->name('api.v1.payments.index');
        Route::get('/{payment}', [$c, 'show'])->middleware('farm.permission:payment.view')->name('api.v1.payments.show');
        Route::post('/{payment}/reverse', [$c, 'reverse'])->middleware(['farm.permission:payment.reverse', 'throttle:finance-write'])->name('api.v1.payments.reverse');
    });

    // Phase 17: reports (read-only derivations; each also needs the permissions of the data it reads), queued private exports, the notification centre and the audit trail.
    Route::get('/reports', [ReportController::class, 'index'])->middleware('farm.permission:report.view')->name('api.v1.reports.index');
    Route::prefix('reports/exports')->group(function () {
        $c = ReportExportController::class;
        Route::get('/', [$c, 'index'])->middleware('farm.permission:report.export')->name('api.v1.reports.exports.index');
        Route::post('/', [$c, 'store'])->middleware(['farm.permission:report.export', 'throttle:report-export'])->name('api.v1.reports.exports.store');
        Route::get('/{export}', [$c, 'show'])->middleware('farm.permission:report.export')->name('api.v1.reports.exports.show');
        Route::get('/{export}/download', [$c, 'download'])->middleware(['farm.permission:report.export', 'throttle:report-download'])->name('api.v1.reports.exports.download');
    });
    Route::get('/reports/{report}', [ReportController::class, 'show'])->middleware(['farm.permission:report.view', 'throttle:report-run'])->name('api.v1.reports.show');
    Route::prefix('notifications')->group(function () {
        $c = NotificationController::class;
        Route::get('/', [$c, 'index'])->name('api.v1.notifications.index');
        Route::post('/read-all', [$c, 'readAll'])->name('api.v1.notifications.read-all');
        Route::post('/{notification}/read', [$c, 'read'])->name('api.v1.notifications.read');
    });
    Route::get('/notification-preferences', [NotificationPreferenceController::class, 'current'])->name('api.v1.notification-preferences.show');
    Route::patch('/notification-preferences', [NotificationPreferenceController::class, 'patch'])->name('api.v1.notification-preferences.update');
    Route::get('/audit', [AuditController::class, 'index'])->middleware('farm.permission:audit.view')->name('api.v1.audit.index');
    // Per-user, per-farm notification switches (shell only)
    Route::get('/settings/notifications', [NotificationPreferenceController::class, 'show'])->middleware('farm.permission:farm.view')->name('api.v1.settings.notifications.show');
    Route::put('/settings/notifications', [NotificationPreferenceController::class, 'update'])->middleware('farm.permission:farm.view')->name('api.v1.settings.notifications.update');
});

/*
|--------------------------------------------------------------------------
| Platform administration (Phase 18)
|--------------------------------------------------------------------------
| NOT farm routes: no farm context, no farm permission. Access needs a platform_admins grant (`platform.admin`); every write needs the
| `admin` platform role (`platform.admin:write`). Farm roles such as Owner/Manager confer nothing here.
*/
Route::prefix('platform-admin')->middleware(['auth:sanctum', 'account.active', 'email.verified', 'platform.admin'])->group(function () {
    $write = ['platform.admin:write', 'throttle:platform-admin-write'];
    $kinds = implode('|', array_keys(PlatformMasterDataService::KINDS));

    Route::get('/me', [PlatformAdminController::class, 'me'])->name('api.v1.platform.me');

    // Plans, prices, entitlements, farm plan changes
    Route::get('/entitlements', [PlatformPlanController::class, 'registry'])->name('api.v1.platform.entitlements');
    Route::get('/plans', [PlatformPlanController::class, 'index'])->name('api.v1.platform.plans.index');
    Route::get('/plans/{plan}', [PlatformPlanController::class, 'show'])->whereUuid('plan')->name('api.v1.platform.plans.show');
    Route::post('/plans', [PlatformPlanController::class, 'store'])->middleware($write)->name('api.v1.platform.plans.store');
    Route::patch('/plans/{plan}', [PlatformPlanController::class, 'update'])->whereUuid('plan')->middleware($write)->name('api.v1.platform.plans.update');
    Route::post('/plans/{plan}/make-default', [PlatformPlanController::class, 'makeDefault'])->whereUuid('plan')->middleware($write)->name('api.v1.platform.plans.make-default');
    Route::put('/plans/{plan}/prices', [PlatformPlanController::class, 'prices'])->whereUuid('plan')->middleware($write)->name('api.v1.platform.plans.prices');
    Route::put('/plans/{plan}/entitlements', [PlatformPlanController::class, 'entitlements'])->whereUuid('plan')->middleware($write)->name('api.v1.platform.plans.entitlements');

    // Reference data and capability schemas
    Route::get('/master/capabilities', [PlatformMasterDataController::class, 'capabilitySchemas'])->name('api.v1.platform.master.capabilities');
    Route::get('/master/species/{species}/capabilities', [PlatformMasterDataController::class, 'speciesCapabilities'])->whereUuid('species')->name('api.v1.platform.master.species-capabilities');
    Route::put('/master/species/{species}/capabilities/{capability}', [PlatformMasterDataController::class, 'setCapability'])->whereUuid('species')->middleware($write)->name('api.v1.platform.master.species-capabilities.set');
    Route::get('/master/{kind}', [PlatformMasterDataController::class, 'index'])->where('kind', $kinds)->name('api.v1.platform.master.index');
    Route::post('/master/{kind}', [PlatformMasterDataController::class, 'store'])->where('kind', $kinds)->middleware($write)->name('api.v1.platform.master.store');
    Route::get('/master/{kind}/{id}', [PlatformMasterDataController::class, 'show'])->where('kind', $kinds)->whereUuid('id')->name('api.v1.platform.master.show');
    Route::patch('/master/{kind}/{id}', [PlatformMasterDataController::class, 'update'])->where('kind', $kinds)->whereUuid('id')->middleware($write)->name('api.v1.platform.master.update');

    // Marketplace shop oversight (Phase 22)
    Route::prefix('marketplace/shops')->group(function () use ($write) {
        Route::get('/', [PlatformMarketplaceController::class, 'index'])->name('api.v1.platform.marketplace.shops.index');
        Route::get('/{shop}', [PlatformMarketplaceController::class, 'show'])->whereUuid('shop')->name('api.v1.platform.marketplace.shops.show');
        Route::post('/{shop}/approve', [PlatformMarketplaceController::class, 'approve'])->whereUuid('shop')->middleware($write)->name('api.v1.platform.marketplace.shops.approve');
        Route::post('/{shop}/reject', [PlatformMarketplaceController::class, 'reject'])->whereUuid('shop')->middleware($write)->name('api.v1.platform.marketplace.shops.reject');
        Route::post('/{shop}/suspend', [PlatformMarketplaceController::class, 'suspend'])->whereUuid('shop')->middleware($write)->name('api.v1.platform.marketplace.shops.suspend');
        Route::post('/{shop}/reinstate', [PlatformMarketplaceController::class, 'reinstate'])->whereUuid('shop')->middleware($write)->name('api.v1.platform.marketplace.shops.reinstate');
        Route::post('/{shop}/verification', [PlatformMarketplaceController::class, 'verification'])->whereUuid('shop')->middleware($write)->name('api.v1.platform.marketplace.shops.verification');
    });

    // Marketplace listing oversight (Phase 23): restrict / lift only - restricting is hiding; nothing is deleted
    Route::prefix('marketplace/listings')->group(function () use ($write) {
        Route::get('/', [PlatformMarketplaceListingController::class, 'index'])->name('api.v1.platform.marketplace.listings.index');
        Route::get('/{listing}', [PlatformMarketplaceListingController::class, 'show'])->whereUuid('listing')->name('api.v1.platform.marketplace.listings.show');
        Route::post('/{listing}/restrict', [PlatformMarketplaceListingController::class, 'restrict'])->whereUuid('listing')->middleware($write)->name('api.v1.platform.marketplace.listings.restrict');
        Route::post('/{listing}/lift-restriction', [PlatformMarketplaceListingController::class, 'lift'])->whereUuid('listing')->middleware($write)->name('api.v1.platform.marketplace.listings.lift');
        Route::get('/{listing}/images/{image}/file', [PlatformMarketplaceListingController::class, 'image'])->whereUuid(['listing', 'image'])->name('api.v1.platform.marketplace.listings.images.file');
    });

    // Platform work templates
    Route::get('/work-templates', [PlatformTemplateController::class, 'index'])->name('api.v1.platform.templates.index');
    Route::get('/work-templates/{template}', [PlatformTemplateController::class, 'show'])->whereUuid('template')->name('api.v1.platform.templates.show');
    Route::post('/work-templates', [PlatformTemplateController::class, 'store'])->middleware($write)->name('api.v1.platform.templates.store');
    Route::patch('/work-templates/{template}', [PlatformTemplateController::class, 'update'])->whereUuid('template')->middleware($write)->name('api.v1.platform.templates.update');
    Route::post('/work-templates/{template}/publish', [PlatformTemplateController::class, 'publish'])->whereUuid('template')->middleware($write)->name('api.v1.platform.templates.publish');
    Route::post('/work-templates/{template}/archive', [PlatformTemplateController::class, 'archive'])->whereUuid('template')->middleware($write)->name('api.v1.platform.templates.archive');

    // Settings and feature flags
    Route::get('/settings', [PlatformConfigController::class, 'settings'])->name('api.v1.platform.settings.index');
    Route::put('/settings/{key}', [PlatformConfigController::class, 'putSetting'])->middleware($write)->name('api.v1.platform.settings.put');
    Route::get('/feature-flags', [PlatformConfigController::class, 'flags'])->name('api.v1.platform.flags.index');
    Route::post('/feature-flags', [PlatformConfigController::class, 'storeFlag'])->middleware($write)->name('api.v1.platform.flags.store');
    Route::patch('/feature-flags/{key}', [PlatformConfigController::class, 'updateFlag'])->middleware($write)->name('api.v1.platform.flags.update');

    // Support: users, farms, audit
    Route::get('/users', [PlatformSupportController::class, 'users'])->name('api.v1.platform.users.index');
    Route::get('/users/{user}', [PlatformSupportController::class, 'user'])->whereUuid('user')->name('api.v1.platform.users.show');
    Route::post('/users/{user}/suspend', [PlatformSupportController::class, 'suspend'])->whereUuid('user')->middleware($write)->name('api.v1.platform.users.suspend');
    Route::post('/users/{user}/restore', [PlatformSupportController::class, 'restore'])->whereUuid('user')->middleware($write)->name('api.v1.platform.users.restore');
    Route::get('/farms', [PlatformSupportController::class, 'farms'])->name('api.v1.platform.farms.index');
    Route::get('/farms/{farm}', [PlatformSupportController::class, 'farm'])->whereUuid('farm')->name('api.v1.platform.farms.show');
    Route::post('/farms/{farm}/subscription/plan', [PlatformSupportController::class, 'changePlan'])->whereUuid('farm')->middleware($write)->name('api.v1.platform.farms.plan');
    Route::get('/audit-logs', [PlatformSupportController::class, 'audit'])->name('api.v1.platform.audit');
});
