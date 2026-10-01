<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_records', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->uuid('production_cycle_id');
            $t->string('type', 40);
            $t->json('details');
            $t->json('measurement')->nullable();
            $t->bigInteger('population_delta')->default(0);
            $t->dateTime('recorded_at');
            $t->text('notes')->nullable();
            $t->uuid('reverses_record_id')->nullable()->unique();
            $t->uuid('corrects_record_id')->nullable()->unique();
            $t->string('idempotency_key', 80)->collation('utf8mb4_bin');
            $t->char('request_hash', 64);
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->string('request_id', 100)->nullable();
            $t->timestamps();
            $t->unique(['farm_id', 'id']);
            $t->unique(['farm_id', 'production_cycle_id', 'id'], 'records_farm_cycle_id_unique');
            $t->unique(['farm_id', 'idempotency_key']);
            $t->index(['farm_id', 'recorded_at']);
            $t->foreign(['farm_id', 'production_cycle_id'])->references(['farm_id', 'id'])->on('production_cycles')->restrictOnDelete();
            foreach (['reverses_record_id', 'corrects_record_id'] as $column) {
                $t->foreign(['farm_id', 'production_cycle_id', $column], 'records_'.$column.'_fk')->references(['farm_id', 'production_cycle_id', 'id'])->on('operational_records')->restrictOnDelete();
            }
        });
        Schema::table('population_movements', function (Blueprint $t) {
            $t->uuid('operational_record_id')->nullable()->unique();
            $t->foreign(['farm_id', 'production_cycle_id', 'operational_record_id'], 'population_record_fk')->references(['farm_id', 'production_cycle_id', 'id'])->on('operational_records')->restrictOnDelete();
        });
        Schema::create('record_attachments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->uuid('operational_record_id');
            $t->string('path');
            $t->string('original_name');
            $t->string('mime_type', 150);
            $t->unsignedBigInteger('size');
            $t->char('sha256', 64);
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['operational_record_id', 'sha256']);
            $t->foreign(['farm_id', 'operational_record_id'])->references(['farm_id', 'id'])->on('operational_records')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_attachments');
        // Destructive schema rollback removes this phase's effects alongside their sources, preserving initial baselines.
        DB::table('population_movements')->whereNotNull('operational_record_id')->delete();
        Schema::table('population_movements', function (Blueprint $t) {
            $t->dropForeign('population_record_fk');
            $t->dropColumn('operational_record_id');
        });
        Schema::table('operational_records', function (Blueprint $t) {
            $t->dropForeign('records_reverses_record_id_fk');
            $t->dropForeign('records_corrects_record_id_fk');
        });
        Schema::dropIfExists('operational_records');
    }
};
