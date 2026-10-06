<?php

namespace App\Support\Api;

/**
 * One spelling for "where do I send this?". Route metadata keeps its historical `path` (relative to /api/v1, or `endpoint` where
 * that was already absolute) and additionally exposes the same route as `path` (relative) and `url` (absolute, host-less), so a
 * client can consume every metadata route the same way.
 */
final class ApiRoute
{
    public const PREFIX = '/api/v1';

    /** Absolute, host-less URL of a route given relative to /api/v1 (idempotent). */
    public static function url(string $path): string
    {
        return str_starts_with($path, self::PREFIX.'/') ? $path : self::PREFIX.'/'.ltrim($path, '/');
    }

    /** Route relative to /api/v1 (idempotent). */
    public static function path(string $url): string
    {
        return str_starts_with($url, self::PREFIX.'/') ? substr($url, strlen(self::PREFIX)) : '/'.ltrim($url, '/');
    }

    /** Adds `url` to a route array that carries a relative `path`. */
    public static function withUrl(array $route): array
    {
        return isset($route['path']) ? $route + ['url' => self::url($route['path'])] : $route;
    }
}
