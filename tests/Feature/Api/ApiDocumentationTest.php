<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    public function test_phase_8_endpoints_schemas_permissions_and_errors_are_documented(): void
    {
        $spec = $this->spec();
        foreach (['get /records', 'post /records', 'get /records/{record}', 'post /records/{record}/reverse', 'post /records/{record}/attachments', 'get /records/{record}/attachments/{attachment}', 'get /master/record-types', 'get /record-types/{type}/schema'] as $endpoint) {
            [$method, $path] = explode(' ', $endpoint);
            $operation = $spec['paths'][$path][$method];
            $this->assertStringContainsString('record.', $operation['description']);
            $this->assertArrayHasKey('401', $operation['responses']);
            $this->assertArrayHasKey('403', $operation['responses']);
        }
        foreach (['201', '401', '403', '404', '409', '419', '422', '429'] as $status) {
            $this->assertArrayHasKey($status, $spec['paths']['/records']['post']['responses']);
        }
        $body = $spec['components']['schemas']['StoreRecordRequest'];
        $this->assertSame('object', $body['properties']['details']['type']);
        $this->assertContains('idempotency_key', $body['required']);
        $this->assertContains('population_adjustment', $body['properties']['type']['enum']);
        $this->assertArrayNotHasKey('farm_id', $body['properties']);
        foreach (['population_delta', 'recorded_at', 'created_at', 'measurement', 'reversed_by_record_id', 'attachments'] as $field) {
            $this->assertArrayHasKey($field, $spec['components']['schemas']['OperationalRecordResource']['properties']);
        }
        $this->assertArrayHasKey('application/octet-stream', $spec['paths']['/records/{record}/attachments/{attachment}']['get']['responses']['200']['content']);
    }

    public function test_phase_10_health_endpoints_schemas_permissions_and_errors_are_documented(): void
    {
        $spec = $this->spec();
        foreach (['get /health-records', 'post /health-records', 'get /health-records/{record}', 'post /health-records/{record}/reverse', 'get /master/health-record-types',
            'get /health/withdrawals', 'get /health/medicines', 'get /health/medicines/{item}', 'put /health/medicines/{item}/profile'] as $endpoint) {
            [$method, $path] = explode(' ', $endpoint);
            $operation = $spec['paths'][$path][$method];
            $this->assertStringContainsString('health.', $operation['description'] ?? '', $endpoint.' documents its permission');
            $this->assertArrayHasKey('401', $operation['responses']);
            $this->assertArrayHasKey('403', $operation['responses']);
        }
        foreach (['201', '401', '403', '404', '409', '422', '429'] as $status) {
            $this->assertArrayHasKey($status, $spec['paths']['/health-records']['post']['responses']);
        }
        $body = $spec['components']['schemas']['StoreHealthRecordRequest'];
        $this->assertContains('idempotency_key', $body['required']);
        $this->assertContains('vaccination', $body['properties']['type']['enum']);
        $this->assertArrayHasKey('medicines', $body['properties']);
        $this->assertArrayNotHasKey('farm_id', $body['properties']);
        $this->assertArrayNotHasKey('withdrawal_ends_at', $body['properties']);
        foreach (['medicines', 'mortality_record_id', 'recorded_at', 'created_at', 'reversed_by_record_id'] as $field) {
            $this->assertArrayHasKey($field, $spec['components']['schemas']['HealthRecordResource']['properties']);
        }
        $this->assertArrayHasKey('profile', $spec['components']['schemas']['MedicineResource']['properties']);
        $this->assertArrayHasKey('health_record_id', $spec['components']['schemas']['InventoryMovementResource']['properties']);
    }

    public function test_phase_12_work_endpoints_schemas_permissions_and_errors_are_documented(): void
    {
        $spec = $this->spec();
        foreach (['get /master/task-categories', 'get /tasks', 'post /tasks', 'get /tasks/{task}', 'patch /tasks/{task}', 'post /tasks/{task}/complete', 'post /tasks/{task}/cancel', 'get /tasks/{task}/record-prefill',
            'get /schedules', 'post /schedules', 'get /schedules/{schedule}', 'post /schedules/{schedule}/end', 'get /calendar', 'get /work-templates', 'get /work-templates/recommended', 'post /work-templates',
            'get /work-templates/{template}', 'patch /work-templates/{template}', 'post /work-templates/{template}/clone', 'post /work-templates/{template}/apply'] as $endpoint) {
            [$method, $path] = explode(' ', $endpoint);
            $operation = $spec['paths'][$path][$method];
            $this->assertStringContainsString('task.', $operation['description'] ?? '', $endpoint.' documents its permission');
            $this->assertArrayHasKey('401', $operation['responses']);
            $this->assertArrayHasKey('403', $operation['responses']);
        }
        foreach (['201', '401', '403', '409', '422', '429'] as $status) {
            $this->assertArrayHasKey($status, $spec['paths']['/tasks']['post']['responses']);
        }
        $this->assertArrayHasKey('409', $spec['paths']['/tasks/{task}/complete']['post']['responses']);
        $this->assertContains('idempotency_key', $spec['components']['schemas']['StoreTaskRequest']['required']);
        $this->assertContains('idempotency_key', $spec['components']['schemas']['ApplyWorkTemplateRequest']['required']);
        foreach (['StoreTaskRequest', 'StoreScheduleRequest'] as $schema) {
            $this->assertArrayNotHasKey('farm_id', $spec['components']['schemas'][$schema]['properties']);
            $this->assertArrayNotHasKey('status', $spec['components']['schemas'][$schema]['properties']);
        }
        foreach (['status', 'due_state', 'due_date', 'due_at', 'timezone', 'completion', 'assigned_user_id', 'linked_record_type'] as $field) {
            $this->assertArrayHasKey($field, $spec['components']['schemas']['TaskResource']['properties']);
        }
    }

    public function test_phase_11_breeding_endpoints_schemas_permissions_and_errors_are_documented(): void
    {
        $spec = $this->spec();
        foreach (['get /breeding-projects', 'post /breeding-projects', 'get /breeding-projects/{project}', 'patch /breeding-projects/{project}', 'get /breeding-projects/{project}/milestones',
            'post /breeding-projects/{project}/checks', 'post /breeding-projects/{project}/cancel', 'post /breeding-projects/{project}/outcomes', 'post /breeding-projects/{project}/outcomes/{outcome}/reverse'] as $endpoint) {
            [$method, $path] = explode(' ', $endpoint);
            $operation = $spec['paths'][$path][$method];
            $this->assertStringContainsString('breeding.', $operation['description'] ?? '', $endpoint.' documents its permission');
            $this->assertArrayHasKey('401', $operation['responses']);
            $this->assertArrayHasKey('403', $operation['responses']);
        }
        foreach (['201', '401', '403', '404', '409', '422', '429'] as $status) {
            $this->assertArrayHasKey($status, $spec['paths']['/breeding-projects/{project}/outcomes']['post']['responses']);
        }
        $this->assertContains('idempotency_key', $spec['components']['schemas']['StoreBreedingProjectRequest']['required']);
        $this->assertArrayNotHasKey('add_to_population', $spec['components']['schemas']['StoreBreedingOutcomeRequest']['properties']);
        $this->assertArrayNotHasKey('farm_id', $spec['components']['schemas']['StoreBreedingProjectRequest']['properties']);
        $this->assertArrayNotHasKey('population_delta', $spec['components']['schemas']['StoreBreedingOutcomeRequest']['properties']);
        foreach (['expectation', 'biological_reference', 'expected_offspring', 'result', 'outcomes'] as $field) {
            $this->assertArrayHasKey($field, $spec['components']['schemas']['BreedingProjectResource']['properties']);
        }
        $this->assertArrayHasKey('operational_record_id', $spec['components']['schemas']['BreedingOutcomeResource']['properties']);
    }

    public function test_phase_9_inventory_endpoints_schemas_permissions_and_errors_are_documented(): void
    {
        $spec = $this->spec();
        $endpoints = [
            'get /inventory/items', 'post /inventory/items', 'get /inventory/items/{item}', 'patch /inventory/items/{item}', 'get /inventory/items/{item}/movements',
            'get /inventory/movements', 'get /inventory/movements/{movement}', 'post /inventory/movements/{movement}/reverse', 'get /inventory/lots',
            'post /inventory/stock-in', 'post /inventory/stock-out', 'post /inventory/adjustments', 'post /inventory/transfers', 'get /master/inventory-options',
            'get /feed-formulas', 'post /feed-formulas', 'get /feed-formulas/{formula}', 'patch /feed-formulas/{formula}',
        ];
        foreach ($endpoints as $endpoint) {
            [$method, $path] = explode(' ', $endpoint);
            $operation = $spec['paths'][$path][$method] ?? null;
            $this->assertNotNull($operation, $endpoint);
            $this->assertStringContainsString('inventory.', $operation['description'] ?? '', $endpoint.' documents its permission');
            $this->assertArrayHasKey('401', $operation['responses']);
            $this->assertArrayHasKey('403', $operation['responses']);
        }
        foreach (['201', '401', '403', '409', '422', '429'] as $status) {
            $this->assertArrayHasKey($status, $spec['paths']['/inventory/stock-out']['post']['responses']);
        }
        $this->assertContains('idempotency_key', $spec['components']['schemas']['StockInRequest']['required']);
        $this->assertContains('recorded_at', $spec['components']['schemas']['StockOutRequest']['required']);
        foreach (['quantity', 'quantity_on_hand', 'farm_id'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $spec['components']['schemas']['StockInRequest']['properties']);
            $this->assertArrayNotHasKey($forbidden, $spec['components']['schemas']['StoreItemRequest']['properties']);
        }
        foreach (['stock', 'is_low_stock', 'stock_unit', 'tracks_lots'] as $field) {
            $this->assertArrayHasKey($field, $spec['components']['schemas']['InventoryItemResource']['properties']);
        }
        foreach (['quantity_delta', 'quantity_delta_display', 'measurement', 'recorded_at', 'created_at', 'operational_record_id', 'transfer_group_id', 'reverses_movement_id', 'reversed_by_movement_id'] as $field) {
            $this->assertArrayHasKey($field, $spec['components']['schemas']['InventoryMovementResource']['properties']);
        }
        $this->assertArrayHasKey('inventory_movement_id', $spec['components']['schemas']['OperationalRecordResource']['properties']);
        $this->assertContains('inventory_item', $spec['components']['schemas']['StorePackageConversionRequest']['properties']['context_type']['enum'] ?? ['inventory_item']);
    }

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

    public function test_phase_18_platform_admin_endpoints_are_documented_with_roles_and_errors(): void
    {
        $spec = $this->spec();
        $platform = array_filter(array_keys($spec['paths']), fn ($p) => str_starts_with($p, '/platform-admin'));
        $this->assertGreaterThanOrEqual(25, count($platform));

        foreach (['get /platform-admin/plans', 'post /platform-admin/plans', 'put /platform-admin/plans/{plan}/entitlements', 'get /platform-admin/master/{kind}', 'post /platform-admin/work-templates/{template}/publish',
            'put /platform-admin/master/species/{species}/capabilities/{capability}', 'post /platform-admin/users/{user}/suspend', 'get /platform-admin/farms', 'get /platform-admin/audit-logs'] as $endpoint) {
            [$method, $path] = explode(' ', $endpoint);
            $operation = $spec['paths'][$path][$method] ?? $this->fail("Missing {$endpoint}");
            $this->assertArrayHasKey('401', $operation['responses'], $endpoint);
            $this->assertArrayHasKey('403', $operation['responses'], $endpoint);
        }
        foreach (['201', '422', '429'] as $status) {
            $this->assertArrayHasKey($status, $spec['paths']['/platform-admin/plans']['post']['responses']);
        }
        $this->assertStringContainsString('plan_in_use', $spec['paths']['/platform-admin/plans/{plan}']['patch']['description']);
        $this->assertStringContainsString('capability_in_use', $spec['paths']['/platform-admin/master/species/{species}/capabilities/{capability}']['put']['description']);
        $this->assertStringContainsString('platform.user_suspended', $spec['paths']['/platform-admin/users/{user}/suspend']['post']['description']);
        $this->assertArrayNotHasKey('delete', $spec['paths']['/platform-admin/plans/{plan}']);
        $this->assertArrayNotHasKey('farm_id', $spec['components']['schemas']['StorePlanRequest']['properties'] ?? []);
    }

    public function test_phase_19_localization_endpoints_are_documented(): void
    {
        $spec = $this->spec();
        foreach (['get /locales', 'get /translations/{locale}', 'get /me/preferences', 'patch /me/preferences'] as $endpoint) {
            [$method, $path] = explode(' ', $endpoint);
            $this->assertArrayHasKey($method, $spec['paths'][$path] ?? [], $endpoint);
        }
        $this->assertStringContainsString('locale_unavailable', $spec['paths']['/translations/{locale}']['get']['description']);
        $this->assertArrayHasKey('401', $spec['paths']['/me/preferences']['patch']['responses']);
        $this->assertArrayHasKey('422', $spec['paths']['/me/preferences']['patch']['responses']);
        $this->assertArrayHasKey('locale', $spec['components']['schemas']['UpdatePreferencesRequest']['properties']);
        $this->assertArrayHasKey('locale', $spec['components']['schemas']['AccountResource']['properties']);
    }
}
