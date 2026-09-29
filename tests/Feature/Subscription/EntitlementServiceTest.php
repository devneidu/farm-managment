<?php

namespace Tests\Feature\Subscription;

use App\Enums\FarmRole;
use App\Enums\Feature;
use App\Enums\Limit;
use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use App\Models\Entitlement;
use App\Models\Farm;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscription\EntitlementService;
use App\Support\Entitlements\EntitlementException;
use Tests\Feature\Team\TeamTestCase;

class EntitlementServiceTest extends TeamTestCase
{
    private function service(): EntitlementService
    {
        return app(EntitlementService::class);
    }

    private function lapse(): void
    {
        Subscription::where('farm_id', $this->farm->id)->update(['current_period_end' => now()->subDay()]);
    }

    private function planEntitlement(string $planSlug, string $key): PlanEntitlement
    {
        return PlanEntitlement::where('plan_id', Plan::where('slug', $planSlug)->value('id'))
            ->where('entitlement_id', Entitlement::where('key', $key)->value('id'))->firstOrFail();
    }

    public function test_a_new_farm_starts_on_the_default_plan_owned_by_the_farm(): void
    {
        $subscription = Subscription::where('farm_id', $this->farm->id)->with('plan')->firstOrFail();

        $this->assertTrue($subscription->plan->is_default);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertNull($subscription->current_period_end);
        $this->assertSame(1, Subscription::where('farm_id', $this->farm->id)->count());
        $this->assertSame([SubscriptionEventType::Created], $subscription->events->pluck('type')->all());
        $this->assertFalse(in_array('user_id', array_keys($subscription->getAttributes()), true));
    }

    public function test_onboarding_creates_the_farm_and_its_default_subscription_atomically(): void
    {
        $user = User::factory()->create();

        $this->signInAs($user)->postJson('/api/v1/onboarding/farm', ['name' => 'Fresh Farm'])->assertCreated();

        $farm = Farm::where('name', 'Fresh Farm')->firstOrFail();
        $this->assertSame('free', $farm->subscription->plan->slug);
        $this->assertSame(3, $this->service()->limit($farm, Limit::TeamMembers)->value);
    }

    public function test_features_resolve_from_the_plan_configuration(): void
    {
        $this->assertFalse($this->service()->allows($this->farm, Feature::AdvancedReports));

        $this->onPlan('farm-pro');
        $this->assertTrue($this->service()->allows($this->farm, Feature::AdvancedReports));
        $this->assertFalse($this->service()->allows($this->farm, Feature::DataExport));

        $this->onPlan('farm-business');
        $this->assertTrue($this->service()->allows($this->farm, Feature::DataExport));
    }

    public function test_numeric_and_unlimited_limits_resolve_explicitly(): void
    {
        $this->assertSame(3, $this->service()->limit($this->farm, Limit::TeamMembers)->value);
        $this->assertSame(2, $this->service()->remaining($this->farm, Limit::TeamMembers)); // owner uses 1

        $this->onPlan('farm-business');
        $limit = $this->service()->limit($this->farm, Limit::TeamMembers);
        $this->assertTrue($limit->unlimited);
        $this->assertNull($limit->value);
        $this->assertNull($this->service()->remaining($this->farm, Limit::TeamMembers));
        $this->assertTrue($limit->allows(1_000_000));
    }

    public function test_missing_subscription_resolves_to_the_default_plan_not_an_undefined_state(): void
    {
        Subscription::where('farm_id', $this->farm->id)->delete();

        $set = $this->service()->for($this->farm);

        $this->assertSame('free', $set->plan->slug);
        $this->assertFalse($set->subscriptionInactive);
        $this->assertFalse($set->allows(Feature::AdvancedReports));
    }

    public function test_lapsed_expired_or_cancelled_subscription_does_not_grant_paid_access(): void
    {
        $this->onPlan('farm-pro');
        $this->assertTrue($this->service()->allows($this->farm, Feature::AdvancedReports));

        $this->lapse();
        $set = $this->service()->for($this->farm);
        $this->assertFalse($set->allows(Feature::AdvancedReports));
        $this->assertTrue($set->subscriptionInactive);
        $this->assertSame('free', $set->plan->slug);
        $this->assertSame(3, $set->limit(Limit::TeamMembers)->value);

        foreach ([SubscriptionStatus::Expired, SubscriptionStatus::Cancelled] as $status) {
            $this->onPlan('farm-pro');
            Subscription::where('farm_id', $this->farm->id)->update(['status' => $status->value]);
            $this->assertFalse($this->service()->allows($this->farm, Feature::AdvancedReports), $status->value);
        }
    }

    public function test_past_due_within_the_paid_period_is_still_honoured(): void
    {
        $this->onPlan('farm-pro');
        Subscription::where('farm_id', $this->farm->id)->update(['status' => SubscriptionStatus::PastDue->value]);

        $this->assertTrue($this->service()->allows($this->farm, Feature::AdvancedReports));
    }

    public function test_an_inactive_plan_is_not_honoured(): void
    {
        $this->onPlan('farm-pro');
        Plan::where('slug', 'farm-pro')->update(['is_active' => false]);

        $set = $this->service()->for($this->farm);
        $this->assertFalse($set->allows(Feature::AdvancedReports));
        $this->assertTrue($set->subscriptionInactive);
    }

    public function test_a_non_public_plan_is_still_honoured_for_existing_subscribers(): void
    {
        $this->onPlan('farm-pro');
        Plan::where('slug', 'farm-pro')->update(['is_public' => false]);

        $this->assertTrue($this->service()->allows($this->farm, Feature::AdvancedReports));
    }

    public function test_without_an_active_default_plan_nothing_is_allowed(): void
    {
        Plan::query()->update(['is_default' => false]);
        Subscription::where('farm_id', $this->farm->id)->delete();

        $set = $this->service()->for($this->farm);

        $this->assertNull($set->plan);
        $this->assertFalse($set->allows(Feature::AdvancedReports));
        $this->assertSame(0, $set->limit(Limit::TeamMembers)->value);
        $this->assertFalse($set->limit(Limit::TeamMembers)->allows(0));
    }

    public function test_malformed_configuration_fails_closed(): void
    {
        $this->onPlan('farm-business');

        // feature with no value, limit with neither a value nor unlimited, and an unknown registry key
        $this->planEntitlement('farm-business', 'advanced_reports')->update(['enabled' => null]);
        $this->planEntitlement('farm-business', 'team_members')->update(['limit_value' => null, 'is_unlimited' => false]);
        $unknown = Entitlement::create(['key' => 'from_the_future', 'type' => 'feature', 'name' => 'Future']);
        PlanEntitlement::create(['plan_id' => Plan::where('slug', 'farm-business')->value('id'), 'entitlement_id' => $unknown->id, 'enabled' => true]);

        $set = $this->service()->for($this->farm);

        $this->assertFalse($set->allows(Feature::AdvancedReports));
        $this->assertSame(0, $set->limit(Limit::TeamMembers)->value);
        $this->assertFalse($set->limit(Limit::TeamMembers)->unlimited);
    }

    public function test_a_row_of_the_wrong_type_is_ignored(): void
    {
        // A "limit" registry row that uses a Feature key must not enable that feature.
        Entitlement::where('key', 'advanced_reports')->update(['type' => 'limit']);

        $this->onPlan('farm-pro');

        $this->assertFalse($this->service()->allows($this->farm, Feature::AdvancedReports));
    }

    public function test_denials_carry_stable_codes_and_structured_details(): void
    {
        try {
            $this->service()->assertAllows($this->farm, Feature::AdvancedReports);
            $this->fail('Expected feature_not_available');
        } catch (EntitlementException $e) {
            $this->assertSame('feature_not_available', $e->errorCode);
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame(['entitlement_key' => 'advanced_reports'], $e->details);
        }

        $this->onPlan('farm-pro');
        $this->lapse();

        try {
            $this->service()->assertAllows($this->farm, Feature::AdvancedReports);
            $this->fail('Expected subscription_inactive');
        } catch (EntitlementException $e) {
            $this->assertSame('subscription_inactive', $e->errorCode);
        }

        $this->member(FarmRole::Manager);
        $this->member(FarmRole::Manager);

        try {
            $this->service()->assertCapacity($this->farm, Limit::TeamMembers);
            $this->fail('Expected plan_limit_reached');
        } catch (EntitlementException $e) {
            $this->assertSame('plan_limit_reached', $e->errorCode);
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame(['entitlement_key' => 'team_members', 'limit' => 3, 'usage' => 3, 'remaining' => 0], $e->details);
        }
    }

    public function test_the_provisioning_migration_backfills_existing_farms_idempotently(): void
    {
        Subscription::where('farm_id', $this->farm->id)->delete();

        $migration = require base_path('database/migrations/2026_10_01_100100_provision_default_plans_and_backfill_subscriptions.php');
        $migration->up();
        $migration->up();

        $this->assertSame(1, Subscription::where('farm_id', $this->farm->id)->count());
        $this->assertSame(3, Plan::count());
    }
}
