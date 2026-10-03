<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform administrators are NOT farm members: a separate grant, one row per user, never creatable through the API.
        Schema::create('platform_admins', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->unique()->constrained('users')->restrictOnDelete();
            $t->string('role', 16); // admin (read + write) | support (read only)
            $t->foreignUuid('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('platform_settings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('key', 64)->unique();
            $t->json('value')->nullable();
            $t->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('feature_flags', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('key', 64)->unique();
            $t->string('description', 255)->nullable();
            $t->boolean('enabled')->default(false);
            $t->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        // Platform-level audit entries share the Phase 17 table: they simply belong to no farm (so no farm audit view ever returns them).
        Schema::table('audit_logs', fn (Blueprint $t) => $t->uuid('farm_id')->nullable()->change());
        Schema::table('audit_logs', fn (Blueprint $t) => $t->index(['created_at'], 'audit_logs_time_index'));

        // Platform templates are drafted before farms can see them; existing platform templates are already published.
        Schema::table('work_templates', fn (Blueprint $t) => $t->timestamp('published_at')->nullable()->after('is_active'));
        DB::table('work_templates')->whereNull('farm_id')->update(['published_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('work_templates', fn (Blueprint $t) => $t->dropColumn('published_at'));

        Schema::table('audit_logs', fn (Blueprint $t) => $t->dropIndex('audit_logs_time_index'));
        DB::table('audit_logs')->whereNull('farm_id')->delete();
        Schema::table('audit_logs', fn (Blueprint $t) => $t->uuid('farm_id')->nullable(false)->change());

        Schema::dropIfExists('feature_flags');
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('platform_admins');
    }
};
