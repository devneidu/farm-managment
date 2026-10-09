<?php

namespace Tests\Feature\Production;

use App\Models\Breed;
use App\Models\Species;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class LivestockBatchMigrationTest extends TestCase
{
    public function test_upgrade_and_unused_rollback_preserve_legacy_batches_and_custom_breeds(): void
    {
        $originalConnection = DB::getDefaultConnection();
        config(['database.connections.livestock_migration_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('livestock_migration_test');
        try {
            Schema::create('farms', fn (Blueprint $table) => $table->uuid('id')->primary());
            $master = require database_path('migrations/2026_10_02_100000_create_master_data_tables.php');
            $master->up();
            (new MasterDataSeeder)->run();
            $catalogue = require database_path('migrations/2026_10_09_100000_expand_livestock_catalogue.php');
            $catalogue->up();
            Schema::create('contacts', fn (Blueprint $table) => $table->uuid('id')->primary());
            Schema::create('livestock_batch_details', function (Blueprint $table) {
                $table->uuid('production_cycle_id')->primary();
                $table->foreignUuid('species_id')->constrained('species');
                $table->foreignUuid('breed_id')->nullable()->constrained('breeds');
                $table->unsignedBigInteger('initial_population');
                $table->json('baseline_measurement');
            });
            $farm = (string) Str::uuid7();
            DB::table('farms')->insert(['id' => $farm]);
            $species = Species::where('code', 'chicken')->firstOrFail();
            // This existing custom breed shares the new system catalogue's name and must remain farm-owned.
            $custom = Breed::create(['species_id' => $species->id, 'farm_id' => $farm, 'name' => 'Noiler', 'is_active' => false]);
            $legacy = ['production_cycle_id' => (string) Str::uuid7(), 'species_id' => $species->id, 'breed_id' => $custom->id,
                'initial_population' => 42, 'baseline_measurement' => json_encode(['normalized' => ['quantity' => '42', 'unit' => 'head']])];
            DB::table('livestock_batch_details')->insert($legacy);
            $migration = require database_path('migrations/2026_10_25_100000_extend_livestock_batch_creation.php');
            $migration->up();
            $row = (array) DB::table('livestock_batch_details')->first();
            foreach ($legacy as $key => $value) {
                $this->assertEquals($value, $row[$key], $key);
            }
            foreach (['production_purpose_id', 'growth_stage_id', 'acquisition_price_per_animal', 'supplier_contact_id'] as $key) {
                $this->assertNull($row[$key]);
            }
            $this->assertSame($farm, $custom->fresh()->farm_id);
            $this->assertFalse($custom->fresh()->is_active);
            $ids = Breed::system()->pluck('id')->all();
            $migration->down();
            $this->assertFalse(Schema::hasColumn('livestock_batch_details', 'production_purpose_id'));
            $this->assertSame($legacy['breed_id'], DB::table('livestock_batch_details')->value('breed_id'));
            $migration->up();
            $this->assertEqualsCanonicalizing($ids, Breed::system()->pluck('id')->all());
            $this->assertSame(42, (int) DB::table('livestock_batch_details')->value('initial_population'));
        } finally {
            DB::setDefaultConnection($originalConnection);
            DB::purge('livestock_migration_test');
        }
    }
}
