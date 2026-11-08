<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-farm preferences as one JSON object. Absent keys read as their default (App\Services\Farm\FarmSettings), so existing farms need no backfill.
        Schema::table('farms', function (Blueprint $t) {
            $t->json('settings')->nullable()->after('locale');
        });

        // A typed medicine (written by name, not picked from the store) has no item, no store and no stock quantity; its line still carries the name snapshot,
        // dose and withdrawal. Stock lines are unchanged: they keep all four values and their movement.
        Schema::table('health_record_medicines', function (Blueprint $t) {
            $t->uuid('inventory_item_id')->nullable()->change();
            $t->uuid('storage_location_id')->nullable()->change();
            $t->decimal('quantity_used', 24, 6)->nullable()->change();
            $t->json('measurement')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('farms', function (Blueprint $t) {
            $t->dropColumn('settings');
        });
    }
};
