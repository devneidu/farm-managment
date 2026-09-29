<?php

namespace App\Support\Api\Docs;

use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApiTypes;

/**
 * OpenAPI description of the project error envelope produced by ApiExceptionRenderer.
 */
class ApiErrorResponse
{
    /** @param  string[]  $codes  machine-readable `code` values this response can carry */
    public static function make(int $status, string $description, array $codes = []): Response
    {
        $code = (new OpenApiTypes\StringType)->setDescription('Machine-readable error code.');

        if ($codes) {
            $code->enum(array_values(array_unique($codes)));
        }

        $body = (new OpenApiTypes\ObjectType)
            ->addProperty('message', (new OpenApiTypes\StringType)->setDescription('Human-readable summary.'))
            ->addProperty('code', $code)
            ->addProperty('request_id', (new OpenApiTypes\StringType)->setDescription('Echo of the X-Request-Id header, for support.'))
            ->addProperty('details', (new OpenApiTypes\ObjectType)->setDescription('Optional structured context. Entitlement errors (feature_not_available, subscription_inactive, plan_limit_reached) carry entitlement_key and, for limits, limit, usage and remaining.'))
            ->setRequired(['message', 'code', 'request_id']);

        return Response::make($status)
            ->setDescription($description)
            ->setContent('application/json', Schema::fromType($body));
    }
}
