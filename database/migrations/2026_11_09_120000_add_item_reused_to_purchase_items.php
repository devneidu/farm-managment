<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            // Only set when the line named its stock with new_item: true = an existing item was reused, false = the purchase created it.
            $table->boolean('item_reused')->nullable()->after('inventory_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropColumn('item_reused');
        });
    }
};
