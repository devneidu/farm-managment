<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Withdrawal metadata of a medicine item. Mutable on purpose: every health line snapshots the effective days it used.
        Schema::create('medicine_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->uuid('inventory_item_id')->unique();
            $t->unsignedSmallInteger('default_withdrawal_days')->nullable();
            $t->text('notes')->nullable();
            $t->foreignUuid('updated_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->foreign(['farm_id', 'inventory_item_id'])->references(['farm_id', 'id'])->on('inventory_items')->restrictOnDelete();
        });

        Schema::create('health_records', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->uuid('production_cycle_id');
            $t->string('type', 24);
            $t->json('details');
            $t->unsignedBigInteger('animals_affected')->nullable();
            $t->date('follow_up_on')->nullable();
            $t->uuid('mortality_record_id')->nullable();
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
            $t->unique(['farm_id', 'production_cycle_id', 'id'], 'health_farm_cycle_id_unique');
            $t->unique(['farm_id', 'idempotency_key']);
            $t->index(['farm_id', 'production_cycle_id', 'recorded_at'], 'health_cycle_recorded_index');
            $t->index(['farm_id', 'recorded_at']);
            $t->foreign(['farm_id', 'production_cycle_id'])->references(['farm_id', 'id'])->on('production_cycles')->restrictOnDelete();
            $t->foreign(['farm_id', 'production_cycle_id', 'mortality_record_id'], 'health_mortality_fk')->references(['farm_id', 'production_cycle_id', 'id'])->on('operational_records')->restrictOnDelete();
            foreach (['reverses_record_id', 'corrects_record_id'] as $column) {
                $t->foreign(['farm_id', 'production_cycle_id', $column], 'health_'.$column.'_fk')->references(['farm_id', 'production_cycle_id', 'id'])->on('health_records')->restrictOnDelete();
            }
        });

        Schema::create('health_record_medicines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->uuid('health_record_id');
            $t->unsignedTinyInteger('line_no');
            $t->uuid('inventory_item_id');
            $t->string('item_name', 150);
            $t->uuid('storage_location_id');
            $t->uuid('inventory_lot_id')->nullable();
            $t->decimal('quantity_used', 24, 6);
            $t->json('measurement');
            $t->json('dose')->nullable();
            $t->string('dosage_instructions', 500)->nullable();
            $t->unsignedSmallInteger('withdrawal_days')->nullable();
            $t->string('withdrawal_source', 16)->nullable();
            $t->dateTime('withdrawal_ends_at')->nullable();
            $t->timestamps();
            $t->unique(['health_record_id', 'line_no']);
            $t->unique(['farm_id', 'id']);
            $t->index(['farm_id', 'withdrawal_ends_at']);
            $t->foreign(['farm_id', 'health_record_id'])->references(['farm_id', 'id'])->on('health_records')->restrictOnDelete();
            $t->foreign(['farm_id', 'inventory_item_id'])->references(['farm_id', 'id'])->on('inventory_items')->restrictOnDelete();
            $t->foreign(['farm_id', 'storage_location_id'])->references(['farm_id', 'id'])->on('storage_locations')->restrictOnDelete();
            $t->foreign(['farm_id', 'inventory_item_id', 'inventory_lot_id'], 'health_lines_lot_fk')->references(['farm_id', 'inventory_item_id', 'id'])->on('inventory_lots')->restrictOnDelete();
        });

        // A medicine line consumes stock exactly once (unique); a reversal movement points at the reversing health record.
        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->uuid('health_record_medicine_id')->nullable()->unique();
            $t->uuid('health_record_id')->nullable()->index();
            $t->foreign(['farm_id', 'health_record_medicine_id'], 'inventory_movements_health_line_fk')->references(['farm_id', 'id'])->on('health_record_medicines')->restrictOnDelete();
            $t->foreign(['farm_id', 'health_record_id'], 'inventory_movements_health_record_fk')->references(['farm_id', 'id'])->on('health_records')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Destructive rollback removes this phase's stock effects together with their sources (Phase 9 stock-ins stay).
        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->dropForeign('inventory_movements_reverses_fk');
        });
        DB::table('inventory_movements')->whereNotNull('health_record_id')->orWhereNotNull('health_record_medicine_id')->delete();
        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->foreign(['farm_id', 'reverses_movement_id'], 'inventory_movements_reverses_fk')->references(['farm_id', 'id'])->on('inventory_movements')->restrictOnDelete();
            $t->dropForeign('inventory_movements_health_line_fk');
            $t->dropForeign('inventory_movements_health_record_fk');
            $t->dropColumn(['health_record_medicine_id', 'health_record_id']);
        });
        Schema::dropIfExists('health_record_medicines');
        Schema::table('health_records', function (Blueprint $t) {
            $t->dropForeign('health_reverses_record_id_fk');
            $t->dropForeign('health_corrects_record_id_fk');
        });
        Schema::dropIfExists('health_records');
        Schema::dropIfExists('medicine_profiles');
    }
};
