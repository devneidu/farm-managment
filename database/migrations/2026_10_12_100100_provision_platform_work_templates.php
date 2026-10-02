<?php

use Database\Seeders\WorkTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/** Seeds the platform starter templates so production databases have them after `migrate` without a manual seeding step. */
return new class extends Migration
{
    public function up(): void
    {
        (new WorkTemplateSeeder)->run();
    }

    public function down(): void
    {
        // Seed rows are removed with the work tables by the preceding migration's rollback; farms may already have applied them.
    }
};
