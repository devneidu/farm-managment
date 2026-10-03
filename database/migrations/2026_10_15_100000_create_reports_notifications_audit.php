<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A report export is a request to render one report to a private file in the background. It stores the request context (who, which
        // farm, which filters) and the lifecycle; it never stores report totals - the file is rendered from the authoritative data on demand.
        Schema::create('report_exports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $t->string('report', 40);
            $t->string('format', 8);
            $t->json('filters');
            $t->string('status', 12)->default('queued'); // queued | processing | completed | failed | expired
            $t->string('idempotency_key', 80)->collation('utf8mb4_bin');
            $t->char('request_hash', 64);
            $t->unsignedInteger('row_count')->nullable();
            $t->string('file_path', 255)->nullable();
            $t->string('file_name', 150)->nullable();
            $t->unsignedBigInteger('file_size')->nullable();
            $t->string('error_code', 60)->nullable();
            $t->string('error_message', 255)->nullable();
            $t->dateTime('started_at')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->dateTime('failed_at')->nullable();
            $t->dateTime('expires_at')->nullable();
            $t->dateTime('first_downloaded_at')->nullable();
            $t->unsignedInteger('download_count')->default(0);
            $t->timestamps();
            $t->unique(['farm_id', 'requested_by', 'idempotency_key'], 'report_exports_request_unique');
            $t->index(['farm_id', 'requested_by', 'created_at'], 'report_exports_owner_index');
            $t->index(['status', 'expires_at'], 'report_exports_expiry_index');
        });

        // A notification is a per-user, per-farm inbox record. The source (an insight, a task reminder, an export) stays untouched; read state
        // lives only here. (user, farm, dedupe_key) is unique so a condition is announced once per period however often it is evaluated.
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $t->string('type', 40);
            $t->string('severity', 10);
            $t->string('title', 160);
            $t->string('message', 500);
            $t->string('subject_type', 40)->nullable();
            $t->uuid('subject_id')->nullable();
            $t->string('subject_reference', 60)->nullable();
            $t->json('data')->nullable();
            $t->string('dedupe_key', 190)->collation('utf8mb4_bin');
            $t->boolean('in_app')->default(true);
            $t->dateTime('read_at')->nullable();
            $t->dateTime('emailed_at')->nullable();
            $t->string('email_status', 10)->nullable(); // queued | sent | failed
            $t->timestamps();
            $t->unique(['user_id', 'farm_id', 'dedupe_key'], 'notifications_dedupe_unique');
            $t->index(['user_id', 'farm_id', 'in_app', 'read_at', 'created_at'], 'notifications_inbox_index');
        });

        // Append-only log of privileged / configuration actions that no domain ledger already records (team and access changes, farm
        // settings, report exports). Operational, inventory, finance and sales history is NOT copied here: the audit view reads it from the
        // authoritative append-only records and their reversal links.
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('farm_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('action', 60);
            $t->string('resource_type', 40);
            $t->uuid('resource_id')->nullable();
            $t->string('resource_label', 150)->nullable();
            $t->json('changes')->nullable();
            $t->string('request_id', 100)->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['farm_id', 'created_at'], 'audit_logs_farm_time_index');
            $t->index(['farm_id', 'resource_type', 'resource_id'], 'audit_logs_resource_index');
            $t->index(['farm_id', 'actor_id'], 'audit_logs_actor_index');
        });

        // Report query patterns: records by type within a period, and the population ledger before/within a period.
        Schema::table('operational_records', fn (Blueprint $t) => $t->index(['farm_id', 'type', 'recorded_at'], 'records_farm_type_recorded_index'));
        Schema::table('population_movements', fn (Blueprint $t) => $t->index(['production_cycle_id', 'recorded_at'], 'population_cycle_recorded_index'));
    }

    public function down(): void
    {
        Schema::table('population_movements', fn (Blueprint $t) => $t->dropIndex('population_cycle_recorded_index'));
        Schema::table('operational_records', fn (Blueprint $t) => $t->dropIndex('records_farm_type_recorded_index'));
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('report_exports');
    }
};
