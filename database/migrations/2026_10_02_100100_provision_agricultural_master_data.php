<?php

use Database\Seeders\MasterDataSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Data migration: provisions the initial system master data (insert-only, keyed by stable codes) so local, test and
 * production databases have it after `migrate` without a manual seeding step.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new MasterDataSeeder)->run();
    }

    public function down(): void
    {
        // Rows are removed with the tables by the previous migration's rollback.
    }
};
