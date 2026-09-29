<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /**
     * API health check
     *
     * Public liveness check for the API. It does not touch the database, so it is
     * safe for uptime monitors and for the frontend to verify connectivity.
     *
     * @unauthenticated
     *
     * @response array{data: array{status: 'ok', service: string, version: string, timestamp: string}, meta: object, message: null}
     */
    public function __invoke(): JsonResponse
    {
        return ApiResponse::success([
            'status' => 'ok',
            'service' => config('app.name'),
            'version' => config('scramble.info.version'),
            'timestamp' => now()->utc()->toIso8601String(),
        ]);
    }
}
