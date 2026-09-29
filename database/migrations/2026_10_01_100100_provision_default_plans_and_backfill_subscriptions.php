<?php

use App\Models\Farm;
use App\Models\Subscription;
use App\Services\Subscription\SubscriptionService;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Data migration: provisions the initial plan catalogue (insert-only, so it never overwrites later admin
 * edits) and gives every existing farm a subscription on the default plan. This is how local, test and
 * production databases obtain plan data without a manual seeding step.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new PlanSeeder)->run();

        $service = app(SubscriptionService::class);

        Farm::query()
            ->whereNotIn('id', Subscription::query()->select('farm_id'))
            ->each(fn (Farm $farm) => $service->startDefault($farm));
    }

    public function down(): void
    {
        // Rows are removed with the tables by the previous migration's rollback; nothing to undo here.
    }
};
