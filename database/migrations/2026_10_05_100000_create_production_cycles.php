<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_cycles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->string('kind', 20);
            $t->string('reference', 40);
            $t->string('name', 100);
            $t->string('normalized_name', 100)->collation('utf8mb4_bin');
            $t->foreignUuid('operation_type_id')->constrained()->restrictOnDelete();
            $t->uuid('production_area_id')->nullable();
            $t->string('status', 20)->default('active');
            $t->date('start_date');
            $t->date('expected_end_date')->nullable();
            $t->date('end_date')->nullable();
            $t->text('notes')->nullable();
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['farm_id', 'id']);
            $t->unique(['farm_id', 'reference']);
            $t->unique(['farm_id', 'kind', 'normalized_name'], 'cycles_farm_kind_name_unique');
            $t->index(['farm_id', 'status', 'kind']);
            $t->foreign(['farm_id', 'production_area_id'], 'cycles_farm_area_fk')->references(['farm_id', 'id'])->on('production_areas')->restrictOnDelete();
        });
        Schema::create('livestock_batch_details', function (Blueprint $t) {
            $t->foreignUuid('production_cycle_id')->primary()->constrained()->restrictOnDelete();
            $t->foreignUuid('species_id')->constrained('species')->restrictOnDelete();
            $t->foreignUuid('breed_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('initial_population');
            $t->json('baseline_measurement');
        });
        Schema::create('crop_project_details', function (Blueprint $t) {
            $t->foreignUuid('production_cycle_id')->primary()->constrained()->restrictOnDelete();
            $t->foreignUuid('crop_type_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('crop_variety_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('planting_material_type_id')->constrained('reference_values')->restrictOnDelete();
            $t->foreignUuid('planting_unit_type_id')->constrained('reference_values')->restrictOnDelete();
            $t->unsignedBigInteger('initial_planting_units');
            $t->json('baseline_measurement');
            $t->date('expected_germination_date')->nullable();
            $t->decimal('area_normalized_quantity', 24, 6)->nullable();
            $t->foreignUuid('area_normalized_unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $t->json('area_measurement')->nullable();
        });
        Schema::create('population_movements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->uuid('production_cycle_id');
            $t->string('type', 40);
            $t->bigInteger('quantity');
            $t->string('source_key', 80);
            $t->dateTime('recorded_at');
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['production_cycle_id', 'source_key'], 'population_source_unique');
            $t->foreign(['farm_id', 'production_cycle_id'], 'population_farm_cycle_fk')->references(['farm_id', 'id'])->on('production_cycles')->restrictOnDelete();
        });
        Schema::create('production_cycle_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->uuid('production_cycle_id');
            $t->string('action', 30);
            $t->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();
            $t->json('changes');
            $t->text('reason')->nullable();
            $t->dateTime('recorded_at');
            $t->timestamps();
            $t->foreign(['farm_id', 'production_cycle_id'], 'cycle_events_farm_cycle_fk')->references(['farm_id', 'id'])->on('production_cycles')->restrictOnDelete();
            $t->index(['production_cycle_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_cycle_events');
        Schema::dropIfExists('population_movements');
        Schema::dropIfExists('crop_project_details');
        Schema::dropIfExists('livestock_batch_details');
        Schema::dropIfExists('production_cycles');
    }
};
