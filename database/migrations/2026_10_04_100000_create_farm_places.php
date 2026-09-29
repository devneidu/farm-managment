<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['locations', 'production_areas', 'storage_locations'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->uuid('id')->primary();
                $table->foreignUuid('farm_id')->constrained('farms')->restrictOnDelete();
                $parent = $name === 'locations' ? 'parent_id' : 'location_id';
                $table->uuid($parent)->nullable();
                $table->string('name', 100);
                $table->string('normalized_name', 100)->collation('utf8mb4_bin');
                $table->string('type', 30);
                $table->boolean('is_active')->default(true);
                // NULL parents must participate in uniqueness too (MySQL otherwise permits duplicate roots).
                $table->string('parent_scope', 36)->storedAs("COALESCE({$parent}, '')");
                $table->timestamps();
                $table->unique(['farm_id', 'id'], $name.'_farm_id_unique');
                $table->unique(['farm_id', 'parent_scope', 'normalized_name'], $name.'_sibling_unique');
                $table->index(['farm_id', 'is_active', 'type']);
            });
            Schema::table($name, function (Blueprint $table) use ($name) {
                $parent = $name === 'locations' ? 'parent_id' : 'location_id';
                // Enforces ownership even for writes outside the application service.
                $table->foreign(['farm_id', $parent], $name.'_parent_foreign')
                    ->references(['farm_id', 'id'])->on('locations')->restrictOnDelete()->restrictOnUpdate();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_locations');
        Schema::dropIfExists('production_areas');
        Schema::table('locations', fn (Blueprint $table) => $table->dropForeign('locations_parent_foreign'));
        Schema::dropIfExists('locations');
    }
};
