<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farm_memberships', function (Blueprint $table) {
            // active | removed. Removal keeps the row (history + re-invite reactivates it).
            $table->string('status', 16)->default('active')->after('role');
            $table->timestamp('removed_at')->nullable()->after('status');
            $table->foreignUuid('removed_by_user_id')->nullable()->after('removed_at')->constrained('users')->nullOnDelete();
            // Per-user, per-farm notification channel preferences (shell; the engine is a later phase).
            $table->json('notification_preferences')->nullable()->after('removed_by_user_id');
        });

        Schema::create('farm_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained('farms')->cascadeOnDelete();
            $table->string('email', 254);
            $table->string('role', 32);
            $table->char('token_hash', 64)->unique(); // sha256 of the emailed token; plaintext is never stored
            $table->foreignUuid('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignUuid('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUuid('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['farm_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farm_invitations');

        Schema::table('farm_memberships', function (Blueprint $table) {
            $table->dropForeign(['removed_by_user_id']);
            $table->dropColumn(['status', 'removed_at', 'removed_by_user_id', 'notification_preferences']);
        });
    }
};
