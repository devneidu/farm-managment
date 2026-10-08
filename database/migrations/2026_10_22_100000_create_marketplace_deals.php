<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A purchase intent is consumed when its confirmation becomes a deal; the buyer must record interest again before the seller can confirm the
        // same intent a second time (and never while the first deal is still active).
        Schema::table('marketplace_purchase_intents', function (Blueprint $t) {
            $t->timestamp('converted_at')->nullable()->after('product_name');
        });

        // The SELLER's side of a fixed-price purchase: the seller has looked at a buyer's purchase intent and offers to proceed on exactly these terms.
        // It is NOT an agreement and exposes no contact; it becomes a deal only when the buyer confirms it. `open_slot` is 'O' while it awaits the buyer
        // (NULL afterwards), so the unique key lets the database refuse a second open confirmation on one intent whatever the application does.
        Schema::create('marketplace_deal_confirmations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('reference', 20)->unique();                  // CNF-2026-00001
            $t->foreignUuid('intent_id')->constrained('marketplace_purchase_intents')->restrictOnDelete();
            $t->foreignUuid('listing_id')->constrained('marketplace_listings')->restrictOnDelete();
            $t->foreignUuid('shop_id')->constrained('marketplace_shops')->restrictOnDelete();
            $t->foreignUuid('buyer_id')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status', 14);                               // awaiting_buyer | converted | withdrawn | lapsed | voided
            $t->char('open_slot', 1)->nullable();

            // Terms the seller confirmed (frozen)
            $t->decimal('quantity', 24, 6);
            $t->decimal('unit_price', 18, 2);
            $t->decimal('total_amount', 18, 2);
            $t->char('currency', 3)->default('NGN');
            $t->string('fulfilment_method', 16);                    // pickup | seller_delivery
            $t->decimal('delivery_charge_amount', 18, 2)->nullable();   // NULL = to be agreed directly; never part of total_amount

            // Listing snapshot at confirmation
            $t->string('listing_title', 150);
            $t->unsignedInteger('listing_version');
            $t->foreignUuid('unit_id')->constrained('units')->restrictOnDelete();
            $t->string('unit_code', 30);
            $t->string('product_kind', 16);
            $t->foreignUuid('species_id')->nullable()->constrained('species')->restrictOnDelete();
            $t->foreignUuid('crop_type_id')->nullable()->constrained('crop_types')->restrictOnDelete();
            $t->string('product_name', 120);

            $t->timestamp('expires_at');
            $t->timestamp('settled_at')->nullable();
            $t->string('void_reason', 30)->nullable();              // a CODE, never free text
            $t->timestamps();

            $t->unique(['intent_id', 'open_slot'], 'confirmations_one_open_per_intent');
            $t->index(['shop_id', 'status', 'created_at']);
            $t->index(['buyer_id', 'created_at']);
            $t->index(['status', 'expires_at']);
        });

        // A deal summary: the commercial terms both parties finalised, frozen. Not an order, an invoice, a payment, a reservation or a sale. Exactly one
        // source: an accepted offer (`offer_id`) or a buyer-confirmed seller confirmation (`confirmation_id`), each unique, so the database refuses a second
        // deal from the same source.
        Schema::create('marketplace_deals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('reference', 20)->unique();                  // DEL-2026-00001
            $t->foreignUuid('shop_id')->constrained('marketplace_shops')->restrictOnDelete();
            $t->foreignUuid('listing_id')->constrained('marketplace_listings')->restrictOnDelete();
            $t->foreignUuid('buyer_id')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('offer_id')->nullable()->unique()->constrained('marketplace_offers')->restrictOnDelete();
            $t->foreignUuid('confirmation_id')->nullable()->unique()->constrained('marketplace_deal_confirmations')->restrictOnDelete();
            $t->foreignUuid('intent_id')->nullable()->constrained('marketplace_purchase_intents')->restrictOnDelete();
            $t->string('terms_source', 20);                         // accepted_offer | confirmed_intent
            $t->string('status', 10);                               // accepted | completed | cancelled

            // Frozen commercial terms (decimal strings end to end)
            $t->string('listing_title', 150);
            $t->unsignedInteger('listing_version');
            $t->string('product_kind', 16);
            $t->foreignUuid('species_id')->nullable()->constrained('species')->restrictOnDelete();
            $t->foreignUuid('crop_type_id')->nullable()->constrained('crop_types')->restrictOnDelete();
            $t->string('product_name', 120);
            $t->foreignUuid('unit_id')->constrained('units')->restrictOnDelete();
            $t->string('unit_code', 30);
            $t->decimal('quantity', 24, 6);
            $t->decimal('unit_price', 18, 2);
            $t->decimal('product_total', 18, 2);
            $t->decimal('listed_unit_price', 18, 2);
            $t->char('currency', 3)->default('NGN');

            // Frozen fulfilment terms. The delivery charge is NEVER part of product_total; NULL means "to be agreed directly".
            $t->string('fulfilment_method', 16);                    // pickup | seller_delivery
            $t->string('listing_fulfilment', 16);                   // what the listing offered: pickup | seller_delivery | both
            $t->string('pickup_area', 120)->nullable();
            $t->json('delivery_coverage')->nullable();
            $t->string('dispatch_estimate', 16)->nullable();
            $t->string('delivery_charge_mode', 20);                 // not_applicable | included | agreed_separately
            $t->decimal('delivery_charge_amount', 18, 2)->nullable();
            $t->string('buyer_contact_phone', 20)->nullable();      // optional, supplied by the buyer for THIS deal only

            // Completion is self-reported by each side; there is no platform verification.
            $t->timestamp('buyer_completed_at')->nullable();
            $t->timestamp('seller_completed_at')->nullable();
            $t->foreignUuid('seller_completed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancelled_by_side', 10)->nullable();        // buyer | seller
            $t->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('cancel_reason', 30)->nullable();            // a CODE
            $t->string('cancel_note', 500)->nullable();
            $t->foreignUuid('seller_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['shop_id', 'status', 'created_at']);
            $t->index(['buyer_id', 'created_at']);
            $t->index(['status', 'created_at']);
        });
        DB::statement('ALTER TABLE marketplace_deals ADD CONSTRAINT marketplace_deals_one_source CHECK ((offer_id IS NULL) <> (confirmation_id IS NULL))');

        Schema::create('marketplace_deal_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('deal_id')->constrained('marketplace_deals')->restrictOnDelete();
            $t->string('actor_kind', 10);                           // buyer | seller | system
            $t->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 24);                               // created | completion_confirmed | completed | cancelled | reported
            $t->string('from_status', 10)->nullable();
            $t->string('to_status', 10)->nullable();
            $t->string('code', 30)->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->index(['deal_id', 'created_at']);
        });

        // A complaint by one party. It preserves the deal and its history and changes nothing about the deal itself; Phase 27 owns triage and outcomes.
        Schema::create('marketplace_deal_reports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('reference', 20)->unique();                  // DRP-2026-00001
            $t->foreignUuid('deal_id')->constrained('marketplace_deals')->restrictOnDelete();
            $t->foreignUuid('reporter_id')->constrained('users')->restrictOnDelete();
            $t->string('reporter_side', 10);                        // buyer | seller
            $t->string('target', 10);                               // deal | buyer | seller
            $t->string('reason', 30);
            $t->text('description')->nullable();
            $t->string('status', 10)->default('open');
            $t->string('deal_status_at_report', 10);
            $t->timestamps();

            $t->unique(['deal_id', 'reporter_id', 'target'], 'deal_reports_one_per_reporter_target');
            $t->index(['status', 'created_at']);
        });

        // Every disclosure of a party's private contact details, who saw it and which fields.
        Schema::create('marketplace_deal_contact_views', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('deal_id')->constrained('marketplace_deals')->restrictOnDelete();
            $t->foreignUuid('viewer_id')->constrained('users')->restrictOnDelete();
            $t->string('viewer_kind', 10);                          // buyer | seller | admin
            $t->json('fields');                                     // field NAMES disclosed, never values
            $t->timestamp('created_at')->useCurrent();

            $t->index(['deal_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_deal_contact_views');
        Schema::dropIfExists('marketplace_deal_reports');
        Schema::dropIfExists('marketplace_deal_events');
        Schema::dropIfExists('marketplace_deals');
        Schema::dropIfExists('marketplace_deal_confirmations');
        Schema::table('marketplace_purchase_intents', function (Blueprint $t) {
            $t->dropColumn('converted_at');
        });
    }
};
