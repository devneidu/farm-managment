<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform reference data. Stable machine `code`s are the identity for logic; names/symbols are display only.
        Schema::create('measurement_dimensions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 32)->unique();      // count | weight | volume | area | temperature | package
            $table->string('name');
            $table->boolean('supports_preference')->default(false); // may a farm pick a default display/entry unit?
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dimension_id')->constrained('measurement_dimensions')->restrictOnDelete();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('symbol', 16);
            // Standard conversion. Units convert into each other only when they share the same non-null `family`
            // (weight units, volume units, ... but also each count meaning on its own: head, egg, piece).
            // Package units have a NULL family: they never convert without a farm/context PackageConversion.
            $table->string('family', 32)->nullable();
            $table->boolean('is_canonical')->default(false);       // the family's base unit (factor 1)
            $table->string('conversion_strategy', 16);             // linear | fahrenheit | none (App\Enums\ConversionStrategy)
            $table->decimal('to_canonical_factor', 30, 12)->nullable(); // linear: canonical = value * factor (exact decimal)
            $table->unsignedTinyInteger('decimal_places')->default(2);  // display precision hint
            $table->boolean('integer_only')->default(false);       // discrete units (heads, eggs): fractions rejected
            $table->boolean('is_system')->default(true);           // system units are protected (no farm unit exists yet)
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);           // selectable for NEW entries; history stays resolvable
            $table->timestamps();

            $table->index(['dimension_id', 'is_active', 'sort_order']);
            $table->index('family');
        });

        Schema::create('farm_unit_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained('farms')->cascadeOnDelete();
            $table->foreignUuid('dimension_id')->constrained('measurement_dimensions')->restrictOnDelete();
            $table->foreignUuid('unit_id')->constrained('units')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['farm_id', 'dimension_id']);
        });

        // "In THIS context, 1 <package unit> = <quantity_per_package> <target unit>". Farm-scoped, never universal.
        Schema::create('package_conversions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained('farms')->cascadeOnDelete();
            $table->string('context_type', 32);   // App\Enums\ConversionContextType (custom | crop_type; inventory items later)
            $table->string('context_key', 64);    // crop type id, or slug of the farm-defined label
            $table->string('context_label');      // display name of the context (Maize, Feed Grower Mash, Eggs)
            $table->foreignUuid('package_unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignUuid('target_unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('quantity_per_package', 18, 6);
            $table->unsignedInteger('version')->default(1); // bumped whenever the meaning changes; recorded in snapshots
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // One definition per package unit per context; changes are versioned in place, never duplicated.
            $table->unique(['farm_id', 'context_type', 'context_key', 'package_unit_id'], 'package_conversions_context_unique');
            $table->index(['farm_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_conversions');
        Schema::dropIfExists('farm_unit_preferences');
        Schema::dropIfExists('units');
        Schema::dropIfExists('measurement_dimensions');
    }
};
