<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_returns_success_envelope(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJsonStructure(['data' => ['status', 'service', 'version', 'timestamp'], 'meta', 'message'])
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('message', null);
    }

    public function test_request_id_is_generated_and_reused_when_valid(): void
    {
        $generated = $this->getJson('/api/v1/health')->headers->get('X-Request-Id');
        $this->assertNotEmpty($generated);

        $this->getJson('/api/v1/health', ['X-Request-Id' => 'frontend-req-12345'])
            ->assertHeader('X-Request-Id', 'frontend-req-12345');

        $this->getJson('/api/v1/health', ['X-Request-Id' => "bad id\nwith newline"])
            ->assertHeader('X-Request-Id');
    }

    public function test_cors_allows_configured_frontend_origin(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:5173']]);

        $this->withHeaders(['Origin' => 'http://localhost:5173'])->getJson('/api/v1/health')
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }

    public function test_cors_rejects_unlisted_origin(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:5173']]);

        $response = $this->withHeaders(['Origin' => 'http://evil.example'])->getJson('/api/v1/health');

        $this->assertNotSame('http://evil.example', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }
}
