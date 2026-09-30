<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    private static ?array $cachedSpec = null;

    /** The export is slow and memory hungry; generate it once per run (routes do not change between tests). */
    private function spec(): array
    {
        return self::$cachedSpec ??= $this->generateSpec();
    }

    private function generateSpec(): array
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

    public function test_phase_2_endpoints_are_documented_with_permissions_and_errors(): void
    {
        $spec = $this->spec();

        foreach ([
            'get /farm', 'patch /farm', 'get /roles',
            'get /farm/members', 'get /farm/members/{membership}', 'patch /farm/members/{membership}', 'delete /farm/members/{membership}',
            'get /farm/invitations', 'post /farm/invitations', 'post /farm/invitations/{invitation}/resend', 'delete /farm/invitations/{invitation}',
            'post /invitations/accept', 'get /account', 'patch /account', 'put /account/password',
            'get /settings/notifications', 'put /settings/notifications',
        ] as $endpoint) {
            [$method, $path] = explode(' ', $endpoint);
            $this->assertArrayHasKey($method, $spec['paths'][$path] ?? [], "Missing {$endpoint} in OpenAPI spec");
        }

        $invite = $spec['paths']['/farm/invitations']['post'];
        $this->assertStringContainsString('team.invite', $invite['description']);
        foreach (['201', '403', '409', '422', '429'] as $status) {
            $this->assertArrayHasKey($status, $invite['responses'], "POST /farm/invitations missing {$status}");
        }
        $this->assertArrayHasKey('404', $spec['paths']['/farm/members/{membership}']['patch']['responses']);
        $this->assertArrayHasKey('409', $spec['paths']['/farm/members/{membership}']['delete']['responses']);
        $this->assertArrayHasKey('410', $spec['paths']['/invitations/accept']['post']['responses']);
    }

    public function test_phase_3_endpoints_are_documented_with_entitlement_errors(): void
    {
        $spec = $this->spec();

        foreach ([
            'get /public/plans', 'get /subscription', 'get /subscription/entitlements', 'get /subscription/usage',
            'post /subscription/cancel', 'post /subscription/resume',
        ] as $endpoint) {
            [$method, $path] = explode(' ', $endpoint);
            $this->assertArrayHasKey($method, $spec['paths'][$path] ?? [], "Missing {$endpoint} in OpenAPI spec");
        }

        $this->assertSame([], $spec['paths']['/public/plans']['get']['security']);
        $this->assertStringContainsString('subscription.manage', $spec['paths']['/subscription/cancel']['post']['description']);
        $this->assertStringContainsString('subscription.view', $spec['paths']['/subscription']['get']['description']);
        foreach (['403', '409'] as $status) {
            $this->assertArrayHasKey($status, $spec['paths']['/subscription/cancel']['post']['responses']);
        }
        $this->assertStringContainsString('plan_limit_reached', $spec['paths']['/farm/invitations']['post']['description']);
        $this->assertArrayHasKey('details', $spec['paths']['/subscription']['get']['responses']['403']['content']['application/json']['schema']['properties']);
    }

    public function test_phase_4_endpoints_are_documented_with_permissions_and_errors(): void
    {
        $spec = $this->spec();

        foreach ([
            'get /master/farm-operations', 'get /master/species', 'get /master/species/{species}/capabilities',
            'get /master/species/{species}/breeds', 'get /master/crops', 'get /master/crops/{crop}/varieties',
            'get /master/planting-reference', 'get /custom-breeds', 'post /custom-breeds', 'patch /custom-breeds/{breed}',
            'get /custom-varieties', 'post /custom-varieties', 'patch /custom-varieties/{variety}',
            'get /farm/operations', 'put /farm/operations',
        ] as $endpoint) {
            [$method, $path] = explode(' ', $endpoint);
            $this->assertArrayHasKey($method, $spec['paths'][$path] ?? [], "Missing {$endpoint} in OpenAPI spec");
        }

        $this->assertStringContainsString('master_data.view', $spec['paths']['/master/species']['get']['description']);
        $this->assertStringContainsString('master_data.manage', $spec['paths']['/custom-breeds']['post']['description']);
        $this->assertStringContainsString('farm.update', $spec['paths']['/farm/operations']['put']['description']);
        foreach (['201', '403', '409', '422', '429'] as $status) {
            $this->assertArrayHasKey($status, $spec['paths']['/custom-breeds']['post']['responses'], "POST /custom-breeds missing {$status}");
        }
        foreach (['403', '404', '409', '422'] as $status) {
            $this->assertArrayHasKey($status, $spec['paths']['/custom-breeds/{breed}']['patch']['responses'], "PATCH /custom-breeds/{breed} missing {$status}");
        }
        $this->assertArrayHasKey('404', $spec['paths']['/master/species/{species}/breeds']['get']['responses']);
        $this->assertArrayHasKey('401', $spec['paths']['/master/species']['get']['responses']);
    }

    public function test_phase_7_documents_cycle_endpoints_conditional_fields_and_resources(): void
    {
        $spec = $this->spec();
        foreach (['get /production-cycles', 'post /production-cycles', 'get /production-cycles/{cycle}', 'patch /production-cycles/{cycle}', 'post /production-cycles/{cycle}/close', 'post /production-cycles/{cycle}/reopen', 'get /production-cycles/{cycle}/summary', 'get /production-cycles/{cycle}/activity'] as $endpoint) {
            [$method, $path] = explode(' ', $endpoint);
            $this->assertArrayHasKey($method, $spec['paths'][$path] ?? []);
            $this->assertStringContainsString('production_cycle.', $spec['paths'][$path][$method]['description']);
            $this->assertArrayHasKey('401', $spec['paths'][$path][$method]['responses']);
            $this->assertArrayHasKey('403', $spec['paths'][$path][$method]['responses']);
        }
        $post = $spec['paths']['/production-cycles']['post'];
        foreach (['201', '404', '409', '419', '422', '429'] as $status) {
            $this->assertArrayHasKey($status, $post['responses']);
        }
        $this->assertStringContainsString('kind=crop requires', $post['description']);
        $this->assertStringContainsString('plan_limit_reached', $post['description']);
        $body = $spec['components']['schemas']['StoreCycleRequest'];
        foreach (['kind', 'name', 'operation_type_id', 'species_id', 'initial_population', 'crop_type_id', 'planting_material_type', 'planting_unit_type', 'initial_planting_units', 'planting_date', 'area'] as $field) {
            $this->assertArrayHasKey($field, $body['properties']);
        }
        foreach (['farm_id', 'current_population', 'material_quantity', 'status'] as $field) {
            $this->assertArrayNotHasKey($field, $body['properties']);
        }
        $resource = $spec['components']['schemas']['ProductionCycleResource']['properties'];
        $this->assertSame('integer', $resource['livestock']['properties']['initial_population']['type']);
        $this->assertSame('integer', $resource['crop']['properties']['initial_planting_units']['type']);
        $this->assertSame('array', $resource['crop']['properties']['area']['properties']['entered']['type']);
        $this->assertArrayHasKey('production_area', $resource);
        $this->assertArrayNotHasKey('delete', $spec['paths']['/production-cycles/{cycle}']);
    }

    public function test_phase_6_place_endpoints_document_contracts_and_errors(): void
    {
        $spec = $this->spec();
        $this->assertArrayHasKey('get', $spec['paths']['/master/location-types']);
        foreach (['locations', 'production-areas', 'storage-locations'] as $path) {
            $collection = $spec['paths']['/'.$path];
            $item = $spec['paths']['/'.$path.'/{place}'];
            $this->assertArrayHasKey('get', $collection);
            $this->assertArrayHasKey('post', $collection);
            $this->assertArrayHasKey('get', $item);
            $this->assertArrayHasKey('patch', $item);
            $this->assertArrayNotHasKey('delete', $item);
            $this->assertStringContainsString('location.view', $collection['get']['description']);
            $this->assertStringContainsString('location.manage', $collection['post']['description']);
            $this->assertStringContainsString('location.manage', $item['patch']['description']);
            foreach (['201', '401', '403', '404', '409', '419', '422', '429'] as $status) {
                $this->assertArrayHasKey($status, $collection['post']['responses']);
            }
            foreach (['200', '404', '409', '422'] as $status) {
                $this->assertArrayHasKey($status, $item['patch']['responses']);
            }
            $names = array_column($collection['get']['parameters'], 'name');
            foreach (['include_inactive', 'type', 'parent_id', 'top_level', 'search', 'page', 'per_page'] as $name) {
                $this->assertContains($name, $names);
            }
            $body = $collection['post']['requestBody']['content']['application/json']['schema'];
            if (isset($body['$ref'])) {
                $body = $spec['components']['schemas'][basename($body['$ref'])];
            }
            $this->assertSame(['name', 'type'], $body['required']);
            $this->assertArrayHasKey('parent_id', $body['properties']);
            $this->assertArrayHasKey('is_active', $body['properties']);
            $this->assertArrayHasKey('enum', $body['properties']['type']);
        }
        $resource = $spec['components']['schemas']['PlaceResource'];
        foreach (['id', 'kind', 'name', 'type', 'type_label', 'parent_id', 'path', 'path_label', 'depth', 'is_active'] as $key) {
            $this->assertArrayHasKey($key, $resource['properties']);
        }
    }

    public function test_phase_5_endpoints_are_documented_with_permissions_and_errors(): void
    {
        $spec = $this->spec();

        foreach ([
            'get /master/measurement-dimensions', 'get /master/units', 'get /settings/units', 'put /settings/units',
            'get /settings/package-conversions', 'post /settings/package-conversions',
            'patch /settings/package-conversions/{conversion}', 'post /measurements/normalize',
        ] as $endpoint) {
            [$method, $path] = explode(' ', $endpoint);
            $this->assertArrayHasKey($method, $spec['paths'][$path] ?? [], "Missing {$endpoint} in OpenAPI spec");
        }

        $this->assertStringContainsString('measurement.view', $spec['paths']['/settings/package-conversions']['get']['description']);
        $this->assertStringContainsString('measurement.manage', $spec['paths']['/settings/package-conversions']['post']['description']);
        $this->assertStringContainsString('measurement.manage', $spec['paths']['/settings/units']['put']['description']);
        $this->assertStringContainsString('dimension', $spec['paths']['/master/units']['get']['description']);
        $this->assertStringContainsString('incompatible_units', $spec['paths']['/measurements/normalize']['post']['description']);
        $this->assertStringContainsString('conversion_not_configured', $spec['paths']['/measurements/normalize']['post']['description']);
        foreach (['201', '403', '409', '422', '429'] as $status) {
            $this->assertArrayHasKey($status, $spec['paths']['/settings/package-conversions']['post']['responses'], "POST /settings/package-conversions missing {$status}");
        }
        $this->assertArrayHasKey('422', $spec['paths']['/master/units']['get']['responses']);
    }
}
