<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    private function spec(): array
    {
        $path = sys_get_temp_dir().'/openapi-'.uniqid().'.json';

        $this->assertSame(0, Artisan::call('scramble:export', ['--path' => $path]));

        $spec = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        @unlink($path);

        return $spec;
    }

    public function test_openapi_spec_generates_and_documents_health_endpoint(): void
    {
        $spec = $this->spec();

        $this->assertStringStartsWith('3.', $spec['openapi']);
        $this->assertStringEndsWith('/api/v1', $spec['servers'][0]['url']);
        $this->assertArrayHasKey('get', $spec['paths']['/health']);
        $this->assertArrayHasKey('200', $spec['paths']['/health']['get']['responses']);
    }

    public function test_phase_1_endpoints_are_documented_with_security_and_errors(): void
    {
        $spec = $this->spec();

        foreach ([
            'get /auth/csrf-cookie', 'post /auth/register', 'post /auth/login', 'post /auth/logout', 'get /auth/me',
            'post /auth/email/verify', 'post /auth/email/resend', 'post /auth/google',
            'post /auth/password/forgot', 'post /auth/password/verify-otp', 'post /auth/password/reset',
            'post /onboarding/farm',
        ] as $endpoint) {
            [$method, $path] = explode(' ', $endpoint);
            $this->assertArrayHasKey($method, $spec['paths'][$path] ?? [], "Missing {$endpoint} in OpenAPI spec");
        }

        $this->assertArrayHasKey('apiKey', $spec['components']['securitySchemes']);
        $this->assertSame('cookie', $spec['components']['securitySchemes']['apiKey']['in']);

        // Public vs authenticated
        $this->assertSame([], $spec['paths']['/auth/login']['post']['security']);
        $this->assertArrayNotHasKey('security', $spec['paths']['/auth/me']['get']);

        // Error responses are documented with the project error envelope
        $me = $spec['paths']['/auth/me']['get']['responses'];
        $this->assertArrayHasKey('401', $me);
        $this->assertArrayHasKey('403', $spec['paths']['/onboarding/farm']['post']['responses']);
        $this->assertArrayHasKey('409', $spec['paths']['/onboarding/farm']['post']['responses']);
        $this->assertArrayHasKey('422', $spec['paths']['/auth/register']['post']['responses']);
        $this->assertArrayHasKey('429', $spec['paths']['/auth/login']['post']['responses']);
        $this->assertArrayHasKey('201', $spec['paths']['/auth/register']['post']['responses']);
    }
}
