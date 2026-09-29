<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-level reference data. Stable machine `code`s are the identity for logic; names are display only.
        Schema::create('operation_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('category', 16);        // livestock | aquaculture | crop (App\Enums\OperationCategory)
            $table->string('tracking_model', 16);  // population | planting_units (App\Enums\TrackingModel)
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category', 'is_active']);
        });

        Schema::create('species', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('operation_type_id')->constrained('operation_types')->restrictOnDelete();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['operation_type_id', 'is_active']);
        });

        Schema::create('crop_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('operation_type_id')->constrained('operation_types')->restrictOnDelete();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['operation_type_id', 'is_active']);
        });

        // Registry of capability codes (mirrors App\Enums\Capability).
        Schema::create('capabilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('species_capabilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('species_id')->constrained('species')->cascadeOnDelete();
            $table->foreignUuid('capability_id')->constrained('capabilities')->restrictOnDelete();
            $table->boolean('enabled')->default(true);
            // Biological REFERENCE defaults only (validated keys, see App\Enums\Capability::configRules()).
            $table->json('reference_config')->nullable();
            $table->timestamps();

            $table->unique(['species_id', 'capability_id']);
        });

        // Small platform lists: planting material types and planting unit types (deliberately distinct lists).
        Schema::create('reference_values', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('list', 64);
            $table->string('code', 64);
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['list', 'code']);
        });

        // System rows have farm_id NULL; a farm's custom rows carry its farm_id. `scope` makes uniqueness work with NULL.
        Schema::create('breeds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('species_id')->constrained('species')->restrictOnDelete();
            $table->foreignUuid('farm_id')->nullable()->constrained('farms')->restrictOnDelete();
            $table->string('code', 64)->nullable();           // system rows only
            $table->string('name');
            $table->string('normalized_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->string('scope', 36)->storedAs("coalesce(farm_id, 'system')");

            $table->unique(['species_id', 'scope', 'normalized_name'], 'breeds_unique_name');
            $table->unique(['species_id', 'code'], 'breeds_unique_code');
            $table->index(['species_id', 'farm_id', 'is_active']);
        });

        Schema::create('crop_varieties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('crop_type_id')->constrained('crop_types')->restrictOnDelete();
            $table->foreignUuid('farm_id')->nullable()->constrained('farms')->restrictOnDelete();
            $table->string('code', 64)->nullable();
            $table->string('name');
            $table->string('normalized_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->string('scope', 36)->storedAs("coalesce(farm_id, 'system')");

            $table->unique(['crop_type_id', 'scope', 'normalized_name'], 'crop_varieties_unique_name');
            $table->unique(['crop_type_id', 'code'], 'crop_varieties_unique_code');
            $table->index(['crop_type_id', 'farm_id', 'is_active']);
        });

        // Optional, post-onboarding: which operations a farm has chosen. No rows = unconfigured = nothing is hidden.
        Schema::create('farm_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained('farms')->cascadeOnDelete();
            $table->foreignUuid('operation_type_id')->constrained('operation_types')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['farm_id', 'operation_type_id']);
        });
    }

    public function down(): void
    {
        foreach (['farm_operations', 'crop_varieties', 'breeds', 'reference_values', 'species_capabilities', 'capabilities', 'crop_types', 'species', 'operation_types'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
