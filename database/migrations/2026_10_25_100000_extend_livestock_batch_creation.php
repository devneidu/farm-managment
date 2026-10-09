<?php

use Database\Seeders\LivestockBatchReferenceSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('species', function (Blueprint $table) {
            $table->string('breed_field_label', 64)->nullable();
        });
        Schema::table('livestock_batch_details', function (Blueprint $table) {
            // Nullable for historical batches; only new livestock requests require a purpose.
            $table->foreignUuid('production_purpose_id')->nullable()->constrained('reference_values')->restrictOnDelete();
            $table->foreignUuid('growth_stage_id')->nullable()->constrained('reference_values')->restrictOnDelete();
            $table->decimal('acquisition_price_per_animal', 18, 2)->nullable();
            $table->foreignUuid('supplier_contact_id')->nullable()->constrained('contacts')->restrictOnDelete();
        });
        (new LivestockBatchReferenceSeeder)->run();
    }

    public function down(): void
    {
        // Never discard acquisition metadata already attached to a real batch.
        if (DB::table('livestock_batch_details')->whereNotNull('production_purpose_id')->orWhereNotNull('growth_stage_id')
            ->orWhereNotNull('acquisition_price_per_animal')->orWhereNotNull('supplier_contact_id')->exists()) {
            throw new RuntimeException('Cannot roll back livestock batch creation while acquisition metadata is in use.');
        }
        Schema::table('livestock_batch_details', function (Blueprint $table) {
            $table->dropConstrainedForeignId('production_purpose_id');
            $table->dropConstrainedForeignId('growth_stage_id');
            $table->dropConstrainedForeignId('supplier_contact_id');
            $table->dropColumn('acquisition_price_per_animal');
        });
        Schema::table('species', fn (Blueprint $table) => $table->dropColumn('breed_field_label'));
        // System breeds and reference rows remain: existing UUIDs/history must survive rollback.
    }
};
