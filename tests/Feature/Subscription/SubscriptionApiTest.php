<?php

namespace Tests\Feature\Subscription;

use App\Enums\FarmRole;
use App\Enums\Permission;
use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Team\TeamTestCase;

class SubscriptionApiTest extends TeamTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Probe routes exercising the reusable gates: RBAC (farm.permission) first, then the plan (entitlement).
        Route::middleware(['api', 'app.access', 'farm.context', 'farm.permission:farm.view', 'entitlement:advanced_reports'])
            ->get('/api/v1/_probe/reports', fn () => response()->json(['ok' => true]));
    }

    private function sub(): Subscription
    {
        return Subscription::where('farm_id', $this->farm->id)->firstOrFail();
    }

    public function test_subscription_endpoints_require_authentication(): void
    {
        foreach (['/subscription', '/subscription/entitlements', '/subscription/usage'] as $path) {
            $this->getJson('/api/v1'.$path)->assertUnauthorized();
        }
        $this->postJson('/api/v1/subscription/cancel')->assertUnauthorized();
    }

    public function test_current_subscription_identifies_the_plan_and_effective_state(): void
    {
        $this->signInAs($this->owner)->getJson('/api/v1/subscription')->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.plan.slug', 'free')
            ->assertJsonPath('data.plan.is_default', true)
            ->assertJsonPath('data.effective_plan.slug', 'free')
            ->assertJsonPath('data.subscription_inactive', false)
            ->assertJsonPath('data.cancel_at_period_end', false)
            ->assertJsonMissingPath('data.provider_reference');

        $this->onPlan('farm-pro');
        $this->getJson('/api/v1/subscription')->assertOk()
            ->assertJsonPath('data.plan.slug', 'farm-pro')
            ->assertJsonPath('data.billing_interval', 'monthly')
            ->assertJsonPath('data.plan.prices.0.amount_minor', 300000);
    }

    public function test_entitlements_endpoint_returns_features_and_limits_cleanly(): void
    {
        $this->signInAs($this->owner)->getJson('/api/v1/subscription/entitlements')->assertOk()
            ->assertJsonPath('data.plan.slug', 'free')
            ->assertJsonPath('data.features.advanced_reports', false)
            ->assertJsonPath('data.limits.team_members.limit', 3)
            ->assertJsonPath('data.limits.team_members.unlimited', false);

        $this->onPlan('farm-business');
        $this->getJson('/api/v1/subscription/entitlements')
            ->assertJsonPath('data.features.data_export', true)
            ->assertJsonPath('data.limits.team_members.limit', null)
            ->assertJsonPath('data.limits.team_members.unlimited', true);
    }

    public function test_a_lapsed_subscription_is_reported_and_falls_back_to_the_default_plan(): void
    {
        $this->onPlan('farm-pro');
        $this->sub()->forceFill(['current_period_end' => now()->subHour()])->save();

        $this->signInAs($this->owner)->getJson('/api/v1/subscription')
            ->assertJsonPath('data.plan.slug', 'farm-pro')
            ->assertJsonPath('data.effective_plan.slug', 'free')
            ->assertJsonPath('data.subscription_inactive', true);
        $this->getJson('/api/v1/subscription/entitlements')->assertJsonPath('data.features.advanced_reports', false);
    }

    public function test_viewing_is_limited_by_rbac_and_entitlements_are_readable_by_every_member(): void
    {
        foreach ([FarmRole::Owner, FarmRole::Manager, FarmRole::Finance] as $role) {
            $user = $role === FarmRole::Owner ? $this->owner : $this->member($role);
            $this->signInAs($user);
            $this->getJson('/api/v1/subscription')->assertOk();
            $this->getJson('/api/v1/subscription/usage')->assertOk();
        }

        $worker = $this->member(FarmRole::FarmWorker);
        $this->signInAs($worker);
        $this->getJson('/api/v1/subscription')->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->getJson('/api/v1/subscription/usage')->assertForbidden();
        $this->getJson('/api/v1/subscription/entitlements')->assertOk();
    }

    public function test_only_the_owner_holds_subscription_manage(): void
    {
        $this->assertTrue(FarmRole::Owner->can(Permission::SubscriptionManage));

        foreach ([FarmRole::Manager, FarmRole::Finance, FarmRole::FarmWorker] as $role) {
            $this->assertFalse($role->can(Permission::SubscriptionManage), $role->value);
        }
    }

    public function test_billing_actions_are_forbidden_to_non_owners_and_a_paid_plan_does_not_change_that(): void
    {
        $this->onPlan('farm-business');

        foreach ([FarmRole::Manager, FarmRole::Finance, FarmRole::FarmWorker] as $role) {
            $this->signInAs($this->member($role));
            $this->postJson('/api/v1/subscription/cancel')->assertForbidden()->assertJsonPath('code', 'forbidden');
            $this->postJson('/api/v1/subscription/resume')->assertForbidden()->assertJsonPath('code', 'forbidden');
        }

        $this->assertFalse($this->sub()->cancel_at_period_end);
    }

    public function test_cancel_and_resume_lifecycle_records_history(): void
    {
        $this->signInAs($this->owner);

        $this->postJson('/api/v1/subscription/cancel')->assertStatus(409)->assertJsonPath('code', 'subscription_not_cancellable'); // free plan
        $this->postJson('/api/v1/subscription/resume')->assertStatus(409)->assertJsonPath('code', 'subscription_not_cancelled');

        $this->onPlan('farm-pro');

        $this->postJson('/api/v1/subscription/cancel')->assertOk()
            ->assertJsonPath('data.cancel_at_period_end', true)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.effective_plan.slug', 'farm-pro'); // access continues until the period ends
        $this->postJson('/api/v1/subscription/cancel')->assertStatus(409)->assertJsonPath('code', 'subscription_already_cancelled');

        $this->postJson('/api/v1/subscription/resume')->assertOk()->assertJsonPath('data.cancel_at_period_end', false);
        $this->postJson('/api/v1/subscription/resume')->assertStatus(409);

        $this->assertSame(
            [SubscriptionEventType::Created, SubscriptionEventType::PlanChanged, SubscriptionEventType::CancellationScheduled, SubscriptionEventType::Resumed],
            $this->sub()->events->pluck('type')->all(),
        );
    }

    public function test_plan_history_survives_plan_changes(): void
    {
        $free = Plan::where('slug', 'free')->firstOrFail();
        $pro = Plan::where('slug', 'farm-pro')->firstOrFail();

        $this->onPlan('farm-pro');
        $this->onPlan('free');

        $changes = $this->sub()->events->where('type', SubscriptionEventType::PlanChanged)->values();
        $this->assertSame([$free->id, $pro->id], $changes->pluck('from_plan_id')->all());
        $this->assertSame([$pro->id, $free->id], $changes->pluck('to_plan_id')->all());
        $this->assertNull($this->sub()->current_period_end);
    }

    public function test_closing_lapsed_subscriptions_marks_expired_or_cancelled(): void
    {
        $this->onPlan('farm-pro');
        $this->sub()->forceFill(['current_period_end' => now()->subDay()])->save();
        $this->assertSame(1, app(SubscriptionService::class)->closeLapsed());
        $this->assertSame(SubscriptionStatus::Expired, $this->sub()->status);

        $this->onPlan('farm-pro');
        $this->sub()->forceFill(['cancel_at_period_end' => true, 'current_period_end' => now()->subDay()])->save();
        $this->artisan('subscriptions:close-lapsed')->assertSuccessful();
        $this->assertSame(SubscriptionStatus::Cancelled, $this->sub()->status);

        $this->assertSame(0, app(SubscriptionService::class)->closeLapsed()); // idempotent
    }

    public function test_boolean_gate_passes_rbac_then_checks_the_plan(): void
    {
        $manager = $this->member(FarmRole::Manager);
        $this->signInAs($manager)->getJson('/api/v1/_probe/reports')->assertForbidden()
            ->assertJsonPath('code', 'feature_not_available')
            ->assertJsonPath('details.entitlement_key', 'advanced_reports');

        $this->onPlan('farm-pro');
        $this->getJson('/api/v1/_probe/reports')->assertOk();

        $this->sub()->forceFill(['current_period_end' => now()->subMinute()])->save();
        $this->getJson('/api/v1/_probe/reports')->assertForbidden()->assertJsonPath('code', 'subscription_inactive');
    }

    public function test_entitlements_are_isolated_per_farm(): void
    {
        [$otherOwner, $otherFarm] = $this->otherFarm();
        $this->onPlan('farm-business', $otherFarm);

        $this->signInAs($this->owner)->getJson('/api/v1/subscription')->assertJsonPath('data.plan.slug', 'free');
        $this->getJson('/api/v1/_probe/reports')->assertForbidden()->assertJsonPath('code', 'feature_not_available');

        $this->signInAs($otherOwner)->getJson('/api/v1/subscription')->assertJsonPath('data.plan.slug', 'farm-business');
        $this->getJson('/api/v1/_probe/reports')->assertOk();

        // a farm id supplied by the client never reveals or borrows another farm's subscription
        $this->signInAs($this->owner)->withHeader('X-Farm-Id', $otherFarm->id)->getJson('/api/v1/subscription')
            ->assertForbidden()->assertJsonPath('code', 'farm_access_denied');

        // cancelling in one farm never touches another
        $this->signInAs($otherOwner)->postJson('/api/v1/subscription/cancel')->assertOk();
        $this->assertFalse(Subscription::where('farm_id', $this->farm->id)->value('cancel_at_period_end'));
    }
}
