<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Farm\UpdateNotificationPreferencesRequest;
use App\Support\Access\FarmContext;
use App\Support\Access\NotificationPreferences;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class NotificationPreferenceController extends Controller
{
    /**
     * Get my notification preferences
     *
     * YOUR channel switches for the current farm (defaults: all on). Foundation only - the notification
     * engine that honours these ships later. Any active member may read/update their own.
     *
     * @response array{data: array{channels: array{in_app: bool, email: bool}}, meta: object, message: string|null}
     */
    public function show(FarmContext $ctx): JsonResponse
    {
        return ApiResponse::success(NotificationPreferences::resolve($ctx->membership->notification_preferences));
    }

    /**
     * Update my notification preferences
     *
     * Body: `{ "channels": { "in_app": true, "email": false } }`. Omitted channels keep their value; unknown
     * channels are a `422`.
     *
     * @response array{data: array{channels: array{in_app: bool, email: bool}}, meta: object, message: string|null}
     */
    public function update(UpdateNotificationPreferencesRequest $request, FarmContext $ctx): JsonResponse
    {
        $current = NotificationPreferences::resolve($ctx->membership->notification_preferences);
        $current['channels'] = array_merge($current['channels'], array_map('boolval', $request->validated('channels')));

        $ctx->membership->forceFill(['notification_preferences' => $current])->save();

        return ApiResponse::success($current, message: 'Preferences updated.');
    }
}
