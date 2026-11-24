<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\TokenAuthenticator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    /**
     * List my signed-in devices
     *
     * The caller's live (unexpired) mobile tokens, newest first: `id`, `device_name`, `created_at`, `last_used_at`, `expires_at`,
     * `is_current` (true for the token used on this request; always false from a cookie session). Browser sessions are not listed.
     *
     * @response array{data: list<array{id: int, device_name: string, created_at: string, last_used_at: string|null, expires_at: string|null, is_current: bool}>, meta: object, message: null}
     */
    public function index(Request $request, TokenAuthenticator $tokens): JsonResponse
    {
        $user = $request->user();
        $currentId = $tokens->current($user)?->getKey();

        return ApiResponse::success($tokens->devices($user)->map(fn ($t) => [
            'id' => $t->getKey(),
            'device_name' => $t->name,
            'created_at' => $t->created_at?->toIso8601String(),
            'last_used_at' => $t->last_used_at?->toIso8601String(),
            'expires_at' => $t->expires_at?->toIso8601String(),
            'is_current' => $t->getKey() === $currentId,
        ])->values()->all());
    }

    /**
     * Revoke one device
     *
     * Deletes one of the caller's own tokens (revoking the current one signs this device out). `404` for an unknown id or another
     * user's token - never a leak.
     *
     * @response array{data: null, meta: object, message: string}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $request->user()->tokens()->whereKey($id)->firstOrFail()->delete();

        return ApiResponse::success(null, message: 'Device signed out.');
    }

    /**
     * Log out of all devices
     *
     * Revokes every token of the account (including the one used for this call) and every browser session except the
     * cookie session making this call, if any.
     *
     * @response array{data: null, meta: object, message: string}
     */
    public function logoutAll(Request $request, TokenAuthenticator $tokens): JsonResponse
    {
        $tokens->revokeAll($request->user(), $request->hasSession() ? $request->session()->getId() : null);

        return ApiResponse::success(null, message: 'Signed out of all devices.');
    }
}
