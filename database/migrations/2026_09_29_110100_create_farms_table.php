<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            // Platform defaults (Nigeria-first); not asked during onboarding.
            $table->string('country_code', 2)->default('NG');
            $table->string('currency', 3)->default('NGN');
            $table->string('timezone', 64)->default('Africa/Lagos');
            $table->string('locale', 10)->default('en');
            $table->timestamps();
        });

        // Ownership/membership from day one so Team & Access (later) needs no data migration.
        Schema::create('farm_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained('farms')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 32); // Phase 1: 'owner' only; replaced by RBAC later
            $table->timestamps();

            $table->unique(['farm_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farm_memberships');
        Schema::dropIfExists('farms');
    }
};
