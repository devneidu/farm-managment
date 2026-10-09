<?php

namespace Tests\Feature\Platform;

use App\Enums\FarmRole;
use App\Enums\Feature;
use App\Enums\Limit;
use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\Breed;
use App\Models\CropType;
use App\Models\FarmMembership;
use App\Models\FeatureFlag;
use App\Models\OperationType;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\ReferenceValue;
use App\Models\Species;
use App\Models\User;
use App\Models\WorkTemplate;
use App\Services\Platform\PlatformConfigService;
use App\Services\Subscription\EntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Reports\ReportsFixtures;
use Tests\Feature\Team\TeamTestCase;

class PlatformAdminTest extends TeamTestCase
{
    use ReportsFixtures;

    private const BASE = '/api/v1/platform-admin';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->platformUser(PlatformRole::Admin, 'Pat Admin');
        $this->signInAs($this->admin);
    }

    private function platformUser(PlatformRole $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        PlatformAdmin::create(['user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    private function url(string $path): string
    {
        return self::BASE.$path;
    }

    private function plan(string $slug): Plan
    {
        return Plan::where('slug', $slug)->firstOrFail();
    }

    private function platformAudit(string $action): array
    {
        return array_values(array_filter($this->getJson($this->url('/audit-logs?per_page=100'))->assertOk()->json('data'), fn ($e) => $e['action'] === $action));
    }

    private function templatePayload(array $extra = []): array
    {
        return array_replace([
            'code' => 'test_broiler_plan', 'name' => 'Test broiler plan', 'applies_to' => 'production_cycle', 'cycle_kind' => 'livestock',
            'items' => [['title' => 'Weigh a sample', 'category' => 'growth_monitoring', 'anchor' => 'cycle_start', 'offset_days' => 7]],
        ], $extra);
    }

    public function test_livestock_reference_lists_reuse_platform_administration_and_audit(): void
    {
        $list = 'livestock_purpose_chicken';
        $this->getJson($this->url('/master/reference-values?list='.$list))->assertOk()->assertJsonCount(5, 'data');
        $row = ReferenceValue::where('list', $list)->where('code', 'eggs')->firstOrFail();
        $this->patchJson($this->url('/master/reference-values/'.$row->id), ['name' => 'Egg production', 'is_active' => false])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.master_updated', 'resource_id' => $row->id]);
        $this->patchJson($this->url('/master/reference-values/'.$row->id), ['list' => 'livestock_purpose_cattle'])->assertUnprocessable();
        $this->postJson($this->url('/master/reference-values'), ['list' => 'livestock_purpose_nonexistent', 'code' => 'meat', 'name' => 'Meat'])->assertUnprocessable();
        $species = Species::where('code', 'fish')->firstOrFail();
        $this->patchJson($this->url('/master/species/'.$species->id), ['breed_field_label' => 'Species / Type'])->assertOk()->assertJsonPath('data.breed_field_label', 'Species / Type');
        $this->signInAs($this->platformUser(PlatformRole::Support, 'Reference support'));
        $this->getJson($this->url('/master/reference-values?list='.$list))->assertOk();
        $this->patchJson($this->url('/master/reference-values/'.$row->id), ['is_active' => true])->assertForbidden();
        $this->signInAs($this->owner);
        $data = $this->getJson('/api/v1/master/species/'.Species::where('code', 'chicken')->value('id').'/batch-reference')->assertOk()->json('data');
        $this->assertNotContains('eggs', array_column($data['purposes'], 'code'));
    }

    // ------------------------------------------------------------------ authorization

    public function test_platform_routes_need_a_platform_grant_and_never_a_farm_role(): void
    {
        $this->signInAs($this->owner);   // a farm Owner
        foreach (['/me', '/plans', '/users', '/farms', '/audit-logs', '/settings', '/work-templates'] as $path) {
            $this->getJson($this->url($path))->assertForbidden()->assertJsonPath('code', 'platform_admin_required');
        }
        $this->postJson($this->url('/plans'), ['slug' => 'x', 'name' => 'X'])->assertForbidden()->assertJsonPath('code', 'platform_admin_required');

        foreach ([FarmRole::Manager, FarmRole::FarmWorker] as $role) {
            $this->signInAs($this->member($role))->getJson($this->url('/plans'))->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->getJson($this->url('/plans'))->assertUnauthorized();
    }

    public function test_a_platform_admin_with_no_farm_works_and_gains_nothing_on_farm_routes(): void
    {
        $this->assertSame(0, $this->admin->memberships()->count());
        $this->getJson($this->url('/me'))->assertOk()->assertJsonPath('data.role', 'admin')->assertJsonPath('data.can_write', true)->assertJsonPath('data.user.id', $this->admin->id);
        $this->getJson($this->url('/farms'))->assertOk()->assertJsonPath('meta.total', 1);

        // The platform grant is not a farm membership: normal farm endpoints stay closed to a non-member.
        $this->getJson('/api/v1/farm')->assertStatus(403);
        $this->getJson('/api/v1/farm', ['X-Farm-Id' => $this->farm->id])->assertStatus(403);
        $this->getJson('/api/v1/audit', ['X-Farm-Id' => $this->farm->id])->assertStatus(403);
    }

    public function test_platform_role_is_independent_of_farm_membership_and_visible_in_the_auth_state(): void
    {
        $admin = $this->platformUser(PlatformRole::Admin, 'Both');
        FarmMembership::create(['farm_id' => $this->farm->id, 'user_id' => $admin->id, 'role' => FarmRole::FarmWorker->value]);
        $this->signInAs($admin);
        $this->getJson($this->url('/plans'))->assertOk();
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.platform_role', 'admin');
        // ...and on the farm they are only a Worker.
        $this->getJson('/api/v1/audit')->assertForbidden();

        $this->signInAs($this->owner)->getJson('/api/v1/auth/me')->assertJsonPath('data.user.platform_role', null);
    }

    public function test_the_support_role_reads_but_cannot_write(): void
    {
        $this->signInAs($this->platformUser(PlatformRole::Support, 'Sam Support'));
        $this->getJson($this->url('/me'))->assertOk()->assertJsonPath('data.role', 'support')->assertJsonPath('data.can_write', false);
        foreach (['/plans', '/master/species', '/work-templates', '/settings', '/feature-flags', '/users', '/farms', '/audit-logs'] as $path) {
            $this->getJson($this->url($path))->assertOk();
        }
        $this->postJson($this->url('/plans'), ['slug' => 'nope', 'name' => 'Nope'])->assertForbidden()->assertJsonPath('code', 'platform_write_forbidden');
        $this->patchJson($this->url('/plans/'.$this->plan('free')->id), ['name' => 'Hacked'])->assertForbidden();
        $this->postJson($this->url('/users/'.$this->owner->id.'/suspend'), ['reason' => 'because'])->assertForbidden();
        $this->putJson($this->url('/settings/announcement'), ['value' => 'x'])->assertForbidden();
        $this->assertSame('Free', $this->plan('free')->name);
    }

    public function test_a_suspended_or_unverified_platform_admin_is_locked_out(): void
    {
        $suspended = User::factory()->suspended()->create();
        PlatformAdmin::create(['user_id' => $suspended->id, 'role' => PlatformRole::Admin]);
        $this->signInAs($suspended)->getJson($this->url('/plans'))->assertForbidden()->assertJsonPath('code', 'account_suspended');

        $unverified = User::factory()->unverified()->create();
        PlatformAdmin::create(['user_id' => $unverified->id, 'role' => PlatformRole::Admin]);
        $this->signInAs($unverified)->getJson($this->url('/plans'))->assertForbidden();
    }

    public function test_grants_are_created_only_by_the_console_command_and_are_audited(): void
    {
        $user = User::factory()->create(['email' => 'ops@example.com']);
        $this->artisan('platform:grant-admin', ['email' => 'ops@example.com', '--role' => 'support'])->assertSuccessful();
        $this->assertSame(PlatformRole::Support, $user->fresh()->platformRole());
        $this->artisan('platform:grant-admin', ['email' => 'nobody@example.com'])->assertFailed();
        $this->artisan('platform:grant-admin', ['email' => 'ops@example.com', '--role' => 'root'])->assertFailed();

        $this->assertSame('support', AuditLog::where('action', 'platform.admin_granted')->firstOrFail()->changes['to']);
        $this->artisan('platform:revoke-admin', ['email' => 'ops@example.com'])->assertSuccessful();
        $this->assertNull($user->fresh()->platformRole());
        $this->assertTrue(AuditLog::where('action', 'platform.admin_revoked')->exists());

        // No API can mint or change a grant.
        $this->postJson($this->url('/users/'.$this->owner->id.'/grant'), ['role' => 'admin'])->assertNotFound();
        $this->patchJson($this->url('/users/'.$this->owner->id), ['platform_role' => 'admin'])->assertStatus(405);
    }

    // ------------------------------------------------------------------ plans / entitlements

    public function test_plan_catalogue_lists_everything_with_subscribers_and_is_paginated(): void
    {
        $this->onPlan('farm-pro');
        $plans = $this->getJson($this->url('/plans'))->assertOk()->json('data');
        $this->assertSame(['free', 'farm-pro', 'farm-business'], array_column($plans, 'slug'));
        $this->assertSame(1, collect($plans)->firstWhere('slug', 'farm-pro')['subscribers_count']);
        $pro = collect($plans)->firstWhere('slug', 'farm-pro');
        $this->assertTrue(collect($pro['features'])->firstWhere('key', 'advanced_reports')['enabled']);
        $this->assertContains('team_members', array_column($pro['limits'], 'key'));

        $this->getJson($this->url('/plans?per_page=1&page=2'))->assertOk()->assertJsonPath('data.0.slug', 'farm-pro')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 3);
        $this->getJson($this->url('/plans?q=business'))->assertJsonCount(1, 'data');
        $this->getJson($this->url('/plans?per_page=500'))->assertStatus(422);
        $this->getJson($this->url('/entitlements'))->assertOk()->assertJsonPath('data.features.0.key', 'advanced_reports');
    }

    public function test_a_new_plan_is_inactive_and_grants_nothing_until_configured_activated_and_used(): void
    {
        $created = $this->postJson($this->url('/plans'), ['slug' => 'farm-plus', 'name' => 'Farm Plus', 'sort_order' => 25])->assertCreated()
            ->assertJsonPath('data.is_active', false)->assertJsonPath('data.is_default', false)->json('data');
        $this->postJson($this->url('/plans'), ['slug' => 'farm-plus', 'name' => 'Dup'])->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->postJson($this->url('/plans'), ['slug' => 'Bad Slug', 'name' => 'Bad'])->assertStatus(422);
        $this->postJson($this->url('/plans'), ['slug' => 'sneaky', 'name' => 'S', 'is_active' => true, 'is_default' => true])->assertStatus(422);

        // Inactive plan: the public catalogue does not show it, and a farm cannot be moved onto it.
        $this->assertNotContains('farm-plus', array_column($this->getJson('/api/v1/public/plans')->json('data'), 'slug'));
        $this->postJson($this->url('/farms/'.$this->farm->id.'/subscription/plan'), ['plan_id' => $created['id'], 'reason' => 'trial'])->assertStatus(422)->assertJsonPath('code', 'plan_not_available');

        $this->putJson($this->url('/plans/'.$created['id'].'/prices'), ['prices' => [['interval' => 'monthly', 'amount_minor' => 750000], ['interval' => 'annual', 'amount_minor' => 7500000]]])->assertOk()->assertJsonCount(2, 'data.prices');
        $this->putJson($this->url('/plans/'.$created['id'].'/entitlements'), ['features' => ['advanced_reports' => true], 'limits' => ['team_members' => ['limit' => 8], 'active_cycles' => ['unlimited' => true]]])
            ->assertOk()->assertJsonPath('data.limits.0.key', 'team_members');
        $this->patchJson($this->url('/plans/'.$created['id']), ['is_active' => true, 'is_public' => true])->assertOk()->assertJsonPath('data.is_active', true);

        $public = collect($this->getJson('/api/v1/public/plans')->json('data'))->firstWhere('slug', 'farm-plus');
        $this->assertSame(750000, collect($public['prices'])->firstWhere('interval', 'monthly')['amount_minor']);

        // The Phase 3 resolver honours the new plan with no other change.
        $this->postJson($this->url('/farms/'.$this->farm->id.'/subscription/plan'), ['plan_id' => $created['id'], 'interval' => 'annual', 'reason' => 'Upgrade by phone'])->assertOk()->assertJsonPath('data.plan.slug', 'farm-plus');
        $set = app(EntitlementService::class)->for($this->farm->fresh());
        $this->assertSame('farm-plus', $set->plan->slug);
        $this->assertTrue($set->allows(Feature::AdvancedReports));
        $this->assertSame(8, $set->limit(Limit::TeamMembers)->value);
        $this->assertTrue($set->limit(Limit::ActiveCycles)->unlimited);
    }

    public function test_entitlement_edits_are_validated_take_effect_immediately_and_are_audited_with_before_after(): void
    {
        $free = $this->plan('free');
        $this->assertFalse(app(EntitlementService::class)->allows($this->farm, Feature::DataExport));

        $this->putJson($this->url('/plans/'.$free->id.'/entitlements'), [])->assertStatus(422);
        $this->putJson($this->url('/plans/'.$free->id.'/entitlements'), ['features' => ['made_up' => true]])->assertStatus(422)->assertJsonValidationErrors('features.made_up');
        $this->putJson($this->url('/plans/'.$free->id.'/entitlements'), ['limits' => ['team_members' => ['limit' => -1]]])->assertStatus(422);
        $this->putJson($this->url('/plans/'.$free->id.'/entitlements'), ['limits' => ['team_members' => ['limit' => 3, 'unlimited' => true]]])->assertStatus(422);
        $this->putJson($this->url('/plans/'.$free->id.'/entitlements'), ['limits' => ['mystery' => ['limit' => 3]]])->assertStatus(422);
        $this->assertSame(0, AuditLog::where('action', 'platform.plan_entitlements_updated')->count(), 'rejected edits leave no trace and no change');

        $this->putJson($this->url('/plans/'.$free->id.'/entitlements'), ['features' => ['data_export' => true], 'limits' => ['team_members' => ['limit' => 5]]])->assertOk();
        $this->assertTrue(app(EntitlementService::class)->allows($this->farm, Feature::DataExport));
        $this->signInAs($this->owner)->getJson('/api/v1/subscription/entitlements')->assertJsonPath('data.features.data_export', true)->assertJsonPath('data.limits.team_members.limit', 5);

        $this->signInAs($this->admin);
        $entry = $this->platformAudit('platform.plan_entitlements_updated')[0];
        $this->assertSame($this->admin->id, $entry['actor']['id']);
        $this->assertSame('plan', $entry['resource']['type']);
        $this->assertSame('free', $entry['resource']['label']);
        $this->assertFalse($entry['changes']['before']['features']['data_export']);
        $this->assertTrue($entry['changes']['after']['features']['data_export']);
        $this->assertSame(3, $entry['changes']['before']['limits']['team_members']);
        $this->assertSame(5, $entry['changes']['after']['limits']['team_members']);
    }

    public function test_lowering_a_limit_never_removes_existing_data(): void
    {
        $this->onPlan('farm-pro');
        $this->member(FarmRole::Manager);
        $this->member(FarmRole::FarmWorker);
        $this->putJson($this->url('/plans/'.$this->plan('farm-pro')->id.'/entitlements'), ['limits' => ['team_members' => ['limit' => 1]]])->assertOk();

        $this->assertSame(3, $this->farm->memberships()->where('status', 'active')->count(), 'existing members stay');
        $this->assertSame(0, app(EntitlementService::class)->remaining($this->farm, Limit::TeamMembers));
        $this->signInAs($this->owner)->postJson('/api/v1/farm/invitations', ['email' => 'new@example.com', 'role' => 'farm_worker'])->assertStatus(409)->assertJsonPath('code', 'plan_limit_reached');
    }

    public function test_default_plan_rules_and_plan_in_use_protection(): void
    {
        $free = $this->plan('free');
        $pro = $this->plan('farm-pro');
        $this->patchJson($this->url('/plans/'.$free->id), ['is_active' => false])->assertStatus(409)->assertJsonPath('code', 'default_plan_protected');
        $this->putJson($this->url('/plans/'.$free->id.'/prices'), ['prices' => [['interval' => 'monthly', 'amount_minor' => 100]]])->assertStatus(422)->assertJsonPath('code', 'default_plan_must_be_free');
        $this->patchJson($this->url('/plans/'.$free->id), ['slug' => 'renamed'])->assertStatus(422);

        $this->onPlan('farm-pro');
        $this->patchJson($this->url('/plans/'.$pro->id), ['is_active' => false])->assertStatus(409)->assertJsonPath('code', 'plan_in_use')->assertJsonPath('details.subscribers', 1);
        $this->assertTrue($pro->fresh()->is_active);

        // Moving the farm away makes the plan deactivatable, and reactivating is a reversible transition.
        $this->postJson($this->url('/farms/'.$this->farm->id.'/subscription/plan'), ['plan_id' => $free->id, 'reason' => 'Downgrade requested'])->assertOk();
        $this->patchJson($this->url('/plans/'.$pro->id), ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->patchJson($this->url('/plans/'.$pro->id), ['is_active' => true])->assertOk();

        // Default switching: must be active and free; idempotent; exactly one default at any time.
        $this->postJson($this->url('/plans/'.$pro->id.'/make-default'))->assertStatus(422)->assertJsonPath('code', 'default_plan_must_be_free');
        $this->postJson($this->url('/plans/'.$free->id.'/make-default'))->assertOk();
        $this->assertSame(0, $this->platformAuditCount('platform.plan_default_changed'), 'no-op does not audit');
        $starter = $this->postJson($this->url('/plans'), ['slug' => 'starter', 'name' => 'Starter'])->json('data');
        $this->postJson($this->url('/plans/'.$starter['id'].'/make-default'))->assertStatus(409)->assertJsonPath('code', 'plan_not_active');
        $this->patchJson($this->url('/plans/'.$starter['id']), ['is_active' => true])->assertOk();
        $this->postJson($this->url('/plans/'.$starter['id'].'/make-default'))->assertOk()->assertJsonPath('data.is_default', true);
        $this->assertSame(['starter'], Plan::where('is_default', true)->pluck('slug')->all());
        $this->assertSame(1, $this->platformAuditCount('platform.plan_default_changed'));
    }

    private function platformAuditCount(string $action): int
    {
        return AuditLog::whereNull('farm_id')->where('action', $action)->count();
    }

    public function test_prices_are_integer_kobo_upserted_and_audited(): void
    {
        $pro = $this->plan('farm-pro');
        $before = $pro->prices()->where('interval', 'monthly')->value('amount_minor');
        $this->putJson($this->url('/plans/'.$pro->id.'/prices'), ['prices' => [['interval' => 'monthly', 'amount_minor' => 999900]]])->assertOk();
        $this->assertSame(999900, $pro->prices()->where('interval', 'monthly')->value('amount_minor'));
        $this->assertSame(1, $pro->prices()->where('interval', 'monthly')->count(), 'upsert, not duplicate');
        foreach ([['amount_minor' => 12.5], ['amount_minor' => 0], ['amount_minor' => '1e3']] as $bad) {
            $this->putJson($this->url('/plans/'.$pro->id.'/prices'), ['prices' => [['interval' => 'monthly'] + $bad]])->assertStatus(422);
        }
        $this->putJson($this->url('/plans/'.$pro->id.'/prices'), ['prices' => [['interval' => 'weekly', 'amount_minor' => 5]]])->assertStatus(422);
        $entry = $this->platformAudit('platform.plan_prices_updated')[0];
        $this->assertSame($before, $entry['changes']['before']['monthly']['amount_minor']);
        $this->assertSame(999900, $entry['changes']['after']['monthly']['amount_minor']);
    }

    public function test_changing_a_farm_plan_uses_the_subscription_service_and_is_audited_in_both_places(): void
    {
        $pro = $this->plan('farm-pro');
        $this->postJson($this->url('/farms/'.$this->farm->id.'/subscription/plan'), ['plan_id' => $pro->id])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson($this->url('/farms/'.$this->farm->id.'/subscription/plan'), ['plan_id' => (string) Str::uuid(), 'reason' => 'abc'])->assertStatus(422);
        $this->postJson($this->url('/farms/'.$this->farm->id.'/subscription/plan'), ['plan_id' => $pro->id, 'interval' => 'monthly', 'reason' => 'Paid by bank transfer'])->assertOk()
            ->assertJsonPath('data.plan.slug', 'farm-pro')->assertJsonPath('data.billing_interval', 'monthly');
        $this->postJson($this->url('/farms/'.$this->farm->id.'/subscription/plan'), ['plan_id' => $pro->id, 'reason' => 'again'])->assertStatus(409)->assertJsonPath('code', 'plan_unchanged');

        $entry = $this->platformAudit('platform.farm_plan_changed')[0];
        $this->assertSame('farm', $entry['resource']['type']);
        $this->assertSame($this->farm->id, $entry['resource']['id']);
        $this->assertSame('Paid by bank transfer', $entry['changes']['reason']);
        $this->assertSame('free', $entry['changes']['before']['plan']);
        $this->assertSame('farm-pro', $entry['changes']['after']['plan']);

        // The farm's own audit trail shows the subscription event with the admin as actor.
        $this->signInAs($this->owner);
        $farmEntry = collect($this->getJson('/api/v1/audit?per_page=100')->json('data'))->firstWhere('action', 'subscription.plan_changed');
        $this->assertSame($this->admin->id, $farmEntry['actor']['id']);
        $this->assertCount(0, array_filter($this->getJson('/api/v1/audit?per_page=100')->json('data'), fn ($e) => str_starts_with($e['action'], 'platform.')), 'platform entries never reach a farm');
    }

    // ------------------------------------------------------------------ master data

    public function test_master_data_lists_include_inactive_filter_search_and_paginate(): void
    {
        $page = $this->getJson($this->url('/master/species?per_page=5'))->assertOk()->json();
        $this->assertCount(5, $page['data']);
        $this->assertGreaterThan(5, $page['meta']['total']);
        $chicken = Species::where('code', 'chicken')->firstOrFail();
        $this->getJson($this->url('/master/species?q=chick'))->assertJsonPath('data.0.code', 'chicken')->assertJsonPath('data.0.operation_type.code', 'poultry');
        $this->getJson($this->url('/master/operation-types?category=crop'))->assertJsonPath('data.0.tracking_model', 'planting_units');
        $this->getJson($this->url('/master/reference-values?list=planting_unit_type'))->assertOk()->assertJsonPath('data.0.list', 'planting_unit_type');
        $this->getJson($this->url('/master/breeds?parent_id='.$chicken->id))->assertOk();
        $this->getJson($this->url('/master/species/'.$chicken->id.'/capabilities'))->assertOk()->assertJsonCount(11, 'data');
        $this->getJson($this->url('/master/widgets'))->assertNotFound();

        $chicken->update(['is_active' => false]);
        $this->getJson($this->url('/master/species?is_active=0&q=chick'))->assertJsonPath('data.0.is_active', false);
        $this->signInAs($this->owner)->getJson('/api/v1/master/species')->assertOk();
        $this->assertNotContains('chicken', array_column($this->getJson('/api/v1/master/species')->json('data'), 'code'), 'inactive is hidden from farms');
    }

    public function test_master_records_are_created_with_permanent_identity_and_audited(): void
    {
        $poultry = OperationType::where('code', 'poultry')->firstOrFail();
        $crops = OperationType::where('code', 'crops')->firstOrFail();

        $species = $this->postJson($this->url('/master/species'), ['operation_type_id' => $poultry->id, 'code' => 'turkey_test', 'name' => 'Turkey', 'livestock_group' => 'poultry'])->assertCreated()
            ->assertJsonPath('data.operation_type.code', 'poultry')->json('data');
        $this->postJson($this->url('/master/species'), ['operation_type_id' => $poultry->id, 'code' => 'turkey_test', 'name' => 'Turkey 2'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson($this->url('/master/species'), ['operation_type_id' => $crops->id, 'code' => 'bad_species', 'name' => 'Bad'])->assertStatus(422)->assertJsonValidationErrors('operation_type_id');
        $this->postJson($this->url('/master/species'), ['operation_type_id' => $poultry->id, 'code' => 'Bad Code', 'name' => 'Bad'])->assertStatus(422);
        $this->postJson($this->url('/master/crop-types'), ['operation_type_id' => $poultry->id, 'code' => 'bad_crop', 'name' => 'Bad'])->assertStatus(422);
        $crop = $this->postJson($this->url('/master/crop-types'), ['operation_type_id' => $crops->id, 'code' => 'okra_test', 'name' => 'Okra'])->assertCreated()->json('data');

        $op = $this->postJson($this->url('/master/operation-types'), ['code' => 'apiary_test', 'name' => 'Apiary', 'category' => 'livestock'])->assertCreated()->assertJsonPath('data.tracking_model', 'population')->json('data');
        $this->postJson($this->url('/master/operation-types'), ['code' => 'x_test', 'name' => 'X', 'category' => 'crop', 'tracking_model' => 'population'])->assertStatus(422);

        $breed = $this->postJson($this->url('/master/breeds'), ['species_id' => $species['id'], 'name' => 'Bronze'])->assertCreated()->assertJsonPath('data.species.code', 'turkey_test')->json('data');
        $this->postJson($this->url('/master/breeds'), ['species_id' => $species['id'], 'name' => ' bronze '])->assertStatus(422);
        $this->assertNull(Breed::findOrFail($breed['id'])->farm_id, 'a platform breed is a system breed');
        $this->postJson($this->url('/master/varieties'), ['crop_type_id' => $crop['id'], 'name' => 'Clemson'])->assertCreated();
        $this->postJson($this->url('/master/reference-values'), ['list' => 'planting_unit_type', 'code' => 'mound_test', 'name' => 'Mound'])->assertCreated();
        $this->postJson($this->url('/master/reference-values'), ['list' => 'nonsense', 'code' => 'x', 'name' => 'X'])->assertStatus(422);

        $entry = $this->platformAudit('platform.master_created');
        $this->assertContains('species', array_column(array_column($entry, 'resource'), 'type'));
        $this->assertSame($this->admin->id, $entry[0]['actor']['id']);
        $this->assertNotNull($entry[0]['request_id']);
        $this->assertNotNull($op['id']);
    }

    public function test_identity_fields_cannot_change_and_nothing_can_be_deleted(): void
    {
        $chicken = Species::where('code', 'chicken')->firstOrFail();
        $cattleOp = OperationType::where('code', 'cattle')->firstOrFail();
        foreach (['code' => 'new_code', 'operation_type_id' => $cattleOp->id] as $field => $value) {
            $this->patchJson($this->url('/master/species/'.$chicken->id), [$field => $value])->assertStatus(422);
        }
        $this->patchJson($this->url('/master/operation-types/'.$cattleOp->id), ['category' => 'crop'])->assertStatus(422);
        $this->patchJson($this->url('/master/operation-types/'.$cattleOp->id), ['tracking_model' => 'planting_units'])->assertStatus(422);
        $this->assertSame('chicken', $chicken->fresh()->code);

        $this->patchJson($this->url('/master/species/'.$chicken->id), ['name' => 'Chicken (fowl)', 'sort_order' => 5])->assertOk()->assertJsonPath('data.name', 'Chicken (fowl)');
        $entry = $this->platformAudit('platform.master_updated')[0];
        $this->assertSame('Chicken', $entry['changes']['before']['name']);
        $this->assertSame('Chicken (fowl)', $entry['changes']['after']['name']);

        $this->deleteJson($this->url('/master/species/'.$chicken->id))->assertStatus(405);
        $this->deleteJson($this->url('/plans/'.$this->plan('free')->id))->assertStatus(405);
        $this->deleteJson($this->url('/feature-flags/x'))->assertStatus(405);
        $this->assertTrue(Species::whereKey($chicken->id)->exists());
    }

    public function test_deactivation_keeps_existing_farm_records_and_parent_child_rules_hold(): void
    {
        $this->bootClock();
        $this->signInAs($this->owner);
        $cycle = $this->layers(50);
        $this->signInAs($this->admin);
        $chicken = Species::where('code', 'chicken')->firstOrFail();
        $poultry = $chicken->operationType;

        $this->patchJson($this->url('/master/operation-types/'.$poultry->id), ['is_active' => false])->assertStatus(409)->assertJsonPath('code', 'has_active_children');
        $this->patchJson($this->url('/master/species/'.$chicken->id), ['is_active' => false])->assertOk();

        // The farm's cycle still reads and records against the deactivated species (nothing referenced was deleted).
        $this->signInAs($this->owner);
        $this->getJson('/api/v1/production-cycles/'.$cycle)->assertOk()->assertJsonPath('data.livestock.species.code', 'chicken');
        $this->assertContains($poultry->id, [$poultry->id]);

        $this->signInAs($this->admin);
        $others = Species::where('operation_type_id', $poultry->id)->where('is_active', true)->get();
        foreach ($others as $s) {
            $this->patchJson($this->url('/master/species/'.$s->id), ['is_active' => false])->assertOk();
        }
        $this->patchJson($this->url('/master/operation-types/'.$poultry->id), ['is_active' => false])->assertOk();
        $this->patchJson($this->url('/master/species/'.$chicken->id), ['is_active' => true])->assertStatus(422)->assertJsonPath('code', 'parent_inactive');
        $this->postJson($this->url('/master/species'), ['operation_type_id' => $poultry->id, 'code' => 'goose_test', 'name' => 'Goose'])->assertStatus(422)->assertJsonPath('code', 'parent_inactive');
        $this->patchJson($this->url('/master/operation-types/'.$poultry->id), ['is_active' => true])->assertOk();
        $this->patchJson($this->url('/master/species/'.$chicken->id), ['is_active' => true])->assertOk();
    }

    public function test_a_farm_custom_breed_is_not_reachable_from_the_platform(): void
    {
        $this->signInAs($this->owner);
        $custom = $this->postJson('/api/v1/custom-breeds', ['species_id' => Species::where('code', 'chicken')->firstOrFail()->id, 'name' => 'Farm Special'])->assertCreated()->json('data');
        $this->signInAs($this->admin);
        $this->assertNotContains('Farm Special', array_column($this->getJson($this->url('/master/breeds?per_page=100&q=Special'))->json('data'), 'name'));
        $this->getJson($this->url('/master/breeds/'.$custom['id']))->assertNotFound();
        $this->patchJson($this->url('/master/breeds/'.$custom['id']), ['name' => 'Hijacked'])->assertNotFound();
        $this->assertSame('Farm Special', Breed::findOrFail($custom['id'])->name);
    }

    // ------------------------------------------------------------------ capability schemas

    public function test_capability_schemas_are_exposed_and_config_is_validated_against_them(): void
    {
        $schemas = collect($this->getJson($this->url('/master/capabilities'))->assertOk()->json('data'))->keyBy('code');
        $this->assertCount(11, $schemas);
        $this->assertArrayHasKey('incubation_days', $schemas['supports_incubation']['config_schema']);
        $this->assertSame([], $schemas['supports_harvest']['config_schema']);

        $chicken = Species::where('code', 'chicken')->firstOrFail();
        $base = '/master/species/'.$chicken->id.'/capabilities/';
        $this->putJson($this->url($base.'supports_incubation'), ['enabled' => true, 'reference_config' => ['incubation_days' => 22]])->assertOk();
        $this->assertSame(22, collect($this->getJson($this->url('/master/species/'.$chicken->id.'/capabilities'))->json('data'))->firstWhere('code', 'supports_incubation')['reference_config']['incubation_days']);

        $this->putJson($this->url($base.'supports_incubation'), ['enabled' => true, 'reference_config' => ['incubation_days' => 0]])->assertStatus(422);
        $this->putJson($this->url($base.'supports_incubation'), ['enabled' => true, 'reference_config' => ['incubation_days' => 5000]])->assertStatus(422);
        $this->putJson($this->url($base.'supports_incubation'), ['enabled' => true, 'reference_config' => ['behaviour' => 'x']])->assertStatus(422);
        $this->putJson($this->url($base.'supports_incubation'), ['enabled' => true, 'reference_config' => ['incubation_days' => 30, 'incubation_days_min' => 18, 'incubation_days_max' => 25]])->assertStatus(422);
        $this->putJson($this->url($base.'supports_incubation'), ['enabled' => true, 'reference_config' => ['incubation_days_min' => 25, 'incubation_days_max' => 18]])->assertStatus(422);
        $this->putJson($this->url($base.'supports_harvest'), ['enabled' => true, 'reference_config' => ['anything' => 1]])->assertStatus(422);
        $this->putJson($this->url($base.'not_a_capability'), ['enabled' => true])->assertNotFound();
        $this->putJson($this->url($base.'supports_incubation'), ['reference_config' => []])->assertStatus(422);
        $this->assertSame(22, collect($this->getJson($this->url('/master/species/'.$chicken->id.'/capabilities'))->json('data'))->firstWhere('code', 'supports_incubation')['reference_config']['incubation_days'], 'rejected edits change nothing');

        $entry = $this->platformAudit('platform.species_capability_updated')[0];
        $this->assertSame(21, $entry['changes']['before']['reference_config']['incubation_days']);
        $this->assertSame(22, $entry['changes']['after']['reference_config']['incubation_days']);
    }

    public function test_capability_compatibility_with_workflows_and_active_breeding_projects(): void
    {
        $this->bootClock();
        $this->signInAs($this->owner);
        $cycle = $this->layers(100);
        $project = $this->postJson('/api/v1/breeding-projects', ['production_cycle_id' => $cycle, 'workflow' => 'incubation', 'start_date' => '2026-10-10', 'eggs_set' => 50, 'idempotency_key' => $this->key()])->assertCreated()->json('data');
        $snapshot = $project['reference'] ?? null;

        $this->signInAs($this->admin);
        $chicken = Species::where('code', 'chicken')->firstOrFail();
        $base = '/master/species/'.$chicken->id.'/capabilities/';
        $this->putJson($this->url($base.'supports_incubation'), ['enabled' => false])->assertStatus(409)->assertJsonPath('code', 'capability_in_use')->assertJsonPath('details.active_breeding_projects', 1);
        $this->putJson($this->url($base.'supports_breeding'), ['enabled' => false])->assertStatus(409)->assertJsonPath('code', 'capability_dependency');

        // Editing the reference does not touch the snapshot already stored on the project.
        $this->putJson($this->url($base.'supports_incubation'), ['enabled' => true, 'reference_config' => ['incubation_days' => 25]])->assertOk();
        $stored = DB::table('breeding_projects')->where('id', $project['id'])->value('reference_snapshot');
        $this->assertStringContainsString('21', $stored);
        $this->assertStringNotContainsString('"incubation_days":25', $stored);

        // Once the project is cancelled the capability may be switched off; the dependency order is enforced.
        $this->signInAs($this->owner)->postJson('/api/v1/breeding-projects/'.$project['id'].'/cancel', ['reason' => 'Test'])->assertOk();
        $this->signInAs($this->admin);
        $this->putJson($this->url($base.'supports_incubation'), ['enabled' => false])->assertOk();
        $cattle = Species::where('code', 'cattle')->firstOrFail();
        $this->putJson($this->url('/master/species/'.$cattle->id.'/capabilities/supports_breeding'), ['enabled' => false])->assertStatus(409)->assertJsonPath('code', 'capability_dependency');
        $this->putJson($this->url('/master/species/'.$cattle->id.'/capabilities/supports_pregnancy'), ['enabled' => false])->assertOk();
        $this->putJson($this->url('/master/species/'.$cattle->id.'/capabilities/supports_breeding'), ['enabled' => false])->assertOk();
        $this->putJson($this->url('/master/species/'.$cattle->id.'/capabilities/supports_pregnancy'), ['enabled' => true])->assertStatus(422)->assertJsonPath('code', 'capability_dependency');
        $this->assertNull($snapshot === null ? null : null);
    }

    // ------------------------------------------------------------------ work templates

    public function test_platform_template_publication_lifecycle_and_farm_visibility(): void
    {
        $draft = $this->postJson($this->url('/work-templates'), $this->templatePayload())->assertCreated()->assertJsonPath('data.state', 'draft')->assertJsonPath('data.version', 1)->assertJsonPath('data.is_active', false)->json('data');
        $this->postJson($this->url('/work-templates'), $this->templatePayload())->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertNull(WorkTemplate::findOrFail($draft['id'])->farm_id);

        // A draft is invisible to farms (list and direct).
        $this->signInAs($this->owner);
        $this->assertNotContains($draft['id'], array_column($this->getJson('/api/v1/work-templates')->json('data'), 'id'));
        $this->getJson('/api/v1/work-templates/'.$draft['id'])->assertNotFound();

        $this->signInAs($this->admin);
        $this->postJson($this->url('/work-templates/'.$draft['id'].'/archive'))->assertStatus(409)->assertJsonPath('code', 'template_not_published');
        $this->postJson($this->url('/work-templates/'.$draft['id'].'/publish'))->assertOk()->assertJsonPath('data.state', 'published')->assertJsonPath('data.is_active', true);
        $this->postJson($this->url('/work-templates/'.$draft['id'].'/publish'))->assertStatus(409)->assertJsonPath('code', 'template_already_published');

        $this->signInAs($this->owner);
        $this->getJson('/api/v1/work-templates/'.$draft['id'])->assertOk()->assertJsonPath('data.source', 'platform');
        $this->getJson('/api/v1/work-templates/'.$draft['id'])->assertOk();
        $this->assertContains($draft['id'], array_column($this->getJson('/api/v1/work-templates')->json('data'), 'id'));

        // Applying captures the version; editing the published template later bumps it without touching what was applied.
        $this->bootClock();
        $cycle = $this->layers(40);
        $applied = $this->postJson('/api/v1/work-templates/'.$draft['id'].'/apply', ['production_cycle_id' => $cycle, 'idempotency_key' => $this->key()])->assertCreated()->json('data');
        $this->assertSame(1, $applied['template_version']);

        $this->signInAs($this->admin);
        $edited = $this->patchJson($this->url('/work-templates/'.$draft['id']), ['items' => [['title' => 'Weigh 20 birds', 'category' => 'growth_monitoring', 'anchor' => 'cycle_start', 'offset_days' => 14]]])->assertOk()
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.items.0.title', 'Weigh 20 birds')->json('data');
        $this->assertSame('Weigh a sample', DB::table('schedules')->where('template_application_id', $applied['id'])->value('title'), 'applied schedules keep their own copy');

        $this->postJson($this->url('/work-templates/'.$draft['id'].'/archive'))->assertOk()->assertJsonPath('data.state', 'archived');
        $this->patchJson($this->url('/work-templates/'.$draft['id']), ['name' => 'Renamed'])->assertStatus(409)->assertJsonPath('code', 'template_archived');
        $this->signInAs($this->owner);
        $this->postJson('/api/v1/work-templates/'.$draft['id'].'/apply', ['production_cycle_id' => $this->layers(10), 'idempotency_key' => $this->key()])->assertStatus(409)->assertJsonPath('code', 'template_inactive');
        $this->signInAs($this->admin);
        $this->postJson($this->url('/work-templates/'.$draft['id'].'/publish'))->assertOk()->assertJsonPath('data.state', 'published');
        $this->assertSame(2, $edited['version']);

        $this->assertSame(['platform.template_created', 'platform.template_published', 'platform.template_updated', 'platform.template_archived'], collect(['created', 'published', 'updated', 'archived'])->map(fn ($s) => 'platform.template_'.$s)->filter(fn ($a) => $this->platformAudit($a) !== [])->values()->all());
    }

    public function test_template_content_is_validated_and_publication_checks_references_and_capabilities(): void
    {
        $this->postJson($this->url('/work-templates'), $this->templatePayload(['items' => []]))->assertStatus(422);
        $this->postJson($this->url('/work-templates'), $this->templatePayload(['code' => 'bad_anchor', 'items' => [['title' => 'x', 'category' => 'growth_monitoring', 'anchor' => 'breeding_start']]]))->assertStatus(422);
        $this->postJson($this->url('/work-templates'), $this->templatePayload(['code' => 'Bad Code']))->assertStatus(422);
        $this->postJson($this->url('/work-templates'), $this->templatePayload(['code' => 'sneaky', 'is_active' => true]))->assertStatus(422);
        $this->postJson($this->url('/work-templates'), $this->templatePayload(['code' => 'sneaky2', 'farm_id' => $this->farm->id]))->assertStatus(422);

        $crop = CropType::where('code', 'yam')->first() ?? CropType::firstOrFail();
        $inactive = $this->postJson($this->url('/work-templates'), $this->templatePayload(['code' => 'crop_tpl', 'cycle_kind' => 'crop', 'crop_type_id' => $crop->id]))->assertCreated()->json('data');
        $crop->update(['is_active' => false]);
        $this->postJson($this->url('/work-templates/'.$inactive['id'].'/publish'))->assertStatus(422)->assertJsonValidationErrors('crop_type_id');
        $crop->update(['is_active' => true]);

        // Breeding template pinned to a species that lacks the workflow capability cannot be published.
        $catfish = Species::where('code', 'catfish')->first() ?? Species::whereDoesntHave('speciesCapabilities', fn ($q) => $q->where('enabled', true)->whereHas('capability', fn ($c) => $c->where('code', 'supports_incubation')))->firstOrFail();
        $breeding = $this->postJson($this->url('/work-templates'), ['code' => 'breed_tpl', 'name' => 'Breeding', 'applies_to' => 'breeding_project', 'breeding_workflow' => 'incubation', 'species_id' => $catfish->id,
            'items' => [['title' => 'Candle eggs', 'category' => 'breeding_reproduction', 'anchor' => 'breeding_start', 'offset_days' => 7]]])->assertCreated()->json('data');
        $this->postJson($this->url('/work-templates/'.$breeding['id'].'/publish'))->assertStatus(422)->assertJsonValidationErrors('breeding_workflow');
        $this->assertSame('draft', $this->getJson($this->url('/work-templates/'.$breeding['id']))->json('data.state'));

        $this->getJson($this->url('/work-templates?state=draft'))->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson($this->url('/work-templates?state=published&applies_to=production_cycle'))->assertOk();
        $this->getJson($this->url('/work-templates?state=bogus'))->assertStatus(422);
        $farmTemplateOwner = $this->signInAs($this->owner)->postJson('/api/v1/work-templates', ['name' => 'Mine', 'applies_to' => 'production_cycle', 'cycle_kind' => 'livestock', 'items' => [['title' => 'x', 'category' => 'growth_monitoring', 'anchor' => 'cycle_start']]])->assertCreated()->json('data.id');
        $this->signInAs($this->admin);
        $this->getJson($this->url('/work-templates/'.$farmTemplateOwner))->assertNotFound();
        $this->patchJson($this->url('/work-templates/'.$farmTemplateOwner), ['name' => 'Hijacked'])->assertNotFound();
        $this->assertSame('Mine', WorkTemplate::findOrFail($farmTemplateOwner)->name);
    }

    public function test_seeded_platform_templates_remain_published_for_farms(): void
    {
        $this->assertGreaterThan(0, WorkTemplate::whereNull('farm_id')->count());
        $this->assertSame(0, WorkTemplate::whereNull('farm_id')->whereNull('published_at')->count());
        $this->signInAs($this->owner);
        $this->assertNotEmpty($this->getJson('/api/v1/work-templates?source=platform')->json('data'));
    }

    // ------------------------------------------------------------------ settings and flags

    public function test_settings_are_a_validated_closed_registry(): void
    {
        $this->getJson($this->url('/settings'))->assertOk()->assertJsonCount(9, 'data')->assertJsonPath('data.0.value', null);
        $this->putJson($this->url('/settings/support_email'), ['value' => 'help@farm.example'])->assertOk()->assertJsonPath('data.value', 'help@farm.example');
        $this->putJson($this->url('/settings/support_email'), ['value' => 'not-an-email'])->assertStatus(422);
        $this->putJson($this->url('/settings/support_whatsapp'), ['value' => '+2348012345678'])->assertOk();
        $this->putJson($this->url('/settings/support_whatsapp'), ['value' => 'call me'])->assertStatus(422);
        foreach ([0, 21, 'many'] as $bad) {
            $this->putJson($this->url('/settings/marketplace_max_shops_per_user'), ['value' => $bad])->assertStatus(422);
        }
        $this->putJson($this->url('/settings/marketplace_max_shops_per_user'), ['value' => 5])->assertOk()->assertJsonPath('data.value', 5);
        $this->putJson($this->url('/settings/announcement'), ['value' => str_repeat('a', 501)])->assertStatus(422);
        $this->putJson($this->url('/settings/announcement'), [])->assertStatus(422);
        $this->putJson($this->url('/settings/default_currency'), ['value' => 'USD'])->assertNotFound()->assertJsonPath('code', 'unknown_setting');
        $this->putJson($this->url('/settings/support_email'), ['value' => null])->assertOk()->assertJsonPath('data.value', null);

        $entries = $this->platformAudit('platform.setting_updated');
        $this->assertSame('help@farm.example', $entries[0]['changes']['before']['value']);
        $this->assertNull($entries[0]['changes']['after']['value']);
        $this->assertNull($entries[2]['changes']['before']['value']);
        $this->assertSame('+2348012345678', app(PlatformConfigService::class)->setting('support_whatsapp'));
        $this->assertSame('help@farm.example', app(PlatformConfigService::class)->setting('support_email') ?? 'help@farm.example');
    }

    public function test_feature_flags_toggle_validate_and_are_never_deleted(): void
    {
        $this->postJson($this->url('/feature-flags'), ['key' => 'new_dashboard', 'description' => 'Beta dashboard'])->assertCreated()->assertJsonPath('data.enabled', false);
        $this->postJson($this->url('/feature-flags'), ['key' => 'new_dashboard'])->assertStatus(422);
        $this->postJson($this->url('/feature-flags'), ['key' => 'Bad Key'])->assertStatus(422);
        $this->assertFalse(app(PlatformConfigService::class)->flag('new_dashboard'));
        $this->patchJson($this->url('/feature-flags/new_dashboard'), ['enabled' => true])->assertOk()->assertJsonPath('data.enabled', true);
        $this->assertTrue(app(PlatformConfigService::class)->flag('new_dashboard'));
        $this->patchJson($this->url('/feature-flags/missing'), ['enabled' => true])->assertNotFound();
        $this->patchJson($this->url('/feature-flags/new_dashboard'), ['enabled' => 'maybe'])->assertStatus(422);
        // The two Phase 26 monetisation flags are seeded (off); keys sort alphabetically.
        $flags = $this->getJson($this->url('/feature-flags'))->assertJsonPath('data.0.key', 'marketplace_promotions')->assertJsonPath('data.0.enabled', false)
            ->assertJsonPath('data.1.key', 'marketplace_seller_plans')->assertJsonPath('data.1.enabled', false)->json('data');
        $this->assertSame('new_dashboard', $flags[2]['key']);

        $this->expectException(\LogicException::class);
        FeatureFlag::firstOrFail()->delete();
    }

    // ------------------------------------------------------------------ support: users and farms

    public function test_user_search_detail_and_pagination_expose_no_secrets(): void
    {
        $worker = $this->member(FarmRole::FarmWorker, null, 'Wale Worker');
        $page = $this->getJson($this->url('/users?per_page=2'))->assertOk()->assertJsonCount(2, 'data')->json();
        $this->assertGreaterThan(2, $page['meta']['total']);
        $found = $this->getJson($this->url('/users?q='.urlencode($worker->email)))->assertOk()->assertJsonPath('meta.total', 1)->json('data.0');
        $this->assertSame(1, $found['active_farms_count']);
        $this->getJson($this->url('/users?platform_role=admin'))->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->admin->id);
        $this->getJson($this->url('/users?q=%25'))->assertJsonPath('meta.total', 0);
        $this->getJson($this->url('/users?status=bogus'))->assertStatus(422);

        $detail = $this->getJson($this->url('/users/'.$worker->id))->assertOk()->assertJsonPath('data.farms.0.farm_name', 'Green Acres')->assertJsonPath('data.farms.0.role', 'farm_worker');
        $raw = $detail->getContent();
        foreach (['password', 'remember_token', 'token', 'otp', 'provider_user_id'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
        $this->assertStringNotContainsString($worker->password, $raw);
        $this->getJson($this->url('/users/'.(string) Str::uuid()))->assertNotFound();
    }

    public function test_suspend_and_restore_are_reversible_guarded_and_audited(): void
    {
        $worker = $this->member(FarmRole::FarmWorker, null, 'Wale Worker');
        $token = $worker->createToken('mobile');
        $this->postJson($this->url('/users/'.$worker->id.'/suspend'), [])->assertStatus(422);
        $this->postJson($this->url('/users/'.$this->admin->id.'/suspend'), ['reason' => 'self'])->assertStatus(409)->assertJsonPath('code', 'cannot_suspend_self');
        $other = $this->platformUser(PlatformRole::Support, 'Other Admin');
        $this->postJson($this->url('/users/'.$other->id.'/suspend'), ['reason' => 'nope'])->assertStatus(409)->assertJsonPath('code', 'platform_admin_protected');

        $this->postJson($this->url('/users/'.$worker->id.'/suspend'), ['reason' => 'Payment fraud report'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->assertTrue($worker->fresh()->isSuspended());
        $this->assertSame(0, $worker->tokens()->count(), 'API tokens are revoked');
        $this->assertNotNull($token->plainTextToken);
        $this->postJson($this->url('/users/'.$worker->id.'/suspend'), ['reason' => 'again'])->assertStatus(409)->assertJsonPath('code', 'already_suspended');
        $this->assertSame(1, $worker->memberships()->where('status', 'active')->count(), 'memberships untouched');
        $this->signInAs($worker->fresh())->getJson('/api/v1/farm')->assertForbidden()->assertJsonPath('code', 'account_suspended');

        $this->signInAs($this->admin);
        $this->postJson($this->url('/users/'.$this->owner->id.'/restore'))->assertStatus(409)->assertJsonPath('code', 'not_suspended');
        $this->postJson($this->url('/users/'.$worker->id.'/restore'))->assertOk()->assertJsonPath('data.status', 'active');
        $this->getJson($this->url('/users?status=suspended'))->assertJsonPath('meta.total', 0);
        $this->assertSame('Payment fraud report', $this->platformAudit('platform.user_suspended')[0]['changes']['reason']);
        $this->assertCount(1, $this->platformAudit('platform.user_restored'));
    }

    public function test_cross_farm_search_and_detail_without_leaking_farm_business_data(): void
    {
        [$otherOwner, $otherFarm] = $this->otherFarm();
        $this->onPlan('farm-pro', $otherFarm);
        $this->bootClock();
        $this->signInAs($this->owner);
        $this->layers(100);
        $this->signInAs($this->admin);

        $list = $this->getJson($this->url('/farms'))->assertOk()->assertJsonPath('meta.total', 2)->json('data');
        $this->assertEqualsCanonicalizing(['Green Acres', 'Other Farm'], array_column($list, 'name'));
        $this->getJson($this->url('/farms?q=Other'))->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.owner.email', $otherOwner->email);
        $this->getJson($this->url('/farms?q='.urlencode($this->owner->email)))->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Green Acres');
        $this->getJson($this->url('/farms?plan=farm-pro'))->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $otherFarm->id);
        $this->getJson($this->url('/farms?subscription_status=cancelled'))->assertJsonPath('meta.total', 0);
        $this->getJson($this->url('/farms?sort=name&direction=asc'))->assertJsonPath('data.0.name', 'Green Acres');
        $this->getJson($this->url('/farms?sort=owner_email'))->assertStatus(422);

        $detail = $this->getJson($this->url('/farms/'.$this->farm->id))->assertOk()->assertJsonPath('data.owner.id', $this->owner->id)->assertJsonPath('data.entitlements.limits.active_cycles.usage', 1)
            ->assertJsonPath('data.members.0.role', 'owner')->assertJsonPath('data.entitlements.plan.slug', 'free');
        $raw = $detail->getContent();
        foreach (['population', 'inventory', 'finance', 'balance', 'invoice', 'payment', 'sale', 'password'] as $leak) {
            $this->assertStringNotContainsString($leak, strtolower($raw), "no $leak data in the support view");
        }
        $this->getJson($this->url('/farms/'.(string) Str::uuid()))->assertNotFound();
    }

    public function test_normal_farm_endpoints_stay_isolated_while_platform_lists_span_farms(): void
    {
        [$otherOwner, $otherFarm] = $this->otherFarm();
        $this->bootClock();
        $this->signInAs($otherOwner);
        $foreignCycle = $this->layers(10);
        $this->signInAs($this->owner);
        $this->getJson('/api/v1/production-cycles/'.$foreignCycle)->assertNotFound();
        $this->getJson('/api/v1/farm', ['X-Farm-Id' => $otherFarm->id])->assertForbidden();
        $this->getJson('/api/v1/production-cycles')->assertJsonCount(0, 'data');

        $this->signInAs($this->admin);
        $this->assertSame(2, $this->getJson($this->url('/farms'))->json('meta.total'));
        $this->getJson('/api/v1/production-cycles/'.$foreignCycle)->assertStatus(403);   // an admin is not a farm member
    }

    public function test_cross_tenant_lists_use_a_constant_number_of_queries(): void
    {
        $count = function (string $path): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($this->url($path))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $this->postJson($this->url('/feature-flags'), ['key' => 'flag_seed'])->assertCreated();
        $count('/farms');   // warm-up: first request in a process pays one-off queries
        $small = [$count('/farms?per_page=50'), $count('/users?per_page=50'), $count('/plans'), $count('/audit-logs?per_page=50')];

        for ($i = 0; $i < 8; $i++) {
            [$owner] = $this->otherFarm();
            $this->member(FarmRole::FarmWorker, $owner->currentFarm(), 'W'.$i);
            $this->postJson($this->url('/feature-flags'), ['key' => 'flag_'.$i])->assertCreated();
        }
        $large = [$count('/farms?per_page=50'), $count('/users?per_page=50'), $count('/plans'), $count('/audit-logs?per_page=50')];

        $this->assertSame($small, $large, 'query count does not grow with the number of farms/users/audit rows');
        $this->assertLessThan(12, max($large));
    }

    // ------------------------------------------------------------------ audit

    public function test_platform_audit_records_actor_resource_request_id_and_stays_out_of_farm_views(): void
    {
        $this->withHeaders(['X-Request-Id' => 'platform-req-0001'])->postJson($this->url('/feature-flags'), ['key' => 'audited_flag'])->assertCreated();
        unset($this->defaultHeaders['X-Request-Id']);
        $this->patchJson($this->url('/feature-flags/audited_flag'), ['enabled' => true])->assertOk();

        $entries = $this->getJson($this->url('/audit-logs?request_id=platform-req-0001'))->assertOk()->assertJsonCount(1, 'data')->json('data');
        $this->assertSame('platform.flag_created', $entries[0]['action']);
        $this->assertSame(['id' => $this->admin->id, 'name' => 'Pat Admin'], $entries[0]['actor']);
        $this->assertSame('feature_flag', $entries[0]['resource']['type']);
        $this->assertSame('audited_flag', $entries[0]['resource']['label']);
        $this->assertNotNull($entries[0]['performed_at']);

        $this->getJson($this->url('/audit-logs?action=platform.flag_updated'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.changes.before.enabled', false)->assertJsonPath('data.0.changes.after.enabled', true);
        $this->getJson($this->url('/audit-logs?actor_id='.$this->admin->id.'&resource_type=feature_flag&from=2000-01-01&to=2100-01-01'))->assertJsonPath('meta.total', 2);
        $this->getJson($this->url('/audit-logs?from=2100-01-01'))->assertJsonPath('meta.total', 0);
        $this->getJson($this->url('/audit-logs?per_page=1'))->assertJsonCount(1, 'data')->assertJsonPath('meta.last_page', 2);
        $this->getJson($this->url('/audit-logs?to=2000-01-01&from=2100-01-01'))->assertStatus(422);

        $this->assertNull(AuditLog::where('action', 'platform.flag_created')->firstOrFail()->farm_id);
        $this->signInAs($this->owner);
        $this->assertSame([], array_values(array_filter(array_column($this->getJson('/api/v1/audit?per_page=100')->json('data'), 'action'), fn ($a) => str_starts_with($a, 'platform.'))));
        $this->getJson($this->url('/audit-logs'))->assertForbidden();
    }

    public function test_audit_entries_never_contain_secrets_and_audit_log_remains_append_only(): void
    {
        $worker = $this->member(FarmRole::FarmWorker, null, 'Wale Worker');
        $this->postJson($this->url('/users/'.$worker->id.'/suspend'), ['reason' => 'Review'])->assertOk();
        $this->putJson($this->url('/settings/announcement'), ['value' => 'Maintenance tonight'])->assertOk();
        $this->postJson($this->url('/plans'), ['slug' => 'plain-plan', 'name' => 'Plain'])->assertCreated();

        $dump = strtolower(json_encode(AuditLog::whereNull('farm_id')->get()->map->only(['action', 'resource_label', 'changes'])->all()));
        foreach (['password', 'remember_token', 'otp', 'bearer', $worker->password, 'secret'] as $needle) {
            $this->assertStringNotContainsString(strtolower($needle), $dump);
        }

        $entry = AuditLog::whereNull('farm_id')->firstOrFail();
        $this->expectException(\LogicException::class);
        $entry->update(['action' => 'tampered']);
    }

    public function test_failed_changes_are_atomic_with_their_audit_entry(): void
    {
        $pro = $this->plan('farm-pro');
        $this->onPlan('farm-pro');
        $before = AuditLog::whereNull('farm_id')->count();
        $this->patchJson($this->url('/plans/'.$pro->id), ['is_active' => false])->assertStatus(409);
        $this->putJson($this->url('/master/species/'.Species::where('code', 'chicken')->firstOrFail()->id.'/capabilities/supports_incubation'), ['enabled' => true, 'reference_config' => ['incubation_days' => -3]])->assertStatus(422);
        $this->postJson($this->url('/plans/'.$this->plan('free')->id.'/make-default'))->assertOk();
        $this->assertSame($before, AuditLog::whereNull('farm_id')->count(), 'no audit row for a change that did not happen');
    }

    public function test_every_response_carries_a_request_id(): void
    {
        $this->getJson($this->url('/plans'))->assertOk()->assertHeader('X-Request-Id');
        $response = $this->withHeaders(['X-Request-Id' => 'trace-me-12345'])->postJson($this->url('/plans'), ['slug' => 'Bad'])->assertStatus(422);
        $response->assertHeader('X-Request-Id', 'trace-me-12345')->assertJsonPath('request_id', 'trace-me-12345');
        $this->signInAs($this->owner)->getJson($this->url('/plans'))->assertForbidden()->assertJsonStructure(['message', 'code', 'request_id']);
    }

    public function test_migration_adds_platform_tables_with_uuid_keys_and_keeps_farm_audit_scoped(): void
    {
        foreach (['platform_admins', 'platform_settings', 'feature_flags'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertSame('char', DB::selectOne('select data_type as t from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?', [$table, 'id'])->t);
        }
        $column = DB::selectOne("select is_nullable as n from information_schema.columns where table_schema = database() and table_name = 'audit_logs' and column_name = 'farm_id'");
        $this->assertSame('YES', $column->n);
        $this->assertTrue(Schema::hasColumn('work_templates', 'published_at'));
    }
}
