<?php

namespace Tests\Feature\Api;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ApiErrorFormatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::prefix('api/v1/_test')->group(function () {
            Route::post('validate', fn (Request $r) => $r->validate(['name' => 'required|string']));
            Route::get('unauthenticated', fn () => throw new AuthenticationException);
            Route::get('forbidden', fn () => throw new AuthorizationException('secret policy detail'));
            Route::get('boom', fn () => throw new RuntimeException('SQLSTATE[42S02] secret internals'));
        });
    }

    public function test_unknown_api_route_returns_json_404_even_without_accept_header(): void
    {
        $response = $this->get('/api/v1/does-not-exist');

        $response->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('code', 'not_found')
            ->assertJsonStructure(['message', 'code', 'request_id'])
            ->assertJsonMissingPath('errors');
        $this->assertSame($response->headers->get('X-Request-Id'), $response->json('request_id'));
    }

    public function test_wrong_method_returns_json_405(): void
    {
        $this->postJson('/api/v1/health')->assertStatus(405)->assertJsonPath('code', 'method_not_allowed');
    }

    public function test_validation_errors_use_agreed_format(): void
    {
        $this->postJson('/api/v1/_test/validate', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['message', 'code', 'request_id', 'errors' => ['name']]);
    }

    public function test_authentication_and_authorization_errors(): void
    {
        $this->getJson('/api/v1/_test/unauthenticated')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');

        $this->getJson('/api/v1/_test/forbidden')->assertStatus(403)
            ->assertJsonPath('code', 'forbidden')
            ->assertJsonMissing(['message' => 'secret policy detail']);
    }

    public function test_unexpected_errors_do_not_leak_internals_in_production(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/api/v1/_test/boom');

        $response->assertStatus(500)->assertJsonPath('code', 'server_error')->assertJsonPath('message', 'Server error.');
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('trace', $response->getContent());
    }
}
