<?php

namespace Tests\Feature\Auth;

use App\Services\Auth\Google\GoogleAuthUnavailableException;
use App\Services\Auth\Google\InvalidGoogleCredentialException;
use App\Services\Auth\Google\JwtGoogleIdentityVerifier;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Exercises the real token-verification logic against a locally generated key pair;
 * Google's JWKS endpoint is faked, so no external call is made.
 */
class JwtGoogleIdentityVerifierTest extends TestCase
{
    private $privateKey;

    private string $jwk;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => 'client-123']);
        Cache::flush();

        $this->privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = openssl_pkey_get_details($this->privateKey);
        $b64 = fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

        Http::fake(['www.googleapis.com/*' => Http::response(['keys' => [[
            'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'test-kid',
            'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e']),
        ]]])]);
    }

    private function token(array $overrides = [], $key = null): string
    {
        $claims = array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => 'client-123',
            'sub' => 'sub-1',
            'email' => 'Farmer@Gmail.com',
            'email_verified' => true,
            'name' => 'Farmer',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $overrides);

        return JWT::encode($claims, $key ?? $this->privateKey, 'RS256', 'test-kid');
    }

    private function verify(string $token)
    {
        return (new JwtGoogleIdentityVerifier)->verify($token);
    }

    public function test_valid_token_yields_normalised_identity(): void
    {
        $identity = $this->verify($this->token());

        $this->assertSame('sub-1', $identity->subject);
        $this->assertSame('farmer@gmail.com', $identity->email);
        $this->assertTrue($identity->emailVerified);
    }

    public function test_wrong_audience_expired_wrong_issuer_and_bad_signature_are_rejected(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        foreach ([
            $this->token(['aud' => 'someone-elses-client']),
            $this->token(['exp' => time() - 3600, 'iat' => time() - 7200]),
            $this->token(['iss' => 'https://evil.example']),
            $this->token([], $other),
            'not.a.jwt',
        ] as $bad) {
            try {
                $this->verify($bad);
                $this->fail('Expected the credential to be rejected.');
            } catch (InvalidGoogleCredentialException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_unconfigured_client_id_is_reported_as_unavailable(): void
    {
        config(['services.google.client_id' => null]);

        $this->expectException(GoogleAuthUnavailableException::class);
        $this->verify($this->token());
    }
}
