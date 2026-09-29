<?php

namespace App\Support\Api;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders every exception for /api/* as:
 * {"message": "...", "code": "...", "request_id": "...", "errors": {...}?}
 * `errors` is only present for validation failures. Internals are never exposed.
 */
class ApiExceptionRenderer
{
    private const HTTP_CODES = [
        401 => ['unauthenticated', 'Unauthenticated.'],
        403 => ['forbidden', 'You are not allowed to perform this action.'],
        404 => ['not_found', 'The requested resource was not found.'],
        405 => ['method_not_allowed', 'This HTTP method is not allowed for the requested URL.'],
        409 => ['conflict', 'The request conflicts with the current state of the resource.'],
        419 => ['session_expired', 'The session has expired.'],
        429 => ['too_many_requests', 'Too many requests. Please try again later.'],
    ];

    public static function shouldRender(Request $request, Throwable $e): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! self::shouldRender($request, $e)) {
            return null;
        }

        if ($e instanceof ValidationException) {
            return $this->respond($request, 422, 'validation_failed', $e->getMessage(), $e->errors());
        }

        if ($e instanceof AuthenticationException) {
            return $this->respond($request, 401, ...self::HTTP_CODES[401]);
        }

        if ($e instanceof AuthorizationException) {
            return $this->respond($request, 403, ...self::HTTP_CODES[403]);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            [$code, $message] = self::HTTP_CODES[$status]
                ?? [$status >= 500 ? 'server_error' : 'http_error', $status >= 500 ? 'Server error.' : 'The request could not be completed.'];

            return $this->respond($request, $status, $code, $message, headers: $e->getHeaders());
        }

        $message = config('app.debug') ? $e->getMessage() : 'Server error.';

        return $this->respond($request, 500, 'server_error', $message);
    }

    private function respond(Request $request, int $status, string $code, string $message, ?array $errors = null, array $headers = []): JsonResponse
    {
        $body = ['message' => $message, 'code' => $code, 'request_id' => $request->attributes->get('request_id')];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status, $headers);
    }
}
