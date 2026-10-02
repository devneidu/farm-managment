<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reusable plan. farm_id null = platform template (read-only for farms; clone to customise). Applied schedules are
        // independent copies that remember the template version, so later edits never rewrite them.
        Schema::create('work_templates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('source', 12);
            $t->string('code', 60)->nullable();
            $t->string('name', 150);
            $t->text('description')->nullable();
            $t->string('applies_to', 20);
            $t->string('cycle_kind', 20)->nullable();
            $t->foreignUuid('operation_type_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('species_id')->nullable()->constrained('species')->restrictOnDelete();
            $t->foreignUuid('crop_type_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('breeding_workflow', 16)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->boolean('is_active')->default(true);
            $t->uuid('cloned_from_id')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['farm_id', 'code']);
            $t->index(['farm_id', 'applies_to', 'is_active']);
        });

        Schema::create('work_template_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('work_template_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('position');
            $t->string('title', 200);
            $t->string('category', 30);
            $t->text('instructions')->nullable();
            $t->string('anchor', 24);
            $t->unsignedSmallInteger('offset_days')->default(0);
            $t->string('recurrence', 8)->default('none');
            $t->unsignedSmallInteger('interval_value')->default(1);
            $t->json('weekdays')->nullable();
            $t->unsignedSmallInteger('until_offset_days')->nullable();
            $t->unsignedSmallInteger('occurrence_limit')->nullable();
            $t->time('due_time')->nullable();
            $t->json('reminder_offsets')->nullable();
            $t->string('assigned_role', 20)->nullable();
            $t->string('linked_record_type', 40)->nullable();
            $t->boolean('requires_evidence')->default(false);
            $t->timestamps();
            $t->index(['work_template_id', 'position']);
        });

        // A template applied to one cycle / breeding project (once per template and target).
        Schema::create('template_applications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('work_template_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('template_version');
            $t->uuid('production_cycle_id');
            $t->uuid('breeding_project_id')->nullable();
            $t->string('target_key', 60);
            $t->unsignedInteger('schedules_created')->default(0);
            $t->unsignedInteger('tasks_created')->default(0);
            $t->unsignedInteger('skipped_past')->default(0);
            $t->unsignedInteger('skipped_no_anchor')->default(0);
            $t->string('idempotency_key', 80)->collation('utf8mb4_bin');
            $t->char('request_hash', 64);
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['farm_id', 'id']);
            $t->unique(['farm_id', 'idempotency_key']);
            $t->unique(['farm_id', 'work_template_id', 'target_key'], 'template_applications_target_unique');
            $t->foreign(['farm_id', 'production_cycle_id'])->references(['farm_id', 'id'])->on('production_cycles')->restrictOnDelete();
            $t->foreign(['farm_id', 'breeding_project_id'])->references(['farm_id', 'id'])->on('breeding_projects')->restrictOnDelete();
        });

        // A recurrence rule that materialises tasks. Never creates operational records.
        Schema::create('schedules', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->uuid('production_cycle_id')->nullable();
            $t->uuid('breeding_project_id')->nullable();
            $t->uuid('template_application_id')->nullable();
            $t->uuid('template_item_id')->nullable();
            $t->string('title', 200);
            $t->string('category', 30);
            $t->text('instructions')->nullable();
            $t->string('recurrence', 8);
            $t->unsignedSmallInteger('interval_value')->default(1);
            $t->json('weekdays')->nullable();
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->unsignedSmallInteger('occurrence_limit')->nullable();
            $t->time('due_time')->nullable();
            $t->json('reminder_offsets')->nullable();
            $t->uuid('assigned_user_id')->nullable();
            $t->string('assigned_role', 20)->nullable();
            $t->string('linked_record_type', 40)->nullable();
            $t->boolean('requires_evidence')->default(false);
            $t->string('status', 8)->default('active');
            $t->string('idempotency_key', 80)->collation('utf8mb4_bin')->nullable();
            $t->char('request_hash', 64)->nullable();
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['farm_id', 'id']);
            $t->unique(['farm_id', 'idempotency_key']);
            $t->index(['farm_id', 'status']);
            $t->foreign(['farm_id', 'production_cycle_id'])->references(['farm_id', 'id'])->on('production_cycles')->restrictOnDelete();
            $t->foreign(['farm_id', 'breeding_project_id'])->references(['farm_id', 'id'])->on('breeding_projects')->restrictOnDelete();
            $t->foreign(['farm_id', 'template_application_id'])->references(['farm_id', 'id'])->on('template_applications')->restrictOnDelete();
            $t->foreign('assigned_user_id')->references('id')->on('users')->restrictOnDelete();
        });

        // Work that SHOULD happen. status is open|completed|cancelled; overdue/due-today are derived from due_at. due_date/due_time are
        // farm-local; due_at is the UTC instant after which an open task is overdue (the due time, or the end of the due day).
        Schema::create('tasks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->string('reference', 24);
            $t->uuid('schedule_id')->nullable();
            $t->date('occurrence_date')->nullable();
            $t->uuid('production_cycle_id')->nullable();
            $t->uuid('breeding_project_id')->nullable();
            $t->string('title', 200);
            $t->string('category', 30);
            $t->text('instructions')->nullable();
            $t->date('due_date');
            $t->time('due_time')->nullable();
            $t->dateTime('due_at');
            $t->json('reminder_offsets')->nullable();
            $t->uuid('assigned_user_id')->nullable();
            $t->string('assigned_role', 20)->nullable();
            $t->string('linked_record_type', 40)->nullable();
            $t->boolean('requires_evidence')->default(false);
            $t->string('status', 10)->default('open');
            $t->dateTime('completed_at')->nullable();
            $t->uuid('completed_by')->nullable();
            $t->text('completion_note')->nullable();
            $t->string('evidence_type', 24)->nullable();
            $t->uuid('evidence_id')->nullable();
            $t->dateTime('cancelled_at')->nullable();
            $t->string('cancel_reason', 2000)->nullable();
            $t->string('idempotency_key', 80)->collation('utf8mb4_bin')->nullable();
            $t->char('request_hash', 64)->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['farm_id', 'id']);
            $t->unique(['farm_id', 'reference']);
            $t->unique(['farm_id', 'idempotency_key']);
            $t->unique(['schedule_id', 'occurrence_date'], 'tasks_schedule_occurrence_unique');
            $t->unique(['farm_id', 'evidence_type', 'evidence_id'], 'tasks_evidence_unique');
            $t->index(['farm_id', 'status', 'due_at']);
            $t->index(['farm_id', 'assigned_user_id', 'status']);
            $t->index(['farm_id', 'production_cycle_id']);
            $t->foreign(['farm_id', 'production_cycle_id'])->references(['farm_id', 'id'])->on('production_cycles')->restrictOnDelete();
            $t->foreign(['farm_id', 'breeding_project_id'])->references(['farm_id', 'id'])->on('breeding_projects')->restrictOnDelete();
            $t->foreign(['farm_id', 'schedule_id'])->references(['farm_id', 'id'])->on('schedules')->restrictOnDelete();
            $t->foreign('assigned_user_id')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('completed_by')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Tasks reach actual records only through evidence_id (no FK), so rolling back never touches operational data.
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('schedules');
        Schema::dropIfExists('template_applications');
        Schema::dropIfExists('work_template_items');
        Schema::dropIfExists('work_templates');
    }
};
