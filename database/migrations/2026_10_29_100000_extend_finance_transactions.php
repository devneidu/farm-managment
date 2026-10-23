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
        // How the money moved (same codes as payments.method) and the optional quantity x unit price an amount came from. No backfill.
        Schema::table('finance_transactions', function (Blueprint $t) {
            $t->string('payment_method', 20)->nullable()->after('contact_id');
            $t->decimal('quantity', 24, 6)->nullable()->after('amount');
            $t->decimal('unit_price', 18, 2)->nullable()->after('quantity');
            $t->index(['farm_id', 'payment_method']);
        });

        // Adds the new platform income categories (services, grants_loans_support); existing rows are left untouched.
        (new FinanceCategorySeeder)->run();
    }

    public function down(): void
    {
        $unused = DB::table('finance_categories')->whereNull('farm_id')->whereIn('code', ['services', 'grants_loans_support'])
            ->whereNotIn('id', DB::table('finance_transactions')->select('finance_category_id'))->pluck('id')->all();
        DB::table('finance_categories')->whereIn('id', $unused)->delete();
        Schema::table('finance_transactions', function (Blueprint $t) {
            $t->dropIndex(['farm_id', 'payment_method']);
            $t->dropColumn(['payment_method', 'quantity', 'unit_price']);
        });
    }
};
