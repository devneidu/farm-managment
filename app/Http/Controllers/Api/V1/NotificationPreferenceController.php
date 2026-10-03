<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Farm\UpdateNotificationPreferencesRequest;
use App\Http\Requests\Notifications\PatchNotificationPreferencesRequest;
use App\Services\Notifications\NotificationCatalogue;
use App\Support\Access\FarmContext;
use App\Support\Access\NotificationPreferences;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class NotificationPreferenceController extends Controller
{
    /**
     * Get my notification channel switches
     *
     * YOUR channel switches for the current farm (defaults: all on). Kept for compatibility; `GET /notification-preferences` returns the same
     * channels plus the per-type switches. Any active member may read/update their own.
     *
     * @response array{data: array{channels: array{in_app: bool, email: bool}}, meta: object, message: string|null}
     */
    public function show(FarmContext $ctx): JsonResponse
    {
        return ApiResponse::success(NotificationPreferences::resolve($ctx->membership->notification_preferences));
    }

    /**
     * Update my notification channel switches
     *
     * Body: `{ "channels": { "in_app": true, "email": false } }`. Omitted channels keep their value (and per-type switches are untouched);
     * unknown channels are a `422`. Equivalent to `PATCH /notification-preferences` with only `channels`.
     *
     * @response array{data: array{channels: array{in_app: bool, email: bool}}, meta: object, message: string|null}
     */
    public function update(UpdateNotificationPreferencesRequest $request, FarmContext $ctx): JsonResponse
    {
        $stored = NotificationPreferences::merge($ctx->membership->notification_preferences, ['channels' => $request->validated('channels')]);
        $ctx->membership->forceFill(['notification_preferences' => $stored])->save();

        return ApiResponse::success(NotificationPreferences::resolve($stored), message: 'Preferences updated.');
    }

    /**
     * Get my notification preferences
     *
     * Any active farm member. YOUR channel switches (`in_app`, `email`) and a switch for every notification type you can receive on this farm.
     * Types depend on your permissions (a Farm Worker is never offered invoice or stock alerts they cannot see), everything defaults to on.
     * A channel or type that is off means no notification is created for it. WhatsApp is not a channel.
     *
     * @response array{data: array{channels: array{in_app: bool, email: bool}, types: array<int, array{code: string, label: string, description: string, enabled: bool}>}, meta: object, message: null}
     */
    public function current(FarmContext $ctx): JsonResponse
    {
        return ApiResponse::success($this->payload($ctx));
    }

    /**
     * Update my notification preferences
     *
     * Any active farm member. Body: `{ "channels": { "email": false }, "types": { "low_stock": false } }` - both optional, omitted values keep
     * their setting. A type code you cannot receive (or that does not exist) is a `422`.
     *
     * @response array{data: array{channels: array{in_app: bool, email: bool}, types: array<int, array{code: string, label: string, description: string, enabled: bool}>}, meta: object, message: string}
     */
    public function patch(PatchNotificationPreferencesRequest $request, FarmContext $ctx): JsonResponse
    {
        $ctx->membership->forceFill(['notification_preferences' => NotificationPreferences::merge($ctx->membership->notification_preferences, $request->validated())])->save();

        return ApiResponse::success($this->payload($ctx), message: 'Preferences updated.');
    }

    /** @return array{channels: array<string, bool>, types: list<array<string, mixed>>} */
    private function payload(FarmContext $ctx): array
    {
        $stored = $ctx->membership->fresh()->notification_preferences;
        $types = [];
        foreach (NotificationCatalogue::availableTo($ctx) as $code => $t) {
            $types[] = ['code' => $code, 'label' => $t['label'], 'description' => $t['description'], 'enabled' => NotificationPreferences::typeEnabled($stored, $code)];
        }

        return NotificationPreferences::resolve($stored) + ['types' => $types];
    }
}
