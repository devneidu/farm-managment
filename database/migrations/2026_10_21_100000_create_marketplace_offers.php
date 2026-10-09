<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A buyer's priced proposal on a NEGOTIABLE listing. It records the terms and a snapshot of the listing as the buyer saw it; it reserves no stock,
        // takes no money and creates no sale. `pending_slot` is 'P' only while the offer is pending (NULL otherwise), so the unique key below lets the
        // database itself refuse a second simultaneous pending offer by the same buyer on the same listing, whatever the application does.
        Schema::create('marketplace_offers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('reference', 20)->unique();                  // OFR-2026-00001
            $t->foreignUuid('listing_id')->constrained('marketplace_listings')->restrictOnDelete();
            $t->foreignUuid('shop_id')->constrained('marketplace_shops')->restrictOnDelete();
            $t->foreignUuid('buyer_id')->constrained('users')->restrictOnDelete();
            $t->unsignedTinyInteger('attempt_no');
            $t->string('status', 10);                               // pending | accepted | rejected | expired | voided
            $t->char('pending_slot', 1)->nullable();

            // Proposed terms (decimal strings end to end)
            $t->decimal('quantity', 24, 6);
            $t->decimal('unit_price', 18, 2);
            $t->decimal('total_amount', 18, 2);
            $t->char('currency', 3)->default('NGN');

            // Snapshot of the listing at submission (never rewritten, even if the listing changes)
            $t->string('listing_title', 150);
            $t->unsignedInteger('listing_version');
            $t->decimal('listed_unit_price', 18, 2);
            $t->foreignUuid('unit_id')->constrained('units')->restrictOnDelete();
            $t->string('unit_code', 30);
            $t->string('product_kind', 16);
            $t->foreignUuid('species_id')->nullable()->constrained('species')->restrictOnDelete();
            $t->foreignUuid('crop_type_id')->nullable()->constrained('crop_types')->restrictOnDelete();
            $t->string('product_name', 120);
            $t->decimal('listing_available_quantity', 24, 6);
            $t->decimal('listing_min_order_quantity', 24, 6)->nullable();

            // Lifecycle
            $t->timestamp('expires_at');
            $t->timestamp('responded_at')->nullable();
            $t->foreignUuid('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('void_reason', 30)->nullable();              // a CODE (listing_changed), never free text
            $t->timestamps();

            $t->unique(['listing_id', 'buyer_id', 'pending_slot'], 'offers_one_pending_per_buyer');
            $t->unique(['listing_id', 'buyer_id', 'attempt_no'], 'offers_attempt_unique');
            $t->index(['shop_id', 'status', 'created_at']);
            $t->index(['buyer_id', 'created_at']);
            $t->index(['status', 'expires_at']);
        });

        Schema::create('marketplace_offer_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('offer_id')->constrained('marketplace_offers')->restrictOnDelete();
            $t->string('actor_kind', 10);                           // buyer | seller | system
            $t->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 16);                               // submitted | accepted | rejected | expired | voided
            $t->string('from_status', 10)->nullable();
            $t->string('to_status', 10);
            $t->string('code', 30)->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->index(['offer_id', 'created_at']);
        });

        // "Proceed at listed price": recorded buyer interest at the listed unit price. Not an acceptance, an order, a payment or a deal.
        Schema::create('marketplace_purchase_intents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('reference', 20)->unique();                  // PIN-2026-00001
            $t->foreignUuid('listing_id')->constrained('marketplace_listings')->restrictOnDelete();
            $t->foreignUuid('shop_id')->constrained('marketplace_shops')->restrictOnDelete();
            $t->foreignUuid('buyer_id')->constrained('users')->restrictOnDelete();
            $t->decimal('quantity', 24, 6);
            $t->decimal('listed_unit_price', 18, 2);
            $t->decimal('total_amount', 18, 2);
            $t->char('currency', 3)->default('NGN');
            $t->foreignUuid('unit_id')->constrained('units')->restrictOnDelete();
            $t->string('unit_code', 30);
            $t->string('listing_title', 150);
            $t->unsignedInteger('listing_version');
            $t->string('product_name', 120);
            $t->timestamps();

            $t->unique(['listing_id', 'buyer_id']);
            $t->index(['shop_id', 'created_at']);
            $t->index(['buyer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_purchase_intents');
        Schema::dropIfExists('marketplace_offer_events');
        Schema::dropIfExists('marketplace_offers');
    }
};
