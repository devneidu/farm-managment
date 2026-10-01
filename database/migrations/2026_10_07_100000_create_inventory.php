<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->string('name', 150);
            $t->string('normalized_name', 150)->collation('utf8mb4_bin');
            $t->string('category', 40);
            $t->foreignUuid('stock_unit_id')->constrained('units')->restrictOnDelete();
            $t->boolean('tracks_lots')->default(false);
            $t->boolean('tracks_expiry')->default(false);
            // Canonical-unit threshold. There is intentionally NO quantity column: stock is SUM(inventory_movements).
            $t->decimal('low_stock_threshold', 24, 6)->nullable();
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['farm_id', 'id']);
            $t->unique(['farm_id', 'normalized_name']);
            $t->index(['farm_id', 'category', 'is_active']);
        });

        Schema::create('inventory_lots', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->uuid('inventory_item_id');
            $t->string('code', 100);
            $t->string('normalized_code', 100)->collation('utf8mb4_bin');
            $t->date('expires_on')->nullable();
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['inventory_item_id', 'normalized_code']);
            $t->unique(['farm_id', 'inventory_item_id', 'id'], 'inventory_lots_farm_item_id_unique');
            $t->index(['farm_id', 'expires_on']);
            $t->foreign(['farm_id', 'inventory_item_id'])->references(['farm_id', 'id'])->on('inventory_items')->restrictOnDelete();
        });

        Schema::create('inventory_movements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->uuid('inventory_item_id');
            $t->uuid('storage_location_id');
            $t->uuid('inventory_lot_id')->nullable();
            $t->string('type', 20);
            $t->string('reason', 30)->nullable();
            $t->text('justification')->nullable();
            $t->decimal('quantity_delta', 24, 6);
            $t->json('measurement');
            $t->dateTime('recorded_at');
            $t->text('notes')->nullable();
            $t->uuid('operational_record_id')->nullable()->unique();
            $t->uuid('transfer_group_id')->nullable()->index();
            $t->uuid('reverses_movement_id')->nullable()->unique();
            $t->string('idempotency_key', 100)->nullable()->collation('utf8mb4_bin');
            $t->char('request_hash', 64)->nullable();
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->string('request_id', 100)->nullable();
            $t->timestamps();
            $t->unique(['farm_id', 'id']);
            $t->unique(['farm_id', 'idempotency_key', 'type'], 'inventory_movements_idempotency_unique');
            $t->index(['inventory_item_id', 'storage_location_id', 'inventory_lot_id', 'recorded_at'], 'inventory_movements_bucket_index');
            $t->index(['farm_id', 'recorded_at']);
            $t->foreign(['farm_id', 'inventory_item_id'])->references(['farm_id', 'id'])->on('inventory_items')->restrictOnDelete();
            $t->foreign(['farm_id', 'storage_location_id'])->references(['farm_id', 'id'])->on('storage_locations')->restrictOnDelete();
            $t->foreign(['farm_id', 'inventory_item_id', 'inventory_lot_id'], 'inventory_movements_lot_fk')->references(['farm_id', 'inventory_item_id', 'id'])->on('inventory_lots')->restrictOnDelete();
            $t->foreign(['farm_id', 'operational_record_id'], 'inventory_movements_record_fk')->references(['farm_id', 'id'])->on('operational_records')->restrictOnDelete();
            $t->foreign(['farm_id', 'reverses_movement_id'], 'inventory_movements_reverses_fk')->references(['farm_id', 'id'])->on('inventory_movements')->restrictOnDelete();
        });

        // Formulas describe a recipe. They never hold or change stock.
        Schema::create('feed_formulas', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->string('name', 150);
            $t->string('normalized_name', 150)->collation('utf8mb4_bin');
            $t->uuid('species_id')->nullable();
            $t->text('description')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->boolean('is_active')->default(true);
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['farm_id', 'id']);
            $t->unique(['farm_id', 'normalized_name']);
            $t->foreign('species_id')->references('id')->on('species')->restrictOnDelete();
        });

        Schema::create('feed_formula_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->uuid('feed_formula_id');
            $t->uuid('inventory_item_id')->nullable();
            $t->string('ingredient_name', 150);
            $t->decimal('inclusion_percent', 7, 4);
            $t->timestamps();
            $t->foreign(['farm_id', 'feed_formula_id'])->references(['farm_id', 'id'])->on('feed_formulas')->cascadeOnDelete();
            $t->foreign(['farm_id', 'inventory_item_id'])->references(['farm_id', 'id'])->on('inventory_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_formula_items');
        Schema::dropIfExists('feed_formulas');
        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->dropForeign('inventory_movements_reverses_fk');
        });
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_lots');
        Schema::dropIfExists('inventory_items');
    }
};
