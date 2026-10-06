<?php

use App\Enums\Capability;
use App\Models\MasterCapability;
use App\Models\Species;
use App\Models\SpeciesCapability;
use App\Services\MasterData\SpeciesCapabilityService;
use Database\Seeders\LivestockCatalogueSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Feed / eggs / milk stock support. Additive only; the ledger stays append-only.
 *
 * - inventory_items.system_key: the stable identity of an automatically resolved output item ("output:eggs", "output:milk").
 *   NULL for every item a farmer created. Unique per farm, which is what makes the resolve-or-create concurrency safe.
 * - inventory_movements.production_cycle_id / breeding_project_id: the domain event a movement belongs to when it is not
 *   already explained by a record / sale / purchase link (incubation consumption, record-driven effects by cycle).
 * - produces_milk: a capability default for the dairy species of the existing catalogue (insert-only, never overwrites a
 *   platform edit). Application behaviour stays capability-driven; this only establishes sensible defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $t) {
            $t->string('system_key', 40)->nullable()->after('normalized_name');
            $t->unique(['farm_id', 'system_key'], 'inventory_items_farm_system_key_unique');
        });

        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->uuid('production_cycle_id')->nullable();
            $t->uuid('breeding_project_id')->nullable();
            $t->index(['farm_id', 'production_cycle_id'], 'inventory_movements_cycle_index');
            $t->index(['farm_id', 'breeding_project_id'], 'inventory_movements_breeding_index');
            $t->foreign(['farm_id', 'production_cycle_id'], 'inventory_movements_cycle_fk')->references(['farm_id', 'id'])->on('production_cycles')->restrictOnDelete();
            $t->foreign(['farm_id', 'breeding_project_id'], 'inventory_movements_breeding_fk')->references(['farm_id', 'id'])->on('breeding_projects')->restrictOnDelete();
        });

        // Record-driven movements (feed use, crop inputs, harvest) already belong to a cycle through their record.
        DB::statement('UPDATE inventory_movements m JOIN operational_records r ON r.id = m.operational_record_id AND r.farm_id = m.farm_id SET m.production_cycle_id = r.production_cycle_id WHERE m.operational_record_id IS NOT NULL AND m.production_cycle_id IS NULL');

        $this->seedMilkCapability();
    }

    public function down(): void
    {
        // Only the additive columns are removed. The produces_milk rows are plain master data that cycles may rely on.
        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->dropForeign('inventory_movements_cycle_fk');
            $t->dropForeign('inventory_movements_breeding_fk');
            $t->dropIndex('inventory_movements_cycle_index');
            $t->dropIndex('inventory_movements_breeding_index');
            $t->dropColumn(['production_cycle_id', 'breeding_project_id']);
        });
        Schema::table('inventory_items', function (Blueprint $t) {
            $t->dropUnique('inventory_items_farm_system_key_unique');
            $t->dropColumn('system_key');
        });
    }

    /** Insert-only: a species/capability pair that already exists (possibly edited by the platform) is left alone. */
    private function seedMilkCapability(): void
    {
        $capability = MasterCapability::firstOrCreate(['code' => Capability::ProducesMilk->value], ['name' => Capability::ProducesMilk->label()]);
        $service = app(SpeciesCapabilityService::class);
        foreach (Species::whereIn('code', LivestockCatalogueSeeder::MILK_SPECIES)->get() as $species) {
            if (! SpeciesCapability::where('species_id', $species->id)->where('capability_id', $capability->id)->exists()) {
                $service->set($species, Capability::ProducesMilk, true, null);
            }
        }
    }
};
