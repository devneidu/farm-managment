<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Curated, REUSABLE illustrative images. A listing points at one row; the image is never copied per listing. `asset_path` is relative to the
        // marketplace disk and stays NULL until the licensed asset is seeded (see docs/api/PHASE-23-IMAGE-ASSET-RUNBOOK.md): such a row is "awaiting_asset"
        // and is never offered or served.
        Schema::create('marketplace_catalog_images', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 60)->unique();
            $t->string('label', 100);
            $t->string('product_kind', 16);
            $t->foreignUuid('species_id')->nullable()->constrained('species')->restrictOnDelete();
            $t->foreignUuid('crop_type_id')->nullable()->constrained('crop_types')->restrictOnDelete();
            $t->boolean('is_kind_fallback')->default(false);       // the category-level fallback of its product_kind
            $t->string('asset_path', 255)->nullable();
            $t->string('mime_type', 40)->nullable();
            $t->string('alt_text', 160);
            $t->boolean('is_illustrative')->default(true);
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->string('licence', 120)->nullable();
            $t->string('attribution', 255)->nullable();
            $t->timestamps();

            $t->index(['product_kind', 'is_active']);
            $t->index('species_id');
            $t->index('crop_type_id');
        });

        Schema::create('marketplace_listings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('reference', 20)->unique();                  // LST-2026-00001
            $t->string('slug', 190)->unique();                      // public identity, immutable
            $t->foreignUuid('shop_id')->constrained('marketplace_shops')->restrictOnDelete();
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // Product: existing master data where it exists, a free name where it does not
            $t->string('product_kind', 16);                         // livestock | fish | crop_produce | eggs | milk | feed | other
            $t->foreignUuid('species_id')->nullable()->constrained('species')->restrictOnDelete();
            $t->foreignUuid('crop_type_id')->nullable()->constrained('crop_types')->restrictOnDelete();
            $t->string('custom_product_name', 120)->nullable();
            $t->string('title', 150);
            $t->text('description')->nullable();

            // Price and quantity (decimal strings end to end; NGN only in V1)
            $t->foreignUuid('unit_id')->constrained('units')->restrictOnDelete();
            $t->decimal('unit_price', 18, 2);
            $t->char('currency', 3)->default('NGN');
            $t->decimal('available_quantity', 24, 6);
            $t->decimal('min_order_quantity', 24, 6)->nullable();
            $t->timestamp('quantity_updated_at')->nullable();
            $t->boolean('negotiable')->default(false);

            // What one package holds, AS DECLARED BY THE SELLER. Descriptive only: never used to convert or to deduct stock.
            $t->foreignUuid('package_basis_unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $t->decimal('package_quantity', 24, 6)->nullable();
            $t->string('package_description', 160)->nullable();

            // Seller-arranged fulfilment (no Farmvest logistics)
            $t->string('fulfilment', 16)->default('pickup');        // pickup | seller_delivery | both
            $t->string('pickup_area', 120)->nullable();             // GENERAL public pickup area; the exact address stays in the private shop contact
            $t->json('delivery_coverage')->nullable();              // list of states / cities the seller delivers to
            $t->string('dispatch_estimate', 16)->nullable();
            $t->string('delivery_charge', 20)->nullable();          // included | agreed_separately

            // Public location (defaults from the shop at creation, then independent)
            $t->string('state', 60)->nullable();
            $t->string('city', 80)->nullable();
            $t->string('area', 120)->nullable();

            // Images
            $t->foreignUuid('catalog_image_id')->nullable()->constrained('marketplace_catalog_images')->nullOnDelete();

            // Optional link to the shop's own farm inventory. PRIVATE: never serialised publicly. Composite FK keeps the item inside the shop's farm.
            $t->foreignUuid('farm_id')->nullable()->constrained('farms')->restrictOnDelete();
            $t->uuid('inventory_item_id')->nullable();
            $t->decimal('inventory_synced_quantity', 24, 6)->nullable();
            $t->timestamp('inventory_synced_at')->nullable();

            // Lifecycle
            $t->string('status', 12)->default('draft');            // draft | published | paused | archived | restricted
            $t->timestamp('published_at')->nullable();             // first publication
            $t->timestamp('paused_at')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->timestamp('restricted_at')->nullable();
            $t->string('restricted_reason', 500)->nullable();
            $t->foreignUuid('restricted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedInteger('version')->default(1);            // optimistic concurrency token
            $t->timestamps();
            $t->softDeletes();

            $t->foreign(['farm_id', 'inventory_item_id'])->references(['farm_id', 'id'])->on('inventory_items')->restrictOnDelete();
            $t->index(['status', 'product_kind']);
            $t->index(['shop_id', 'status']);
            $t->index(['status', 'state', 'city']);
            $t->index(['status', 'unit_price']);
            $t->index(['status', 'published_at']);
        });

        Schema::create('marketplace_listing_images', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('listing_id')->constrained('marketplace_listings')->cascadeOnDelete();
            $t->foreignUuid('shop_id')->constrained('marketplace_shops')->restrictOnDelete();
            $t->string('path', 255);                                // relative to the marketplace disk; random name, never client-controlled
            $t->string('original_name', 200)->nullable();           // display only
            $t->string('mime_type', 40);
            $t->unsignedInteger('size');
            $t->unsignedSmallInteger('width');
            $t->unsignedSmallInteger('height');
            $t->char('sha256', 64);
            $t->unsignedSmallInteger('position')->default(0);
            $t->string('alt_text', 160)->nullable();
            $t->foreignUuid('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();

            $t->unique(['listing_id', 'sha256']);
            $t->index(['listing_id', 'position']);
        });

        // Append-only history of every lifecycle change (who, from, to, why). Survives soft deletion of the listing.
        Schema::create('marketplace_listing_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('listing_id')->constrained('marketplace_listings')->restrictOnDelete();
            $t->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('actor_kind', 10);                           // seller | platform
            $t->string('action', 24);                               // created | published | paused | archived | restored | restricted | restriction_lifted | deleted
            $t->string('from_status', 12)->nullable();
            $t->string('to_status', 12)->nullable();
            $t->string('reason', 500)->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->index(['listing_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_listing_events');
        Schema::dropIfExists('marketplace_listing_images');
        Schema::dropIfExists('marketplace_listings');
        Schema::dropIfExists('marketplace_catalog_images');
    }
};
