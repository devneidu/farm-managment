<?php

namespace Database\Seeders;

use App\Models\Entitlement;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Insert-only provisional values from product source section 32; existing administrator values win. */
class ActiveCycleLimitSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $entitlement = Entitlement::firstOrCreate(['key' => 'active_cycles'], ['type' => Entitlement::TYPE_LIMIT, 'name' => 'Active production cycles']);
            foreach (['free' => 3, 'farm-pro' => null, 'farm-business' => null] as $slug => $limit) {
                $plan = Plan::where('slug', $slug)->first();
                if ($plan) {
                    PlanEntitlement::firstOrCreate(['plan_id' => $plan->id, 'entitlement_id' => $entitlement->id], ['limit_value' => $limit, 'is_unlimited' => $limit === null]);
                }
            }
        });
    }
}
