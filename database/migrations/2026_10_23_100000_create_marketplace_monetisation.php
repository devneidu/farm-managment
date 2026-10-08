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
        // Seller plans belong to the MARKETPLACE and are separate from the farm-scoped Phase 3 plans. `listing_limit` is the number of PUBLISHED listings a
        // shop may have at once (NULL = unlimited). A plan price is a PREPAID period (30 or 365 days) in naira; a NULL/absent price means "not purchasable".
        Schema::create('marketplace_seller_plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 40)->unique();
            $t->string('name', 80);
            $t->string('description', 255)->nullable();
            $t->unsignedInteger('listing_limit')->nullable();
            $t->boolean('is_free')->default(false);
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('marketplace_seller_plan_prices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('plan_id')->constrained('marketplace_seller_plans')->cascadeOnDelete();
            $t->unsignedSmallInteger('interval_days');               // 30 | 365
            $t->decimal('amount', 18, 2);
            $t->char('currency', 3)->default('NGN');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['plan_id', 'interval_days']);
        });

        // Fixed-price, fixed-duration promotion packages. No bidding, no targeting.
        Schema::create('marketplace_promotion_packages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 40)->unique();
            $t->string('name', 80);
            $t->string('description', 255)->nullable();
            $t->unsignedSmallInteger('duration_days');
            $t->decimal('amount', 18, 2);
            $t->char('currency', 3)->default('NGN');
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });

        // Money collected by FARMVEST for its own services (a subscription or a promotion) - never a buyer-seller product payment. The amount is frozen at
        // checkout; `settled_at` is set exactly once, in the transaction that grants the benefit, so a payment can never activate twice.
        Schema::create('marketplace_service_payments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('reference', 20)->unique();                  // MSP-2026-00001; also the Paystack transaction reference
            $t->foreignUuid('shop_id')->constrained('marketplace_shops')->restrictOnDelete();
            $t->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $t->string('purpose', 14);                              // subscription | promotion
            $t->string('provider', 12)->default('paystack');
            $t->decimal('amount', 18, 2);
            $t->char('currency', 3)->default('NGN');
            $t->string('status', 10);                               // pending | paid | failed | abandoned
            $t->foreignUuid('plan_id')->nullable()->constrained('marketplace_seller_plans')->restrictOnDelete();
            $t->unsignedSmallInteger('interval_days')->nullable();
            $t->foreignUuid('package_id')->nullable()->constrained('marketplace_promotion_packages')->restrictOnDelete();
            $t->foreignUuid('listing_id')->nullable()->constrained('marketplace_listings')->restrictOnDelete();
            $t->string('subject_label', 150);                       // "Seller Plus - 30 days" / "Featured week - Healthy chickens"
            $t->string('authorization_url', 500)->nullable();
            $t->string('access_code', 80)->nullable();
            $t->string('gateway_status', 30)->nullable();           // the status Paystack last reported
            $t->string('failure_reason', 40)->nullable();           // a CODE: gateway_failed | amount_mismatch | currency_mismatch | initialize_failed
            $t->string('settlement_issue', 40)->nullable();         // paid but no benefit granted: needs an admin (shop_not_eligible | listing_not_eligible | listing_already_promoted)
            $t->timestamp('verified_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('settled_at')->nullable();
            $t->timestamps();
            $t->index(['shop_id', 'created_at']);
            $t->index(['status', 'created_at']);
        });

        Schema::create('marketplace_shop_subscriptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('shop_id')->constrained('marketplace_shops')->restrictOnDelete();
            $t->foreignUuid('plan_id')->constrained('marketplace_seller_plans')->restrictOnDelete();
            $t->foreignUuid('payment_id')->unique()->constrained('marketplace_service_payments')->restrictOnDelete();   // one period per payment
            $t->unsignedSmallInteger('interval_days');
            $t->unsignedInteger('listing_limit')->nullable();       // frozen at purchase: an admin changing the plan later never shrinks a paid period
            $t->string('plan_name', 80);
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->timestamps();
            $t->index(['shop_id', 'starts_at', 'ends_at']);
        });

        // `open_slot` is 'A' while a promotion occupies its listing (NULL once cancelled or released after expiry), so the unique key lets the database
        // refuse a second live promotion on one listing whatever the application does.
        Schema::create('marketplace_promotions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('reference', 20)->unique();                  // MPR-2026-00001
            $t->foreignUuid('shop_id')->constrained('marketplace_shops')->restrictOnDelete();
            $t->foreignUuid('listing_id')->constrained('marketplace_listings')->restrictOnDelete();
            $t->foreignUuid('payment_id')->unique()->constrained('marketplace_service_payments')->restrictOnDelete();
            $t->foreignUuid('package_id')->nullable()->constrained('marketplace_promotion_packages')->nullOnDelete();
            $t->string('package_name', 80);
            $t->unsignedSmallInteger('duration_days');
            $t->decimal('amount', 18, 2);
            $t->char('currency', 3)->default('NGN');
            $t->string('status', 10);                               // active | cancelled (expiry is read from expires_at)
            $t->char('open_slot', 1)->nullable();
            $t->timestamp('starts_at');
            $t->timestamp('expires_at');
            $t->timestamp('cancelled_at')->nullable();
            $t->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('cancel_reason', 200)->nullable();
            $t->timestamps();
            $t->unique(['listing_id', 'open_slot'], 'promotions_one_open_per_listing');
            $t->index(['status', 'expires_at']);
            $t->index(['shop_id', 'created_at']);
        });

        // Every Paystack webhook delivery is stored BEFORE it is processed. (provider, dedupe_key) is unique so a redelivery is recognised; an event whose
        // processing failed stays `failed` and is retried (by Paystack's own retries and by the reconcile command), so a transient failure never loses a payment.
        Schema::create('marketplace_payment_webhook_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('provider', 12);
            $t->string('dedupe_key', 120);
            $t->string('event', 40);
            $t->string('payment_reference', 40)->nullable();
            $t->string('status', 10);                               // received | processed | failed
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->string('last_error', 255)->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
            $t->unique(['provider', 'dedupe_key']);
            $t->index(['status', 'created_at']);
        });

        $now = now();
        $plans = [
            ['code' => 'free', 'name' => 'Free', 'description' => 'Every shop starts here.', 'listing_limit' => 10, 'is_free' => true, 'sort_order' => 0],
            ['code' => 'seller_plus', 'name' => 'Seller Plus', 'description' => 'More published listings.', 'listing_limit' => null, 'is_free' => false, 'sort_order' => 1],
            ['code' => 'seller_pro', 'name' => 'Seller Pro', 'description' => 'The most published listings.', 'listing_limit' => null, 'is_free' => false, 'sort_order' => 2],
        ];
        foreach ($plans as $plan) {
            // Paid plans ship INACTIVE with no price and no limit: an administrator sets both before they can be bought. No price is invented here.
            DB::table('marketplace_seller_plans')->insert($plan + ['id' => (string) Str::uuid7(), 'is_active' => $plan['is_free'], 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (['marketplace_seller_plans' => 'Sell more: allow shops to buy a paid seller plan (Paystack).', 'marketplace_promotions' => 'Allow shops to buy a promoted ("Sponsored") listing (Paystack).'] as $key => $description) {
            DB::table('feature_flags')->insertOrIgnore(['id' => (string) Str::uuid7(), 'key' => $key, 'description' => $description, 'enabled' => false, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        DB::table('feature_flags')->whereIn('key', ['marketplace_seller_plans', 'marketplace_promotions'])->delete();
        Schema::dropIfExists('marketplace_payment_webhook_events');
        Schema::dropIfExists('marketplace_promotions');
        Schema::dropIfExists('marketplace_shop_subscriptions');
        Schema::dropIfExists('marketplace_service_payments');
        Schema::dropIfExists('marketplace_promotion_packages');
        Schema::dropIfExists('marketplace_seller_plan_prices');
        Schema::dropIfExists('marketplace_seller_plans');
    }
};
