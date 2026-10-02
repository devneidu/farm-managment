<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrade path for databases migrated before the client-controlled flag was removed from Phase 11.
 * Fresh installs never create the column, so this is a no-op there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('breeding_outcomes', 'add_to_population')) {
            Schema::table('breeding_outcomes', function (Blueprint $table) {
                $table->dropColumn('add_to_population');
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: the column is obsolete and must not be recreated.
    }
};
