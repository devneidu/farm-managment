<?php

use Database\Seeders\LivestockBatchReferenceSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Shows "Dual purpose (eggs & meat)" instead of "Dual purpose"; codes, ids and edited names are untouched. */
    public function up(): void
    {
        (new LivestockBatchReferenceSeeder)->run();
    }

    public function down(): void
    {
        // Display names only; nothing to undo.
    }
};
