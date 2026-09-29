<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Resources\AuthStateResource;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait RespondsWithAuthState
{
    protected function authState(User $user, ?string $message = null, int $status = 200, array $meta = []): JsonResponse
    {
        return ApiResponse::success(
            (new AuthStateResource($user))->resolve(app(Request::class)),
            $meta,
            $message,
            $status,
        );
    }
}
