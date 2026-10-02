<?php

use Database\Seeders\FinanceCategorySeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->string('name', 150);
            $t->string('normalized_name', 150);
            $t->string('kind', 12)->default('person');
            $t->boolean('is_supplier')->default(false);
            $t->boolean('is_customer')->default(false);
            $t->string('phone', 40)->nullable();
            $t->string('email', 190)->nullable();
            $t->string('address', 500)->nullable();
            $t->text('notes')->nullable();
            $t->boolean('is_active')->default(true);
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['farm_id', 'id']);
            $t->unique(['farm_id', 'normalized_name']);
            $t->index(['farm_id', 'is_supplier']);
            $t->index(['farm_id', 'is_customer']);
        });

        // farm_id NULL = platform category; a farm only ever sees platform rows plus its own.
        Schema::create('finance_categories', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('code', 60);
            $t->string('name', 120);
            $t->string('direction', 8);
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
            $t->index(['direction', 'sort_order']);
            $t->index(['farm_id', 'code']);
        });

        Schema::create('purchases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->string('reference', 20);
            $t->uuid('contact_id')->nullable();
            $t->uuid('production_cycle_id')->nullable();
            $t->uuid('finance_category_id')->nullable();
            $t->string('supplier_reference', 100)->nullable();
            $t->dateTime('recorded_at');
            $t->decimal('total_amount', 18, 2);
            $t->char('currency', 3);
            $t->string('status', 12)->default('active');
            $t->boolean('records_expense')->default(true);
            $t->text('notes')->nullable();
            $t->uuid('corrects_purchase_id')->nullable()->unique();
            $t->dateTime('cancelled_at')->nullable();
            $t->text('cancel_reason')->nullable();
            $t->uuid('cancelled_by')->nullable();
            $t->string('cancel_idempotency_key', 80)->nullable()->collation('utf8mb4_bin');
            $t->char('cancel_request_hash', 64)->nullable();
            $t->string('idempotency_key', 80)->collation('utf8mb4_bin');
            $t->char('request_hash', 64);
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->string('request_id', 100)->nullable();
            $t->timestamps();
            $t->unique(['farm_id', 'id']);
            $t->unique(['farm_id', 'reference']);
            $t->unique(['farm_id', 'idempotency_key']);
            $t->unique(['farm_id', 'cancel_idempotency_key']);
            $t->index(['farm_id', 'recorded_at']);
            $t->index(['farm_id', 'contact_id']);
            $t->index(['farm_id', 'production_cycle_id']);
            $t->foreign('finance_category_id')->references('id')->on('finance_categories')->restrictOnDelete();
            $t->foreign('cancelled_by')->references('id')->on('users')->restrictOnDelete();
            $t->foreign(['farm_id', 'contact_id'])->references(['farm_id', 'id'])->on('contacts')->restrictOnDelete();
            $t->foreign(['farm_id', 'production_cycle_id'])->references(['farm_id', 'id'])->on('production_cycles')->restrictOnDelete();
            $t->foreign(['farm_id', 'corrects_purchase_id'], 'purchases_corrects_fk')->references(['farm_id', 'id'])->on('purchases')->restrictOnDelete();
        });

        Schema::create('purchase_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->uuid('purchase_id');
            $t->unsignedTinyInteger('line_no');
            $t->string('kind', 12);
            $t->string('description', 190);
            $t->uuid('inventory_item_id')->nullable();
            $t->uuid('storage_location_id')->nullable();
            $t->uuid('inventory_lot_id')->nullable();
            $t->decimal('quantity', 24, 6)->nullable();
            $t->json('measurement')->nullable();
            $t->decimal('amount', 18, 2);
            $t->timestamps();
            $t->unique(['purchase_id', 'line_no']);
            $t->unique(['farm_id', 'id']);
            $t->foreign(['farm_id', 'purchase_id'])->references(['farm_id', 'id'])->on('purchases')->restrictOnDelete();
            $t->foreign(['farm_id', 'inventory_item_id'])->references(['farm_id', 'id'])->on('inventory_items')->restrictOnDelete();
            $t->foreign(['farm_id', 'storage_location_id'])->references(['farm_id', 'id'])->on('storage_locations')->restrictOnDelete();
            $t->foreign(['farm_id', 'inventory_item_id', 'inventory_lot_id'], 'purchase_items_lot_fk')->references(['farm_id', 'inventory_item_id', 'id'])->on('inventory_lots')->restrictOnDelete();
        });

        // The money ledger: append-only. A reversal is another row (entry_type = reversal) that offsets the original in every sum.
        Schema::create('finance_transactions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->string('reference', 20);
            $t->string('entry_type', 10)->default('entry');
            $t->string('direction', 8);
            $t->uuid('finance_category_id');
            $t->decimal('amount', 18, 2);
            $t->char('currency', 3);
            $t->date('occurred_on');
            $t->dateTime('recorded_at');
            $t->uuid('contact_id')->nullable();
            $t->uuid('production_cycle_id')->nullable();
            $t->string('description', 500)->nullable();
            $t->string('source_type', 24)->nullable();
            $t->uuid('source_id')->nullable();
            // "<type>:<id>" of the FIRST entry for a source: the database-level duplicate guard (a later entry after a reversal leaves it null).
            $t->string('source_key', 80)->nullable();
            $t->uuid('reverses_transaction_id')->nullable()->unique();
            $t->uuid('corrects_transaction_id')->nullable()->unique();
            $t->text('reason')->nullable();
            $t->string('idempotency_key', 80)->nullable()->collation('utf8mb4_bin');
            $t->char('request_hash', 64)->nullable();
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->string('request_id', 100)->nullable();
            $t->timestamps();
            $t->unique(['farm_id', 'id']);
            $t->unique(['farm_id', 'reference']);
            $t->unique(['farm_id', 'source_key']);
            $t->unique(['farm_id', 'idempotency_key']);
            $t->index(['farm_id', 'direction', 'occurred_on']);
            $t->index(['farm_id', 'production_cycle_id']);
            $t->index(['farm_id', 'source_type', 'source_id']);
            $t->foreign('finance_category_id')->references('id')->on('finance_categories')->restrictOnDelete();
            $t->foreign(['farm_id', 'contact_id'])->references(['farm_id', 'id'])->on('contacts')->restrictOnDelete();
            $t->foreign(['farm_id', 'production_cycle_id'])->references(['farm_id', 'id'])->on('production_cycles')->restrictOnDelete();
            foreach (['reverses_transaction_id', 'corrects_transaction_id'] as $column) {
                $t->foreign(['farm_id', $column], 'finance_'.$column.'_fk')->references(['farm_id', 'id'])->on('finance_transactions')->restrictOnDelete();
            }
        });

        // A purchase line receives stock exactly once (unique); a reversal movement points at the cancelled purchase.
        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->uuid('purchase_item_id')->nullable()->unique();
            $t->uuid('purchase_id')->nullable()->index();
            $t->foreign(['farm_id', 'purchase_item_id'], 'inventory_movements_purchase_item_fk')->references(['farm_id', 'id'])->on('purchase_items')->restrictOnDelete();
            $t->foreign(['farm_id', 'purchase_id'], 'inventory_movements_purchase_fk')->references(['farm_id', 'id'])->on('purchases')->restrictOnDelete();
        });

        (new FinanceCategorySeeder)->run();
    }

    public function down(): void
    {
        // Destructive rollback removes this phase's stock effects together with their sources (other stock-ins stay).
        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->dropForeign('inventory_movements_reverses_fk');
        });
        DB::table('inventory_movements')->whereNotNull('purchase_id')->orWhereNotNull('purchase_item_id')->delete();
        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->foreign(['farm_id', 'reverses_movement_id'], 'inventory_movements_reverses_fk')->references(['farm_id', 'id'])->on('inventory_movements')->restrictOnDelete();
            $t->dropForeign('inventory_movements_purchase_item_fk');
            $t->dropForeign('inventory_movements_purchase_fk');
            $t->dropColumn(['purchase_item_id', 'purchase_id']);
        });
        Schema::table('finance_transactions', function (Blueprint $t) {
            $t->dropForeign('finance_reverses_transaction_id_fk');
            $t->dropForeign('finance_corrects_transaction_id_fk');
        });
        Schema::dropIfExists('finance_transactions');
        Schema::dropIfExists('purchase_items');
        Schema::table('purchases', function (Blueprint $t) {
            $t->dropForeign('purchases_corrects_fk');
        });
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('finance_categories');
        Schema::dropIfExists('contacts');
    }
};
