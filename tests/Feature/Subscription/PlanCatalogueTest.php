<?php

namespace Tests\Feature\Subscription;

use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\PlanPrice;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\QueryException;
use Tests\Feature\Auth\AuthTestCase;

class PlanCatalogueTest extends AuthTestCase
{
    public function test_initial_plans_are_provisioned_by_migration_with_one_default(): void
    {
        $this->assertSame(['free', 'farm-pro', 'farm-business'], Plan::orderBy('sort_order')->pluck('slug')->all());
        $this->assertSame(['free'], Plan::where('is_default', true)->pluck('slug')->all());
    }

    public function test_prices_are_integer_minor_units_in_ngn(): void
    {
        $pro = Plan::where('slug', 'farm-pro')->firstOrFail();
        $monthly = PlanPrice::where('plan_id', $pro->id)->where('interval', 'monthly')->firstOrFail();

        $this->assertSame(300000, $monthly->amount_minor); // ₦3,000.00 in kobo
        $this->assertSame('NGN', $monthly->currency);
        $this->assertSame(0, PlanPrice::where('plan_id', Plan::where('slug', 'free')->value('id'))->value('amount_minor'));
    }

    public function test_plan_slugs_are_unique(): void
    {
        $this->expectException(QueryException::class);

        Plan::create(['slug' => 'free', 'name' => 'Another']);
    }

    public function test_seeder_is_insert_only_and_never_overwrites_admin_changes(): void
    {
        $pro = Plan::where('slug', 'farm-pro')->firstOrFail();
        $pro->update(['name' => 'Renamed by admin', 'is_public' => false]);
        PlanPrice::where('plan_id', $pro->id)->where('interval', 'monthly')->update(['amount_minor' => 123400]);
        $entitlements = PlanEntitlement::count();

        (new PlanSeeder)->run();
        (new PlanSeeder)->run();

        $this->assertSame(3, Plan::count());
        $this->assertSame($entitlements, PlanEntitlement::count());
        $this->assertSame('Renamed by admin', $pro->fresh()->name);
        $this->assertFalse($pro->fresh()->is_public);
        $this->assertSame(123400, PlanPrice::where('plan_id', $pro->id)->where('interval', 'monthly')->value('amount_minor'));
    }

    public function test_public_plans_need_no_authentication_and_are_ordered_deterministically(): void
    {
        $response = $this->getJson('/api/v1/public/plans')->assertOk();

        $this->assertSame(['free', 'farm-pro', 'farm-business'], array_column($response->json('data'), 'slug'));

        Plan::where('slug', 'farm-business')->update(['sort_order' => 5]);

        $this->assertSame(['farm-business', 'free', 'farm-pro'], array_column($this->getJson('/api/v1/public/plans')->json('data'), 'slug'));
    }

    public function test_inactive_and_non_public_plans_are_not_listed(): void
    {
        Plan::where('slug', 'farm-pro')->update(['is_public' => false]);
        Plan::where('slug', 'farm-business')->update(['is_active' => false]);

        $this->assertSame(['free'], array_column($this->getJson('/api/v1/public/plans')->json('data'), 'slug'));
    }

    public function test_plan_payload_lists_every_feature_and_limit_with_explicit_unlimited(): void
    {
        $plans = collect($this->getJson('/api/v1/public/plans')->json('data'))->keyBy('slug');

        $pro = $plans['farm-pro'];
        $this->assertSame(300000, $pro['prices'][0]['amount_minor']);
        $this->assertSame('monthly', $pro['prices'][0]['interval']);
        $this->assertSame('₦3,000.00', $pro['prices'][0]['formatted']);

        $features = fn (string $slug) => collect($plans[$slug]['features'])->pluck('enabled', 'key')->all();
        $this->assertSame(['advanced_reports' => false, 'data_export' => false], $features('free'));
        $this->assertSame(['advanced_reports' => true, 'data_export' => false], $features('farm-pro'));

        $this->assertSame(['key' => 'team_members', 'label' => 'Team members', 'limit' => 3, 'unlimited' => false], $plans['free']['limits'][0]);
        $this->assertSame(['key' => 'team_members', 'label' => 'Team members', 'limit' => null, 'unlimited' => true], $plans['farm-business']['limits'][0]);
        $this->assertArrayNotHasKey('provider', $pro);
    }
}
