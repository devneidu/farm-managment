<?php

namespace App\Services\Auth\Google;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifies a Google Identity Services ID token locally: RS256 signature against Google's
 * published JWKS, plus issuer, audience (our client id) and expiry. No token is logged.
 */
class JwtGoogleIdentityVerifier implements GoogleIdentityVerifier
{
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    public function verify(string $credential): GoogleIdentity
    {
        $clientId = config('services.google.client_id');

        if (! $clientId) {
            throw new GoogleAuthUnavailableException('Google client id is not configured.');
        }

        $keys = JWK::parseKeySet($this->keySet(), 'RS256');

        try {
            JWT::$leeway = 60;
            $claims = (array) JWT::decode($credential, $keys);
        } catch (Throwable) {
            throw new InvalidGoogleCredentialException('Invalid Google credential.');
        }

        $audience = (array) ($claims['aud'] ?? []);

        if (! in_array($claims['iss'] ?? null, self::ISSUERS, true)
            || ! in_array($clientId, $audience, true)
            || empty($claims['sub'])) {
            throw new InvalidGoogleCredentialException('Invalid Google credential.');
        }

        return new GoogleIdentity(
            subject: (string) $claims['sub'],
            email: isset($claims['email']) ? mb_strtolower(trim((string) $claims['email'])) : null,
            emailVerified: filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            name: isset($claims['name']) ? (string) $claims['name'] : null,
        );
    }

    private function keySet(): array
    {
        try {
            return Cache::remember('google.jwks', 3600, fn () => Http::timeout(5)->get(self::JWKS_URL)->throw()->json());
        } catch (Throwable) {
            throw new GoogleAuthUnavailableException('Unable to fetch Google signing keys.');
        }
    }
}
