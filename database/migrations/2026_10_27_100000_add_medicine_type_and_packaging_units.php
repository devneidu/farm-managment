<?php

use Database\Seeders\MeasurementSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V1 medicine catalogue.
 *
 * - medicine_profiles.medicine_type: antibiotic | vaccine | supplement | dewormer | other. Nullable on purpose: every medicine that
 *   existed before this column keeps NULL ("unspecified"); nothing is guessed from names or past health records.
 * - Package units box, card, sachet and vial are provisioned through the (insert-only, keyed by code) measurement seeder, so existing
 *   units, conversions and snapshots are untouched and re-running is harmless.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('medicine_profiles', 'medicine_type')) {
            Schema::table('medicine_profiles', function (Blueprint $table) {
                $table->string('medicine_type', 32)->nullable()->after('inventory_item_id');
            });
        }

        (new MeasurementSeeder)->run();
    }

    public function down(): void
    {
        if (Schema::hasColumn('medicine_profiles', 'medicine_type')) {
            Schema::table('medicine_profiles', fn (Blueprint $table) => $table->dropColumn('medicine_type'));
        }
        // Provisioned units stay: system units are protected and may already be referenced by conversions.
    }
};
