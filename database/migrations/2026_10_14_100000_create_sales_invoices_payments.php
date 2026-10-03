<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A sale is the commercial/operational event: what left the farm, to whom, for how much. It is NOT an invoice and not a payment.
        Schema::create('sales', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->string('reference', 20);
            $t->uuid('contact_id')->nullable();
            $t->string('customer_name', 150)->nullable();
            $t->uuid('finance_category_id')->nullable();
            $t->dateTime('recorded_at');
            $t->decimal('total_amount', 18, 2);
            $t->char('currency', 3);
            $t->string('status', 12)->default('active');
            $t->text('notes')->nullable();
            $t->uuid('corrects_sale_id')->nullable()->unique();
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
            $t->foreign('finance_category_id')->references('id')->on('finance_categories')->restrictOnDelete();
            $t->foreign('cancelled_by')->references('id')->on('users')->restrictOnDelete();
            $t->foreign(['farm_id', 'contact_id'])->references(['farm_id', 'id'])->on('contacts')->restrictOnDelete();
            $t->foreign(['farm_id', 'corrects_sale_id'], 'sales_corrects_fk')->references(['farm_id', 'id'])->on('sales')->restrictOnDelete();
        });

        // kind: stock (produce inventory -> Phase 9 stock-out), livestock (animals -> population ledger exit), other (no physical effect).
        Schema::create('sale_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->uuid('sale_id');
            $t->unsignedTinyInteger('line_no');
            $t->string('kind', 12);
            $t->string('description', 190);
            $t->uuid('inventory_item_id')->nullable();
            $t->uuid('storage_location_id')->nullable();
            $t->uuid('inventory_lot_id')->nullable();
            $t->uuid('production_cycle_id')->nullable();
            $t->unsignedInteger('head_count')->nullable();
            $t->decimal('quantity', 24, 6)->nullable();
            $t->json('measurement')->nullable();
            $t->decimal('amount', 18, 2);
            // The operational record whose population_delta removed the animals (livestock lines): at most one per line.
            $t->uuid('operational_record_id')->nullable()->unique();
            $t->timestamps();
            $t->unique(['sale_id', 'line_no']);
            $t->unique(['farm_id', 'id']);
            $t->foreign(['farm_id', 'sale_id'])->references(['farm_id', 'id'])->on('sales')->restrictOnDelete();
            $t->foreign(['farm_id', 'inventory_item_id'])->references(['farm_id', 'id'])->on('inventory_items')->restrictOnDelete();
            $t->foreign(['farm_id', 'storage_location_id'])->references(['farm_id', 'id'])->on('storage_locations')->restrictOnDelete();
            $t->foreign(['farm_id', 'inventory_item_id', 'inventory_lot_id'], 'sale_items_lot_fk')->references(['farm_id', 'inventory_item_id', 'id'])->on('inventory_lots')->restrictOnDelete();
            $t->foreign(['farm_id', 'production_cycle_id'])->references(['farm_id', 'id'])->on('production_cycles')->restrictOnDelete();
            $t->foreign('operational_record_id')->references('id')->on('operational_records')->restrictOnDelete();
        });

        // An invoice is the customer-facing document, snapshotted when issued. live_sale_key = sale id while issued, NULL once void:
        // the unique key is the database-level "one live invoice per sale" guard.
        Schema::create('invoices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->uuid('sale_id');
            $t->string('reference', 20);
            $t->string('status', 10)->default('issued');
            $t->uuid('live_sale_key')->nullable();
            $t->date('issue_date');
            $t->date('due_date')->nullable();
            $t->uuid('contact_id')->nullable();
            $t->string('customer_name', 150)->nullable();
            $t->string('customer_phone', 40)->nullable();
            $t->string('customer_email', 190)->nullable();
            $t->string('customer_address', 500)->nullable();
            $t->string('seller_name', 150);
            $t->decimal('total_amount', 18, 2);
            $t->char('currency', 3);
            $t->text('notes')->nullable();
            $t->dateTime('voided_at')->nullable();
            $t->text('void_reason')->nullable();
            $t->uuid('voided_by')->nullable();
            $t->string('void_idempotency_key', 80)->nullable()->collation('utf8mb4_bin');
            $t->char('void_request_hash', 64)->nullable();
            $t->string('idempotency_key', 80)->nullable()->collation('utf8mb4_bin');
            $t->char('request_hash', 64)->nullable();
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->string('request_id', 100)->nullable();
            $t->timestamps();
            $t->unique(['farm_id', 'id']);
            $t->unique(['farm_id', 'reference']);
            $t->unique(['farm_id', 'live_sale_key']);
            $t->unique(['farm_id', 'idempotency_key']);
            $t->unique(['farm_id', 'void_idempotency_key']);
            $t->index(['farm_id', 'issue_date']);
            $t->index(['farm_id', 'contact_id']);
            $t->foreign('voided_by')->references('id')->on('users')->restrictOnDelete();
            $t->foreign(['farm_id', 'sale_id'])->references(['farm_id', 'id'])->on('sales')->restrictOnDelete();
            $t->foreign(['farm_id', 'contact_id'])->references(['farm_id', 'id'])->on('contacts')->restrictOnDelete();
        });

        Schema::create('invoice_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->uuid('invoice_id');
            $t->unsignedTinyInteger('line_no');
            $t->uuid('sale_item_id');
            $t->string('kind', 12);
            $t->string('description', 190);
            $t->string('quantity_label', 120)->nullable();
            $t->decimal('amount', 18, 2);
            $t->timestamps();
            $t->unique(['invoice_id', 'line_no']);
            $t->foreign(['farm_id', 'invoice_id'])->references(['farm_id', 'id'])->on('invoices')->restrictOnDelete();
            $t->foreign(['farm_id', 'sale_item_id'])->references(['farm_id', 'id'])->on('sale_items')->restrictOnDelete();
        });

        // Money actually received against an invoice. Append-only: a reversal is another row that offsets the original.
        Schema::create('payments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->uuid('invoice_id');
            $t->string('reference', 20);
            $t->string('entry_type', 10)->default('payment');
            $t->decimal('amount', 18, 2);
            $t->char('currency', 3);
            $t->string('method', 20)->nullable();
            $t->string('payment_reference', 100)->nullable();
            $t->date('received_on');
            $t->dateTime('recorded_at');
            $t->text('notes')->nullable();
            $t->uuid('reverses_payment_id')->nullable()->unique();
            $t->text('reason')->nullable();
            $t->uuid('finance_transaction_id')->nullable()->unique();
            $t->string('idempotency_key', 80)->collation('utf8mb4_bin');
            $t->char('request_hash', 64);
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->string('request_id', 100)->nullable();
            $t->timestamps();
            $t->unique(['farm_id', 'id']);
            $t->unique(['farm_id', 'reference']);
            $t->unique(['farm_id', 'idempotency_key']);
            $t->index(['farm_id', 'invoice_id']);
            $t->index(['farm_id', 'received_on']);
            $t->foreign(['farm_id', 'invoice_id'])->references(['farm_id', 'id'])->on('invoices')->restrictOnDelete();
            $t->foreign(['farm_id', 'reverses_payment_id'], 'payments_reverses_fk')->references(['farm_id', 'id'])->on('payments')->restrictOnDelete();
            $t->foreign(['farm_id', 'finance_transaction_id'])->references(['farm_id', 'id'])->on('finance_transactions')->restrictOnDelete();
        });

        // A sold stock line leaves inventory exactly once (unique); a reversal movement points at the cancelled sale.
        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->uuid('sale_item_id')->nullable()->unique();
            $t->uuid('sale_id')->nullable()->index();
            $t->foreign(['farm_id', 'sale_item_id'], 'inventory_movements_sale_item_fk')->references(['farm_id', 'id'])->on('sale_items')->restrictOnDelete();
            $t->foreign(['farm_id', 'sale_id'], 'inventory_movements_sale_fk')->references(['farm_id', 'id'])->on('sales')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Destructive rollback: payment income rows and sale stock effects go together with their sources; unrelated rows stay.
        $paymentTx = DB::table('payments')->whereNotNull('finance_transaction_id')->pluck('finance_transaction_id')->all();
        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->dropForeign('inventory_movements_reverses_fk');
        });
        DB::table('inventory_movements')->whereNotNull('sale_id')->orWhereNotNull('sale_item_id')->delete();
        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->foreign(['farm_id', 'reverses_movement_id'], 'inventory_movements_reverses_fk')->references(['farm_id', 'id'])->on('inventory_movements')->restrictOnDelete();
            $t->dropForeign('inventory_movements_sale_item_fk');
            $t->dropForeign('inventory_movements_sale_fk');
            $t->dropColumn(['sale_item_id', 'sale_id']);
        });

        Schema::table('payments', function (Blueprint $t) {
            $t->dropForeign('payments_reverses_fk');
            $t->dropForeign(['farm_id', 'finance_transaction_id']);
        });
        Schema::dropIfExists('payments');
        if ($paymentTx !== []) {
            Schema::table('finance_transactions', function (Blueprint $t) {
                $t->dropForeign('finance_reverses_transaction_id_fk');
                $t->dropForeign('finance_corrects_transaction_id_fk');
            });
            foreach (array_chunk($paymentTx, 500) as $chunk) {
                DB::table('finance_transactions')->whereIn('id', $chunk)->orWhereIn('reverses_transaction_id', $chunk)->delete();
            }
            Schema::table('finance_transactions', function (Blueprint $t) {
                $t->foreign(['farm_id', 'reverses_transaction_id'], 'finance_reverses_transaction_id_fk')->references(['farm_id', 'id'])->on('finance_transactions')->restrictOnDelete();
                $t->foreign(['farm_id', 'corrects_transaction_id'], 'finance_corrects_transaction_id_fk')->references(['farm_id', 'id'])->on('finance_transactions')->restrictOnDelete();
            });
        }
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('sale_items');
        Schema::table('sales', function (Blueprint $t) {
            $t->dropForeign('sales_corrects_fk');
        });
        Schema::dropIfExists('sales');
    }
};
