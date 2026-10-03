<?php

namespace App\Http\Middleware;

use App\Services\Localization\LocaleCatalogue;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the UI language for the request: the user's saved choice, else Accept-Language, else the platform default.
 * Only enabled locales are used. Locale never changes stored data, codes, money, quantities or timestamps.
 */
class SetRequestLocale
{
    public function __construct(private readonly LocaleCatalogue $locales) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');
        $locale = ($this->locales->isEnabled($user?->locale) ? $user->locale : null)
            ?? $this->locales->fromAcceptLanguage($request->header('Accept-Language'))
            ?? $this->locales->default();

        app()->setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);
        $vary = $response->headers->get('Vary');
        $response->headers->set('Vary', $vary ? $vary.', Accept-Language' : 'Accept-Language');

        return $response;
    }
}
