<?php

namespace App\Support\Api\Docs;

use App\Support\Api\ApiHttpException;
use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Type\Literal\LiteralIntegerType;
use Dedoc\Scramble\Support\Type\Literal\LiteralStringType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;

/**
 * Documents `throw new ApiHttpException(status, code, message)` with the project error envelope.
 * Status and code are read from the literal constructor arguments in the controller/service code.
 */
class ApiHttpExceptionToResponseExtension extends ExceptionToResponseExtension
{
    public function shouldHandle(Type $type)
    {
        return $type instanceof ObjectType && $type->isInstanceOf(ApiHttpException::class);
    }

    public function toResponse(Type $type)
    {
        $args = array_values($type->original->arguments ?? []);
        $status = ($args[0] ?? null) instanceof LiteralIntegerType ? $args[0]->value : null;
        $code = ($args[1] ?? null) instanceof LiteralStringType ? $args[1]->value : null;

        if ($status === null) {
            return null;
        }

        return ApiErrorResponse::make($status, $code ? "Error: {$code}" : 'Error', $code ? [$code] : []);
    }
}
