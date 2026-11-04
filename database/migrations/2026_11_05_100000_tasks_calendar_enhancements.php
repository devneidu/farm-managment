<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $t) {
            // 'batch_end' = keep going while the cycle is open; null for every other schedule (the end is ends_on / occurrence_limit / none).
            $t->string('ends_type', 10)->nullable()->after('occurrence_limit');
            // Day-based reminder presets (on_the_day, 1_day, 2_days, 7_days); reminder_offsets (minutes) keeps working beside them.
            $t->json('reminder_presets')->nullable()->after('reminder_offsets');
        });
        Schema::table('tasks', function (Blueprint $t) {
            $t->json('reminder_presets')->nullable()->after('reminder_offsets');
        });

        // One-way ICS subscription per user and farm. Lookup is by the SHA-256 of the secret token; an encrypted copy lets the app show the link again.
        Schema::create('calendar_feeds', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->char('token_hash', 64)->nullable()->unique();
            $t->text('token_encrypted')->nullable();
            $t->boolean('enabled')->default(false);
            $t->boolean('include_milestones')->default(false);
            $t->timestamps();
            $t->unique(['farm_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_feeds');
        Schema::table('tasks', fn (Blueprint $t) => $t->dropColumn('reminder_presets'));
        Schema::table('schedules', fn (Blueprint $t) => $t->dropColumn(['ends_type', 'reminder_presets']));
    }
};
