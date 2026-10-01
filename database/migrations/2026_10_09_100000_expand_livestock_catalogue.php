<?php

use Database\Seeders\LivestockCatalogueSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-Phase-11 master-data correction: adds `species.livestock_group` and applies the product-owner livestock
 * catalogue to databases that already hold Phase 4 data. Additive and idempotent: existing species keep their ids and
 * relationships; no row is deleted or recreated. Fish/aquaculture data is not touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('species', 'livestock_group')) {
            Schema::table('species', function (Blueprint $table) {
                $table->string('livestock_group', 32)->nullable()->after('name');
                $table->index('livestock_group');
            });
        }

        (new LivestockCatalogueSeeder)->run();
    }

    public function down(): void
    {
        // Only the column is removed. Added species/operations/capabilities are plain master data that cycles or
        // records may already reference, so they are intentionally kept (deleting them could orphan history).
        Schema::table('species', function (Blueprint $table) {
            $table->dropIndex(['livestock_group']);
            $table->dropColumn('livestock_group');
        });
    }
};
