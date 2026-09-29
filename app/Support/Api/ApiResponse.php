<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;

/**
 * Success envelope: {"data": ..., "meta": {}, "message": null}.
 * The error envelope lives in ApiExceptionRenderer.
 */
class ApiResponse
{
    public static function success(mixed $data = null, array $meta = [], ?string $message = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => (object) $meta,
            'message' => $message,
        ], $status);
    }
}
