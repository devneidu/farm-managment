<?php

use Database\Seeders\MeasurementSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Data migration: provisions the standard dimensions and units (insert-only, keyed by stable codes) so local, test and
 * production databases have them after `migrate` without a manual seeding step.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new MeasurementSeeder)->run();
    }

    public function down(): void
    {
        // Rows are removed with the tables by the previous migration's rollback.
    }
};
