<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Gives every package conversion a durable context identity. Before: `context_key` was the slug of client-typed text
 * (a display label used as identity) and the label was copied onto every row. After: a conversion points at
 * `(context_type, context_id)` where the id is a crop type id, a `measurement_contexts` id (farm-defined "Feed Grower
 * Mash" before inventory exists) or, later, an inventory item id. The label is looked up, never stored on the conversion.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A farm-defined thing that packages are measured against, until real domain entities (inventory items) exist.
        Schema::create('measurement_contexts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained('farms')->cascadeOnDelete();
            $table->string('name');                     // display only: may be renamed at any time
            $table->string('normalized_name');          // duplicate-detection key (trim, collapse spaces, case-fold)
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['farm_id', 'normalized_name'], 'measurement_contexts_farm_name_unique');
        });

        Schema::table('package_conversions', function (Blueprint $table) {
            // Polymorphic by design (crop_types / measurement_contexts / inventory items later): validated in the service, no FK.
            $table->uuid('context_id')->nullable()->after('context_type');
        });

        $this->backfill();

        Schema::table('package_conversions', function (Blueprint $table) {
            $table->uuid('context_id')->nullable(false)->change();
            $table->unique(['farm_id', 'context_type', 'context_id', 'package_unit_id'], 'package_conversions_context_id_unique');
        });

        Schema::table('package_conversions', function (Blueprint $table) {
            $table->dropUnique('package_conversions_context_unique');
            $table->dropColumn(['context_key', 'context_label']);
        });
    }

    public function down(): void
    {
        Schema::table('package_conversions', function (Blueprint $table) {
            $table->string('context_key', 64)->nullable()->after('context_type');
            $table->string('context_label')->nullable()->after('context_key');
        });

        foreach (DB::table('package_conversions')->get() as $row) {
            $custom = $row->context_type === 'custom' ? DB::table('measurement_contexts')->find($row->context_id) : null;
            $crop = $row->context_type === 'crop_type' ? DB::table('crop_types')->find($row->context_id) : null;

            DB::table('package_conversions')->where('id', $row->id)->update([
                'context_key' => $custom ? Str::slug($custom->name) : $row->context_id,
                'context_label' => $custom->name ?? $crop->name ?? 'Unknown',
            ]);
        }

        Schema::table('package_conversions', function (Blueprint $table) {
            $table->string('context_key', 64)->nullable(false)->change();
            $table->string('context_label')->nullable(false)->change();
            $table->unique(['farm_id', 'context_type', 'context_key', 'package_unit_id'], 'package_conversions_context_unique');
        });

        Schema::table('package_conversions', function (Blueprint $table) {
            $table->dropUnique('package_conversions_context_id_unique');
            $table->dropColumn('context_id');
        });

        Schema::dropIfExists('measurement_contexts');
    }

    /** Existing rows: crop contexts keep their crop id; each distinct (farm, custom key) becomes one measurement context. */
    private function backfill(): void
    {
        $created = [];

        foreach (DB::table('package_conversions')->orderBy('created_at')->orderBy('id')->get() as $row) {
            if ($row->context_type !== 'custom') {
                DB::table('package_conversions')->where('id', $row->id)->update(['context_id' => $row->context_key]);

                continue;
            }

            $key = $row->farm_id.'|'.$row->context_key;
            if (! isset($created[$key])) {
                $created[$key] = (string) Str::uuid7();
                DB::table('measurement_contexts')->insert([
                    'id' => $created[$key], 'farm_id' => $row->farm_id, 'name' => $row->context_label,
                    'normalized_name' => Str::lower(trim(preg_replace('/\s+/u', ' ', $row->context_label))),
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            DB::table('package_conversions')->where('id', $row->id)->update(['context_id' => $created[$key]]);
        }
    }
};
