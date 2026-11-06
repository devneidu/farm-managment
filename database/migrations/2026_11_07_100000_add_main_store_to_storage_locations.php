<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The farm's "main store": the store stock goes into (and is taken from) when a request names none.
 *
 * Stored as storage_locations.is_default, with a generated main_scope column (= farm_id for the main store, NULL otherwise) under a
 * UNIQUE index: the database itself allows at most one main store per farm. MySQL permits many NULLs in a unique index, so non-main
 * stores are unconstrained. The flag lives on the store (not as farms.default_storage_location_id) because the store already carries
 * the (farm_id, id) ownership key, so a main store can never belong to another farm, and there is no circular farms <-> storage_locations
 * reference to order on create/restore.
 *
 * Existing farms: an active store named "Main Store" becomes main; otherwise the only active store; otherwise it stays unset (the
 * first stock-in without a store then creates "Main Store").
 */
return new class extends Migration
{
    private const DEFAULT_STORE = 'Main Store';

    public function up(): void
    {
        Schema::table('storage_locations', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('is_active');
        });
        DB::statement('ALTER TABLE storage_locations ADD COLUMN main_scope CHAR(36) GENERATED ALWAYS AS (CASE WHEN is_default = 1 THEN farm_id ELSE NULL END) STORED');
        Schema::table('storage_locations', function (Blueprint $table) {
            $table->unique('main_scope', 'storage_locations_one_main_per_farm');
        });

        $this->runBackfill();
    }

    /** Marks a main store on every farm that has none yet, by the rules in the class comment. Safe to re-run. */
    public function runBackfill(): void
    {
        DB::table('storage_locations')->where('is_active', true)->select('farm_id')->distinct()->orderBy('farm_id')->pluck('farm_id')->each(function (string $farmId) {
            $active = DB::table('storage_locations')->where('farm_id', $farmId)->where('is_active', true);
            $named = (clone $active)->where('normalized_name', mb_strtolower(self::DEFAULT_STORE))->orderByRaw('location_id is not null')->orderBy('id')->value('id');
            $chosen = $named ?? ((clone $active)->count() === 1 ? (clone $active)->value('id') : null);
            if ($chosen !== null && ! DB::table('storage_locations')->where('farm_id', $farmId)->where('is_default', true)->exists()) {
                DB::table('storage_locations')->where('id', $chosen)->update(['is_default' => true]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('storage_locations', function (Blueprint $table) {
            $table->dropUnique('storage_locations_one_main_per_farm');
        });
        DB::statement('ALTER TABLE storage_locations DROP COLUMN main_scope');
        Schema::table('storage_locations', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
