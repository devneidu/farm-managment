<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Steps speak the task form's language: day-based reminder presets and the way a repeat ends.
        Schema::table('work_template_items', function (Blueprint $t) {
            // 'batch_end' (while the batch is open) or 'date' (ends_on); null otherwise ("days" is until_offset_days, "never" is all null).
            $t->string('ends_type', 10)->nullable()->after('occurrence_limit');
            $t->date('ends_on')->nullable()->after('ends_type');
            $t->json('reminder_presets')->nullable()->after('reminder_offsets');
        });

        // A routine can be applied to a batch or to nothing (general), and more than once; it can also be removed again.
        Schema::table('template_applications', function (Blueprint $t) {
            $t->dropUnique('template_applications_target_unique');
        });
        Schema::table('template_applications', function (Blueprint $t) {
            $t->uuid('production_cycle_id')->nullable()->change();
            $t->date('start_date')->nullable()->after('target_key');
            $t->string('status', 10)->default('active')->after('start_date');
            $t->unsignedInteger('tasks_cancelled')->default(0)->after('skipped_no_anchor');
            $t->dateTime('removed_at')->nullable()->after('status');
            $t->uuid('removed_by')->nullable()->after('removed_at');
            $t->index(['farm_id', 'work_template_id', 'target_key', 'status'], 'template_applications_target_index');
        });
        DB::table('template_applications')->update(['start_date' => DB::raw('DATE(created_at)')]);
    }

    public function down(): void
    {
        Schema::table('template_applications', function (Blueprint $t) {
            $t->dropIndex('template_applications_target_index');
            $t->dropColumn(['start_date', 'status', 'tasks_cancelled', 'removed_at', 'removed_by']);
        });
        Schema::table('work_template_items', fn (Blueprint $t) => $t->dropColumn(['ends_type', 'ends_on', 'reminder_presets']));
    }
};
