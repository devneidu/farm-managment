<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One reproductive attempt. reference_snapshot is the biological reference as it was when the project started
        // (never re-read from master data); expected_* is the operational expectation derived from it or entered by hand.
        Schema::create('breeding_projects', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->uuid('production_cycle_id');
            $t->string('reference', 24);
            $t->string('workflow', 16);
            $t->string('status', 16);
            $t->date('start_date');
            $t->unsignedInteger('eggs_set')->nullable();
            $t->unsignedInteger('females_bred')->nullable();
            $t->unsignedInteger('expected_offspring')->nullable();
            $t->json('reference_snapshot');
            $t->string('expectation_source', 16);
            $t->date('expected_date')->nullable();
            $t->date('expected_from')->nullable();
            $t->date('expected_to')->nullable();
            $t->text('notes')->nullable();
            $t->dateTime('cancelled_at')->nullable();
            $t->string('cancel_reason', 2000)->nullable();
            $t->string('idempotency_key', 80)->collation('utf8mb4_bin');
            $t->char('request_hash', 64);
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['farm_id', 'id']);
            $t->unique(['farm_id', 'reference']);
            $t->unique(['farm_id', 'idempotency_key']);
            $t->unique(['farm_id', 'production_cycle_id', 'id'], 'breeding_projects_farm_cycle_id_unique');
            $t->index(['farm_id', 'production_cycle_id', 'status'], 'breeding_projects_cycle_status_index');
            $t->index(['farm_id', 'status', 'expected_date']);
            $t->foreign(['farm_id', 'production_cycle_id'])->references(['farm_id', 'id'])->on('production_cycles')->restrictOnDelete();
        });

        // Group-managed parent source: a livestock cycle of the same farm (no individual animal identity or pedigree).
        Schema::create('breeding_parents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->uuid('breeding_project_id');
            $t->string('role', 8);
            $t->uuid('parent_cycle_id');
            $t->unsignedInteger('head_count')->nullable();
            $t->timestamps();
            $t->unique(['breeding_project_id', 'role', 'parent_cycle_id'], 'breeding_parents_unique');
            $t->foreign(['farm_id', 'breeding_project_id'])->references(['farm_id', 'id'])->on('breeding_projects')->restrictOnDelete();
            $t->foreign(['farm_id', 'parent_cycle_id'])->references(['farm_id', 'id'])->on('production_cycles')->restrictOnDelete();
        });

        Schema::create('breeding_checks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->uuid('breeding_project_id');
            $t->date('checked_on');
            $t->string('result', 16);
            $t->unsignedInteger('fertile_count')->nullable();
            $t->text('notes')->nullable();
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['breeding_project_id', 'checked_on']);
            $t->foreign(['farm_id', 'breeding_project_id'])->references(['farm_id', 'id'])->on('breeding_projects')->restrictOnDelete();
        });

        // Actual outcomes. Append-only: a mistake is a `reversal` row (which compensates the population record) plus a linked replacement.
        Schema::create('breeding_outcomes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->uuid('breeding_project_id');
            $t->uuid('production_cycle_id');
            $t->string('kind', 12);
            $t->unsignedInteger('live_count')->nullable();
            $t->unsignedInteger('loss_count')->nullable();
            $t->date('outcome_date')->nullable();
            $t->uuid('operational_record_id')->nullable()->unique();
            $t->uuid('reverses_outcome_id')->nullable()->unique();
            $t->uuid('corrects_outcome_id')->nullable()->unique();
            $t->text('reason')->nullable();
            $t->dateTime('recorded_at');
            $t->text('notes')->nullable();
            $t->string('idempotency_key', 80)->collation('utf8mb4_bin');
            $t->char('request_hash', 64);
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->string('request_id', 100)->nullable();
            $t->timestamps();
            $t->unique(['farm_id', 'id']);
            $t->unique(['farm_id', 'idempotency_key']);
            $t->index(['breeding_project_id', 'recorded_at']);
            $t->foreign(['farm_id', 'breeding_project_id'])->references(['farm_id', 'id'])->on('breeding_projects')->restrictOnDelete();
            $t->foreign(['farm_id', 'production_cycle_id'])->references(['farm_id', 'id'])->on('production_cycles')->restrictOnDelete();
            $t->foreign(['farm_id', 'production_cycle_id', 'operational_record_id'], 'breeding_outcomes_record_fk')->references(['farm_id', 'production_cycle_id', 'id'])->on('operational_records')->restrictOnDelete();
            foreach (['reverses_outcome_id', 'corrects_outcome_id'] as $column) {
                $t->foreign(['farm_id', $column], 'breeding_outcomes_'.$column.'_fk')->references(['farm_id', 'id'])->on('breeding_outcomes')->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        // Destructive rollback removes this phase's population effects together with their sources (Phase 8 records stay).
        $records = DB::table('breeding_outcomes')->whereNotNull('operational_record_id')->pluck('operational_record_id')->all();
        Schema::table('breeding_outcomes', function (Blueprint $t) {
            $t->dropForeign('breeding_outcomes_reverses_outcome_id_fk');
            $t->dropForeign('breeding_outcomes_corrects_outcome_id_fk');
        });
        Schema::dropIfExists('breeding_outcomes');
        Schema::dropIfExists('breeding_checks');
        Schema::dropIfExists('breeding_parents');
        Schema::dropIfExists('breeding_projects');
        foreach (array_chunk($records, 500) as $chunk) {
            DB::table('population_movements')->whereIn('operational_record_id', $chunk)->delete();
            DB::table('operational_records')->whereIn('id', $chunk)->whereNotNull('reverses_record_id')->delete();
            DB::table('operational_records')->whereIn('id', $chunk)->delete();
        }
    }
};
