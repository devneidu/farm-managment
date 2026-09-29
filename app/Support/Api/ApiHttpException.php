<?php

namespace App\Support\Api;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * HTTP exception carrying a machine-readable error `code` for the API error envelope,
 * and optional structured `details` (e.g. entitlement key / limit / usage) safe to expose to clients.
 */
class ApiHttpException extends HttpException
{
    public function __construct(int $status, public readonly string $errorCode, string $message, array $headers = [], public readonly array $details = [])
    {
        parent::__construct($status, $message, null, $headers);
    }
}
