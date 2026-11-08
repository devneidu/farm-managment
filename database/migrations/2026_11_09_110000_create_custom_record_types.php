<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // farm_id NULL = a public type (platform curated); set = one farm's own type. `scope` makes the name unique with NULL farm ids.
        Schema::create('custom_record_types', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('name', 80);
            $t->string('normalized_name', 80);
            $t->text('description')->nullable();
            $t->string('applies_to', 10); // livestock | crop | both
            $t->foreignUuid('species_id')->nullable()->constrained('species')->restrictOnDelete();
            $t->foreignUuid('crop_type_id')->nullable()->constrained('crop_types')->restrictOnDelete();
            $t->json('fields');
            $t->unsignedInteger('version')->default(1);
            $t->string('status', 10)->default('active'); // active | archived
            $t->string('source', 10)->default('farm'); // farm | public
            $t->uuid('copied_from_id')->nullable()->index();
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('scope', 36)->storedAs("coalesce(farm_id, 'public')");
            $t->timestamps();
            $t->unique(['scope', 'normalized_name'], 'custom_record_types_unique_name');
            $t->index(['farm_id', 'status']);
        });

        // Records of a farm-made type are type = 'custom'; the id lives in its own column, never in the 40-char type string.
        Schema::table('operational_records', function (Blueprint $t) {
            $t->foreignUuid('custom_record_type_id')->nullable()->after('type')->constrained('custom_record_types')->restrictOnDelete();
            $t->unsignedInteger('custom_schema_version')->nullable()->after('custom_record_type_id');
            $t->index(['farm_id', 'custom_record_type_id'], 'records_farm_custom_type_index');
        });
    }

    public function down(): void
    {
        Schema::table('operational_records', function (Blueprint $t) {
            $t->dropForeign(['custom_record_type_id']);
            $t->dropIndex('records_farm_custom_type_index');
            $t->dropColumn(['custom_record_type_id', 'custom_schema_version']);
        });
        Schema::dropIfExists('custom_record_types');
    }
};
