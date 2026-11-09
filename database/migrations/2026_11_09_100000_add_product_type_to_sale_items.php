<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A typed product line (kind other, farm sells without the store): what kind of product it was, so the income category follows it.
        // Null on every existing line. The entered quantity of an `other` line is kept in the existing nullable `quantity` column.
        Schema::table('sale_items', function (Blueprint $t) {
            $t->string('product_type', 20)->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $t) {
            $t->dropColumn('product_type');
        });
    }
};
