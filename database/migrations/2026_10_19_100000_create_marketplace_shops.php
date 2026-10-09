<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A seller shop belongs to a USER (a marketplace-only seller needs no farm). `farm_id` is an optional, creation-time link to a farm the
        // creator may manage; a farm has at most one shop. The shop stores no farm business data and the public API never exposes `farm_id`.
        Schema::create('marketplace_shops', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('reference', 20)->unique();           // SHP-2026-00001
            $t->string('slug', 100)->unique();               // public URL identity, immutable
            $t->foreignUuid('farm_id')->nullable()->unique()->constrained('farms')->restrictOnDelete();
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();

            // Public profile
            $t->string('name', 120);
            $t->string('tagline', 160)->nullable();
            $t->text('description')->nullable();
            $t->string('seller_type', 16);                   // individual | business | farm
            $t->json('categories');                          // closed vocabulary, see MarketplaceShop::CATEGORIES
            $t->string('country_code', 2)->default('NG');
            $t->string('state', 60)->nullable();
            $t->string('city', 80)->nullable();
            $t->string('area', 120)->nullable();             // public landmark / neighbourhood

            // PRIVATE: never part of a public response
            $t->string('address_line', 255)->nullable();
            $t->string('contact_phone', 20)->nullable();
            $t->string('contact_whatsapp', 20)->nullable();
            $t->string('contact_email', 254)->nullable();
            $t->string('preferred_contact_method', 16)->nullable(); // in_app | phone | whatsapp | email

            // Lifecycle (publishing) and verification (trust badge) are independent axes
            $t->string('status', 16)->default('draft');     // draft | pending_review | rejected | active | suspended | closed
            $t->string('status_reason', 500)->nullable();   // why rejected / suspended
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('approved_at')->nullable();       // first approval: a shop that was never approved can never be reopened as active
            $t->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('suspended_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->string('verification_status', 16)->default('unverified'); // unverified | pending | verified | rejected
            $t->string('verification_reason', 500)->nullable();
            $t->timestamp('verification_requested_at')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->foreignUuid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['status', 'state', 'city']);
            $t->index(['status', 'verification_status']);
            $t->index('created_by');
        });

        // Shop-scoped roles (owner | manager | staff). Independent of FarmRole so a marketplace-only seller needs nothing from the farm modules.
        Schema::create('marketplace_shop_members', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('shop_id')->constrained('marketplace_shops')->cascadeOnDelete();
            $t->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('role', 16);
            $t->foreignUuid('added_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['shop_id', 'user_id']);
            $t->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_shop_members');
        Schema::dropIfExists('marketplace_shops');
    }
};
