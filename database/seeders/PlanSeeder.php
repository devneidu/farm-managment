<?php

namespace Database\Seeders;

use App\Enums\Feature;
use App\Enums\Limit;
use App\Models\Entitlement;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\PlanPrice;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Initial platform plan catalogue. PROVISIONAL product configuration (prices in kobo, feature flags, limits)
 * - see 37-OPEN-DECISIONS: final prices/limits are not decided. Plans are data; edit them in the database
 * (later via Platform Admin), never in entitlement logic.
 *
 * INSERT-ONLY: a plan that already exists (by slug) is left completely untouched, so re-running this seeder
 * never overwrites deliberate changes. New registry rows for entitlement keys are added if missing.
 * The catalogue is provisioned automatically by a migration; run `php artisan db:seed --class=PlanSeeder` only
 * to add a plan/entitlement that is missing.
 */
class PlanSeeder extends Seeder
{
    private const UNLIMITED = 'unlimited';

    /** @return list<array<string, mixed>> */
    private function catalogue(): array
    {
        return [
            [
                'slug' => 'free', 'name' => 'Free', 'sort_order' => 10, 'is_default' => true,
                'description' => 'Get started managing one farm.',
                'prices' => ['monthly' => 0],
                'features' => [Feature::AdvancedReports->value => false, Feature::DataExport->value => false],
                'limits' => [Limit::TeamMembers->value => 3],
            ],
            [
                'slug' => 'farm-pro', 'name' => 'Farm Pro', 'sort_order' => 20, 'is_default' => false,
                'description' => 'For growing farms with a team.',
                'prices' => ['monthly' => 300000, 'annual' => 3000000],
                'features' => [Feature::AdvancedReports->value => true, Feature::DataExport->value => false],
                'limits' => [Limit::TeamMembers->value => 10],
            ],
            [
                'slug' => 'farm-business', 'name' => 'Farm Business', 'sort_order' => 30, 'is_default' => false,
                'description' => 'For large operations that need everything.',
                'prices' => ['monthly' => 750000, 'annual' => 7500000],
                'features' => [Feature::AdvancedReports->value => true, Feature::DataExport->value => true],
                'limits' => [Limit::TeamMembers->value => self::UNLIMITED],
            ],
        ];
    }

    public function run(): void
    {
        DB::transaction(function () {
            $entitlements = $this->registry();

            foreach ($this->catalogue() as $def) {
                if (Plan::where('slug', $def['slug'])->exists()) {
                    continue;
                }

                $plan = Plan::create([
                    'slug' => $def['slug'], 'name' => $def['name'], 'description' => $def['description'],
                    'currency' => 'NGN', 'is_active' => true, 'is_public' => true,
                    'is_default' => $def['is_default'], 'sort_order' => $def['sort_order'],
                ]);

                foreach ($def['prices'] as $interval => $amountMinor) {
                    PlanPrice::create(['plan_id' => $plan->id, 'interval' => $interval, 'currency' => 'NGN', 'amount_minor' => $amountMinor]);
                }

                foreach ($def['features'] as $key => $enabled) {
                    PlanEntitlement::create(['plan_id' => $plan->id, 'entitlement_id' => $entitlements[$key], 'enabled' => $enabled]);
                }

                foreach ($def['limits'] as $key => $value) {
                    $unlimited = $value === self::UNLIMITED;
                    PlanEntitlement::create([
                        'plan_id' => $plan->id, 'entitlement_id' => $entitlements[$key],
                        'limit_value' => $unlimited ? null : $value, 'is_unlimited' => $unlimited,
                    ]);
                }
            }
        });
    }

    /** Ensures a registry row exists for every Feature/Limit case. @return array<string, string> key => id */
    private function registry(): array
    {
        $ids = [];

        foreach (Feature::cases() as $case) {
            $ids[$case->value] = Entitlement::firstOrCreate(['key' => $case->value], ['type' => Entitlement::TYPE_FEATURE, 'name' => $case->label()])->id;
        }

        foreach (Limit::cases() as $case) {
            $ids[$case->value] = Entitlement::firstOrCreate(['key' => $case->value], ['type' => Entitlement::TYPE_LIMIT, 'name' => $case->label()])->id;
        }

        return $ids;
    }
}
