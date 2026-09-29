<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    public function test_openapi_spec_generates_and_documents_health_endpoint(): void
    {
        $path = sys_get_temp_dir().'/openapi-'.uniqid().'.json';

        $this->assertSame(0, Artisan::call('scramble:export', ['--path' => $path]));

        $spec = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        @unlink($path);

        $this->assertStringStartsWith('3.', $spec['openapi']);
        $this->assertStringEndsWith('/api/v1', $spec['servers'][0]['url']);
        $this->assertArrayHasKey('get', $spec['paths']['/health']);
        $this->assertArrayHasKey('200', $spec['paths']['/health']['get']['responses']);
    }
}
