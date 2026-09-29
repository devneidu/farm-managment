<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-level catalogue (editable later by Platform Admin). Money is integer minor units.
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('currency', 3)->default('NGN');
            $table->boolean('is_active')->default(true);   // false: plan is not honoured (safe fallback applies)
            $table->boolean('is_public')->default(true);   // false: hidden from the public catalogue, still honoured
            $table->boolean('is_default')->default(false); // the plan every new farm starts on (exactly one)
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('plan_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('interval', 16); // monthly | annual
            $table->string('currency', 3)->default('NGN');
            $table->unsignedBigInteger('amount_minor'); // kobo for NGN
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['plan_id', 'interval', 'currency']);
        });

        // Registry rows for stable machine keys (mirrors App\Enums\Feature / Limit); type = feature | limit.
        Schema::create('entitlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 64)->unique();
            $table->string('type', 16);
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('plan_entitlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->foreignUuid('entitlement_id')->constrained('entitlements')->cascadeOnDelete();
            $table->boolean('enabled')->nullable();                // features
            $table->unsignedBigInteger('limit_value')->nullable(); // limits; NULL with is_unlimited=false means 0
            $table->boolean('is_unlimited')->default(false);       // limits
            $table->timestamps();

            $table->unique(['plan_id', 'entitlement_id']);
        });

        // One current subscription per farm; every change is recorded in subscription_events.
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->unique()->constrained('farms')->cascadeOnDelete();
            $table->foreignUuid('plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('status', 16); // active | past_due | cancelled | expired
            $table->string('billing_interval', 16)->nullable();
            $table->timestamp('starts_at')->useCurrent();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable(); // NULL = no period (default plan)
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('provider', 32)->nullable();       // billing provider slug, once one exists
            $table->string('provider_reference')->nullable(); // external subscription/customer reference
            $table->timestamps();

            $table->index(['status', 'current_period_end']);
        });

        Schema::create('subscription_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignUuid('farm_id')->constrained('farms')->cascadeOnDelete();
            $table->string('type', 32);
            $table->foreignUuid('from_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->foreignUuid('to_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16)->nullable();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['subscription_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_events');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plan_entitlements');
        Schema::dropIfExists('entitlements');
        Schema::dropIfExists('plan_prices');
        Schema::dropIfExists('plans');
    }
};
