<?php

namespace App\Support\Api;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * HTTP exception carrying a machine-readable error `code` for the API error envelope.
 */
class ApiHttpException extends HttpException
{
    public function __construct(int $status, public readonly string $errorCode, string $message, array $headers = [])
    {
        parent::__construct($status, $message, null, $headers);
    }
}
