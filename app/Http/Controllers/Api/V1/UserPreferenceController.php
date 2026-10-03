<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdatePreferencesRequest;
use App\Models\User;
use App\Services\Localization\LocaleCatalogue;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserPreferenceController extends Controller
{
    /**
     * Get my preferences
     *
     * Verified user (no farm required). `locale` is YOUR explicit UI language (`null` = not chosen); `effective_locale` is what the API is
     * using for you now (your choice, else `Accept-Language`, else the platform default). `farm_locale` and the farm's
     * currency/timezone are not changed by this: language only affects interface text. Units are a farm preference (`/measurements/unit-preferences`),
     * notifications are `/notification-preferences`.
     *
     * @response array{data: array{locale: string|null, effective_locale: string, default_locale: string, fallback_locale: string, farm_locale: string|null, available_locales: list<string>}, meta: object, message: string|null}
     */
    public function show(Request $request, LocaleCatalogue $locales): JsonResponse
    {
        return ApiResponse::success($this->payload($request->user(), $locales));
    }

    /**
     * Update my preferences
     *
     * Body: `{ "locale": "en" }`, or `{ "locale": null }` to clear the explicit choice. Only available languages are accepted
     * (`422` for registered-but-unreviewed ones such as `ha`, `yo`, `ig`, `pcm`, and for unknown codes). Omitted fields are unchanged. Never changes
     * stored farm records, money, quantities or timestamps.
     *
     * @response array{data: array{locale: string|null, effective_locale: string, default_locale: string, fallback_locale: string, farm_locale: string|null, available_locales: list<string>}, meta: object, message: string|null}
     */
    public function update(UpdatePreferencesRequest $request, LocaleCatalogue $locales): JsonResponse
    {
        $user = $request->user();
        if ($request->has('locale')) {
            $user->forceFill(['locale' => $request->validated('locale')])->save();
            app()->setLocale($user->locale
                ?? $locales->fromAcceptLanguage($request->header('Accept-Language'))
                ?? $locales->default());
        }

        return ApiResponse::success($this->payload($user, $locales), message: 'Preferences updated.');
    }

    /** @return array<string, mixed> */
    private function payload(User $user, LocaleCatalogue $locales): array
    {
        return [
            'locale' => $user->locale,
            'effective_locale' => app()->getLocale(),
            'default_locale' => $locales->default(),
            'fallback_locale' => $locales->fallback(),
            'farm_locale' => $user->currentFarm()?->locale,
            'available_locales' => $locales->enabledCodes(),
        ];
    }
}
