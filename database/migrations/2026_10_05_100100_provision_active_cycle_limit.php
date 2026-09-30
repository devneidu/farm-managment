<?php

use Database\Seeders\ActiveCycleLimitSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        (new ActiveCycleLimitSeeder)->run();
    }

    public function down(): void
    {
        $ids = DB::table('entitlements')->where('key', 'active_cycles')->pluck('id');
        DB::table('plan_entitlements')->whereIn('entitlement_id', $ids)->delete();
        DB::table('entitlements')->whereIn('id', $ids)->delete();
    }
};
