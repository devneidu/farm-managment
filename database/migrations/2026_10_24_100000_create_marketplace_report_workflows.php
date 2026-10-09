<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_deal_reports', function (Blueprint $t) {
            $t->index('deal_id', 'deal_reports_deal_lookup');
        });
        Schema::table('marketplace_deal_reports', function (Blueprint $t) {
            $t->dropUnique('deal_reports_one_per_reporter_target');
            $t->string('status', 16)->default('open')->change();
            $t->char('open_slot', 1)->nullable();
            $t->foreignUuid('handled_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('outcome_reason')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->unique(['deal_id', 'reporter_id', 'target', 'reason', 'open_slot'], 'deal_reports_one_active_issue');
        });
        DB::table('marketplace_deal_reports')->where('status', 'open')->update(['open_slot' => 'O']);
        Schema::create('marketplace_content_reports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('reference', 20)->unique();
            $t->foreignUuid('reporter_id')->constrained('users')->restrictOnDelete();
            $t->string('target_type', 10);
            $t->uuid('target_id');
            $t->string('reason', 30);
            $t->text('description')->nullable();
            $t->string('status', 16)->default('open');
            $t->char('open_slot', 1)->nullable();
            $t->foreignUuid('handled_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('outcome_reason')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->unique(['reporter_id', 'target_type', 'target_id', 'reason', 'open_slot'], 'content_reports_one_active_issue');
            $t->index(['status', 'created_at']);
        });
        Schema::create('marketplace_report_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('report_type', 10);
            $t->uuid('report_id');
            $t->foreignUuid('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('from_status', 16)->nullable();
            $t->string('to_status', 16);
            $t->text('reason')->nullable();
            $t->string('enforcement_type', 10)->nullable();
            $t->uuid('enforcement_id')->nullable();
            $t->string('enforcement_action', 30)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['report_type', 'report_id', 'created_at']);
        });
        DB::table('marketplace_deal_reports')->orderBy('id')->chunk(200, function ($reports) {
            foreach ($reports as $report) {
                DB::table('marketplace_report_events')->insert([
                    'id' => (string) Str::uuid7(), 'report_type' => 'deal', 'report_id' => $report->id,
                    'actor_id' => $report->reporter_id, 'from_status' => null, 'to_status' => $report->status, 'created_at' => $report->created_at,
                ]);
            }
        });
        // Historical complaints must not remain visible through the reported farm's audit endpoint.
        DB::table('audit_logs')->where('action', 'marketplace.deal_reported')->update(['farm_id' => null]);
    }

    public function down(): void
    {
        // Closed cases may legitimately share the old unique key. Never discard reports to force a rollback.
        if (DB::table('marketplace_deal_reports')->select('deal_id', 'reporter_id', 'target')->groupBy('deal_id', 'reporter_id', 'target')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot restore legacy uniqueness while repeat deal reports exist.');
        }
        if (DB::table('marketplace_content_reports')->exists() || DB::table('marketplace_report_events')->whereNotNull('from_status')->exists()) {
            throw new RuntimeException('Cannot roll back while content complaints or administrative report history exist.');
        }
        Schema::dropIfExists('marketplace_report_events');
        Schema::dropIfExists('marketplace_content_reports');
        Schema::table('marketplace_deal_reports', function (Blueprint $t) {
            $t->dropUnique('deal_reports_one_active_issue');
            $t->dropForeign(['handled_by']);
            $t->dropColumn(['open_slot', 'handled_by', 'outcome_reason', 'closed_at']);
            $t->unique(['deal_id', 'reporter_id', 'target'], 'deal_reports_one_per_reporter_target');
        });
        Schema::table('marketplace_deal_reports', fn (Blueprint $t) => $t->dropIndex('deal_reports_deal_lookup'));
    }
};
