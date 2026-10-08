<?php

namespace Tests\Feature\Marketplace;

use App\Models\AuditLog;
use App\Models\CropType;
use App\Models\MarketplaceListing;
use App\Models\Species;
use App\Models\Unit;
use Database\Seeders\MeasurementSeeder;

/** Creating listings: product identity, units, decimal-safe price and quantity, minimum order and seller-declared packages. */
class MarketplaceListingPricingTest extends ListingTestCase
{
    public function test_a_marketplace_only_seller_creates_a_draft_listing_without_a_farm_or_an_image(): void
    {
        $user = $this->seller();
        $shop = $this->draftShop($user);   // not even approved: drafts may be prepared early

        $response = $this->create($shop, $this->chicken())->assertCreated();
        $response->assertJsonPath('data.status', 'draft')->assertJsonPath('data.is_public', false)->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.reference', 'LST-'.now()->format('Y').'-00001')
            ->assertJsonPath('data.product.kind', 'livestock')->assertJsonPath('data.product.name', 'Chicken')
            ->assertJsonPath('data.price.amount', '8000.00')->assertJsonPath('data.price.amount_minor', 800000)->assertJsonPath('data.price.currency', 'NGN')
            ->assertJsonPath('data.price.per.code', 'head')->assertJsonPath('data.price.per.integer_only', true)
            ->assertJsonPath('data.quantity.available', '120')->assertJsonPath('data.quantity.min_order', '5')->assertJsonPath('data.quantity.basis', 'seller_declared')
            ->assertJsonPath('data.negotiable', true)->assertJsonPath('data.package', null)
            ->assertJsonPath('data.fulfilment.mode', 'pickup')->assertJsonPath('data.location.state', 'Oyo')->assertJsonPath('data.location.city', 'Ibadan')
            ->assertJsonPath('data.image.source', 'placeholder')->assertJsonPath('data.image.has_image', false)->assertJsonPath('data.image.placeholder_kind', 'livestock')
            ->assertJsonPath('data.image.url', null)->assertJsonPath('data.images', []);

        $listing = MarketplaceListing::findOrFail($response->json('data.id'));
        $this->assertSame($user->id, $listing->created_by);
        $this->assertNull($listing->farm_id);
        $this->assertMatchesRegularExpression('/^healthy-point-of-lay-chickens-[a-z0-9]{6}$/', $listing->slug);
        $this->assertDatabaseHas('audit_logs', ['action' => 'marketplace.listing_created', 'actor_id' => $user->id, 'resource_id' => $listing->id]);
        $this->assertDatabaseHas('marketplace_listing_events', ['listing_id' => $listing->id, 'action' => 'created', 'to_status' => 'draft', 'actor_kind' => 'seller']);
    }

    public function test_the_client_cannot_set_lifecycle_ownership_or_farm_fields(): void
    {
        $user = $this->seller();
        $shop = $this->draftShop($user);
        $other = $this->activeShop($this->seller('Other'));
        $this->signInAs($user);
        $id = $this->create($shop, $this->chicken() + [
            'status' => 'published', 'version' => 9, 'shop_id' => $other, 'created_by' => $this->owner->id, 'farm_id' => $this->farm->id, 'slug' => 'hijack', 'reference' => 'LST-X',
            'restricted_reason' => 'x', 'published_at' => now()->toIso8601String(), 'currency' => 'USD',
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.version', 1)->assertJsonPath('data.price.currency', 'NGN')->json('data.id');

        $listing = MarketplaceListing::findOrFail($id);
        $this->assertSame($shop, $listing->shop_id);
        $this->assertSame($user->id, $listing->created_by);
        $this->assertNull($listing->farm_id);
        $this->assertNull($listing->published_at);
        $this->assertNotSame('hijack', $listing->slug);
    }

    public function test_product_identity_uses_existing_master_data_or_a_custom_name(): void
    {
        $shop = $this->draftShop($this->seller());

        // livestock / fish / crops need master data or a name
        $this->create($shop, $this->chicken(['species_id' => null]))->assertStatus(422)->assertJsonValidationErrors('custom_product_name');
        $this->create($shop, $this->chicken(['species_id' => null, 'custom_product_name' => 'Guinea fowl']))->assertCreated()->assertJsonPath('data.product.name', 'Guinea fowl')->assertJsonPath('data.product.species', null);
        // a name refines master data, never replaces it
        $this->create($shop, $this->catfish())->assertCreated()->assertJsonPath('data.product.species.code', 'fish')->assertJsonPath('data.product.name', 'Catfish');
        // an unknown / wrong-kind reference is refused
        $this->create($shop, $this->chicken(['species_id' => '019a0000-0000-7000-8000-000000000000']))->assertStatus(422)->assertJsonValidationErrors('species_id');
        $fish = Species::where('code', 'fish')->value('id');
        $this->create($shop, $this->chicken(['species_id' => $fish]))->assertStatus(422)->assertJsonValidationErrors('species_id');          // fish is not livestock
        $this->create($shop, $this->catfish(['species_id' => Species::where('code', 'goat')->value('id')]))->assertStatus(422)->assertJsonValidationErrors('species_id');
        $this->create($shop, $this->yam(['species_id' => $fish]))->assertStatus(422)->assertJsonValidationErrors('species_id');               // crops carry no species
        $this->create($shop, $this->chicken(['crop_type_id' => CropType::where('code', 'yam')->value('id')]))->assertStatus(422)->assertJsonValidationErrors('crop_type_id');
        // 'other' must be named; eggs and milk may name a producing species
        $this->create($shop, ['title' => 'Honey jars', 'product_kind' => 'other', 'unit' => 'bottle', 'unit_price' => '3000', 'available_quantity' => '10',
            'package' => ['quantity' => '500', 'unit' => 'g']])->assertStatus(422)->assertJsonValidationErrors('custom_product_name');
        $this->create($shop, $this->eggsCrate(['species_id' => Species::where('code', 'chicken')->value('id')]))->assertCreated()->assertJsonPath('data.product.species.code', 'chicken');
    }

    public function test_units_are_limited_per_product_kind_and_listing_examples_are_priced_per_unit(): void
    {
        $shop = $this->draftShop($this->seller());

        $this->create($shop, $this->chicken(['unit' => 'kg']))->assertStatus(422)->assertJsonValidationErrors('unit');
        $this->create($shop, $this->eggsCrate(['unit' => 'l']))->assertStatus(422)->assertJsonValidationErrors('unit');
        $this->create($shop, $this->chicken(['unit' => 'furlong']))->assertStatus(422)->assertJsonValidationErrors('unit');

        // the documented examples
        $this->create($shop, $this->chicken())->assertCreated()->assertJsonPath('data.price.amount', '8000.00');                                     // N8,000 per head
        $this->create($shop, $this->catfish())->assertCreated()->assertJsonPath('data.price.per.code', 'kg');                                         // N3,500 per kg
        $this->create($shop, $this->eggsCrate())->assertCreated()->assertJsonPath('data.price.per.code', 'crate');                                    // N6,000 per crate
        $this->create($shop, $this->yam())->assertCreated()->assertJsonPath('data.price.per.code', 'tuber')->assertJsonPath('data.price.amount', '2000.00');   // N2,000 per tuber
        $this->create($shop, $this->tomatoes())->assertCreated()->assertJsonPath('data.price.per.code', 'basket')->assertJsonPath('data.price.amount_minor', 1500000); // N15,000 per basket
    }

    public function test_the_new_selling_units_exist_as_package_units_without_any_conversion(): void
    {
        foreach (['basket', 'tuber', 'bunch'] as $code) {
            $unit = Unit::with('dimension')->where('code', $code)->firstOrFail();
            $this->assertSame('package', $unit->dimension->code);
            $this->assertNull($unit->family, "$code must never convert to anything");
            $this->assertTrue($unit->is_system);
        }
        $this->assertTrue(Unit::where('code', 'tuber')->value('integer_only') == true, 'a tuber is counted whole');
        $this->assertFalse((bool) Unit::where('code', 'basket')->value('integer_only'));

        // re-running the (insert-only) provisioning changes nothing and duplicates nothing
        $before = Unit::count();
        (new MeasurementSeeder)->run();
        $this->assertSame($before, Unit::count());
    }

    public function test_price_is_an_exact_positive_naira_amount_with_at_most_two_decimals(): void
    {
        $shop = $this->draftShop($this->seller());
        foreach (['0', '0.00', '-5', '10.005', '12.3456', 'abc', '1e3', '', '99999999999'] as $bad) {
            $this->create($shop, $this->chicken(['unit_price' => $bad]))->assertStatus(422)->assertJsonValidationErrors('unit_price');
        }
        $this->create($shop, $this->chicken(['unit_price' => null]))->assertStatus(422)->assertJsonValidationErrors('unit_price');

        $this->create($shop, $this->chicken(['unit_price' => '1234.5']))->assertCreated()->assertJsonPath('data.price.amount', '1234.50')->assertJsonPath('data.price.amount_minor', 123450);
        $this->create($shop, $this->chicken(['unit_price' => 8000]))->assertCreated()->assertJsonPath('data.price.amount', '8000.00');          // JSON integer
        $this->create($shop, $this->chicken(['unit_price' => 99.99]))->assertCreated()->assertJsonPath('data.price.amount_minor', 9999);       // JSON float: exact, via its shortest text
        $this->create($shop, $this->chicken(['unit_price' => '0.01']))->assertCreated()->assertJsonPath('data.price.amount_minor', 1);
    }

    public function test_quantities_follow_the_unit_whole_numbers_for_heads_and_limited_decimals_for_weight(): void
    {
        $shop = $this->draftShop($this->seller());
        $this->create($shop, $this->chicken(['available_quantity' => '2.5', 'min_order_quantity' => null]))->assertStatus(422)->assertJsonValidationErrors('available_quantity');
        $this->create($shop, $this->yam(['available_quantity' => '10.5']))->assertStatus(422)->assertJsonValidationErrors('available_quantity');   // a tuber is whole
        $this->create($shop, $this->eggsCrate(['unit' => 'tray', 'available_quantity' => '3.25']))->assertCreated()->assertJsonPath('data.quantity.available', '3.25');   // package units: 2 places
        $this->create($shop, $this->eggsCrate(['available_quantity' => '3.255']))->assertStatus(422)->assertJsonValidationErrors('available_quantity');
        $this->create($shop, $this->catfish(['available_quantity' => '250.123']))->assertCreated()->assertJsonPath('data.quantity.available', '250.123');   // kg: 3 places
        $this->create($shop, $this->catfish(['available_quantity' => '250.1234']))->assertStatus(422)->assertJsonValidationErrors('available_quantity');
        foreach (['0', '-1', 'x', '1000000000000'] as $bad) {
            $this->create($shop, $this->catfish(['available_quantity' => $bad]))->assertStatus(422)->assertJsonValidationErrors('available_quantity');
        }
    }

    public function test_minimum_order_cannot_exceed_the_available_quantity_and_is_rechecked_on_update(): void
    {
        $shop = $this->draftShop($this->seller());
        $this->create($shop, $this->chicken(['min_order_quantity' => '121']))->assertStatus(422)->assertJsonValidationErrors('min_order_quantity');
        $this->create($shop, $this->chicken(['min_order_quantity' => '0']))->assertStatus(422)->assertJsonValidationErrors('min_order_quantity');
        $this->create($shop, $this->chicken(['min_order_quantity' => '2.5']))->assertStatus(422)->assertJsonValidationErrors('min_order_quantity');
        $id = $this->create($shop, $this->chicken(['min_order_quantity' => '120']))->assertCreated()->json('data.id');   // equal is fine

        $this->patchJson(self::SELLER."/shops/$shop/listings/$id", ['available_quantity' => '50'])->assertStatus(422)->assertJsonValidationErrors('min_order_quantity');
        $this->patchJson(self::SELLER."/shops/$shop/listings/$id", ['available_quantity' => '50', 'min_order_quantity' => '10'])->assertOk()->assertJsonPath('data.quantity.available', '50');
        $this->patchJson(self::SELLER."/shops/$shop/listings/$id", ['min_order_quantity' => null])->assertOk()->assertJsonPath('data.quantity.min_order', null);
    }

    public function test_container_units_require_a_seller_declared_package_and_other_units_reject_one(): void
    {
        $shop = $this->draftShop($this->seller());

        $this->create($shop, $this->eggsCrate(['package' => null]))->assertStatus(422)->assertJsonValidationErrors('package');
        $this->create($shop, $this->tomatoes(['package' => ['quantity' => '25']]))->assertStatus(422)->assertJsonValidationErrors('package.unit');
        $this->create($shop, $this->tomatoes(['package' => ['quantity' => '25', 'unit' => 'bag']]))->assertStatus(422)->assertJsonValidationErrors('package.unit');   // a container is not a content unit
        $this->create($shop, $this->eggsCrate(['package' => ['quantity' => '0', 'unit' => 'egg']]))->assertStatus(422)->assertJsonValidationErrors('package.quantity');
        $this->create($shop, $this->eggsCrate(['package' => ['quantity' => '30.5', 'unit' => 'egg']]))->assertStatus(422)->assertJsonValidationErrors('package.quantity');   // eggs are whole
        $this->create($shop, $this->catfish(['package' => ['quantity' => '1', 'unit' => 'kg']]))->assertStatus(422)->assertJsonValidationErrors('package');           // kg is not a container
        $this->create($shop, $this->yam(['package' => ['quantity' => '1', 'unit' => 'kg']]))->assertStatus(422)->assertJsonValidationErrors('package');              // nor is a tuber

        $this->create($shop, $this->eggsCrate())->assertCreated()
            ->assertJsonPath('data.package.quantity', '30')->assertJsonPath('data.package.unit', 'egg')->assertJsonPath('data.package.declared_by', 'seller')
            ->assertJsonPath('data.package.is_conversion', false)->assertJsonPath('data.package.description', 'Standard crate of 30 eggs')
            ->assertJsonPath('data.quantity.available', '40')->assertJsonPath('data.quantity.unit', 'crate');   // never silently turned into 1,200 eggs
        // a different seller states a different crate: nothing is universal
        $this->create($shop, $this->eggsCrate(['package' => ['quantity' => '36', 'unit' => 'egg']]))->assertCreated()->assertJsonPath('data.package.quantity', '36');
    }

    public function test_changing_the_unit_drops_a_package_statement_that_no_longer_applies(): void
    {
        $shop = $this->draftShop($this->seller());
        $id = $this->createId($shop, $this->eggsCrate());

        // a package statement belongs to the unit it was made for: even crate -> tray must be restated, never carried over
        $this->patchJson(self::SELLER."/shops/$shop/listings/$id", ['unit' => 'tray'])->assertStatus(422)->assertJsonValidationErrors('package');
        $this->patchJson(self::SELLER."/shops/$shop/listings/$id", ['unit' => 'tray', 'package' => ['quantity' => '12', 'unit' => 'egg']])->assertOk()->assertJsonPath('data.package.quantity', '12')->assertJsonPath('data.price.per.code', 'tray');
        $this->patchJson(self::SELLER."/shops/$shop/listings/$id", ['unit' => 'egg', 'available_quantity' => '1200'])->assertOk()->assertJsonPath('data.package', null)->assertJsonPath('data.price.per.code', 'egg');
        $this->patchJson(self::SELLER."/shops/$shop/listings/$id", ['unit' => 'crate'])->assertStatus(422)->assertJsonValidationErrors('package');   // must restate the contents
    }

    public function test_price_preview_uses_exact_decimals_and_respects_minimum_and_available_quantity(): void
    {
        $shop = $this->draftShop($this->seller());
        $fish = $this->createId($shop, $this->catfish(['unit_price' => '3500.55', 'min_order_quantity' => '1']));

        $r = $this->postJson(self::SELLER."/shops/$shop/listings/$fish/price-preview", ['quantity' => '2.333'])->assertOk();
        $r->assertJsonPath('data.total', '8166.78')->assertJsonPath('data.total_minor', 816678)->assertJsonPath('data.binding', false)->assertJsonPath('data.currency', 'NGN')->assertJsonPath('data.unit_price', '3500.55');

        // classic binary-float trap: 0.1 x 3 must be exactly 0.30
        $cheap = $this->createId($shop, $this->catfish(['unit_price' => '0.10', 'min_order_quantity' => null]));
        $this->postJson(self::SELLER."/shops/$shop/listings/$cheap/price-preview", ['quantity' => '3'])->assertOk()->assertJsonPath('data.total', '0.30')->assertJsonPath('data.total_minor', 30);
        // half-up rounding to kobo
        $odd = $this->createId($shop, $this->catfish(['unit_price' => '0.01', 'min_order_quantity' => null]));
        $this->postJson(self::SELLER."/shops/$shop/listings/$odd/price-preview", ['quantity' => '0.5'])->assertOk()->assertJsonPath('data.total', '0.01');

        $this->postJson(self::SELLER."/shops/$shop/listings/$fish/price-preview", ['quantity' => '0.5'])->assertStatus(422)->assertJsonValidationErrors('quantity');    // below minimum order
        $this->postJson(self::SELLER."/shops/$shop/listings/$fish/price-preview", ['quantity' => '999'])->assertStatus(422)->assertJsonValidationErrors('quantity');    // above declared available
        $this->postJson(self::SELLER."/shops/$shop/listings/$fish/price-preview", ['quantity' => '1.2345'])->assertStatus(422)->assertJsonValidationErrors('quantity'); // too many decimals for kg
        $this->postJson(self::SELLER."/shops/$shop/listings/$fish/price-preview", [])->assertStatus(422)->assertJsonValidationErrors('quantity');
        $chicken = $this->createId($shop, $this->chicken());
        $this->postJson(self::SELLER."/shops/$shop/listings/$chicken/price-preview", ['quantity' => '5.5'])->assertStatus(422)->assertJsonValidationErrors('quantity');   // heads are whole
        $this->postJson(self::SELLER."/shops/$shop/listings/$chicken/price-preview", ['quantity' => '7'])->assertOk()->assertJsonPath('data.total', '56000.00');
    }

    public function test_fulfilment_options_are_seller_controlled_and_only_the_applicable_details_are_kept(): void
    {
        $shop = $this->draftShop($this->seller());

        $this->create($shop, $this->chicken(['fulfilment' => 'courier']))->assertStatus(422)->assertJsonValidationErrors('fulfilment');
        $this->create($shop, $this->chicken(['dispatch_estimate' => 'yesterday']))->assertStatus(422)->assertJsonValidationErrors('dispatch_estimate');
        $this->create($shop, $this->chicken(['delivery_charge' => 'free-shipping']))->assertStatus(422)->assertJsonValidationErrors('delivery_charge');
        $this->create($shop, $this->chicken(['delivery_coverage' => array_fill(0, 21, 'Oyo')]))->assertStatus(422)->assertJsonValidationErrors('delivery_coverage');

        // pickup only drops delivery details
        $this->create($shop, $this->chicken(['fulfilment' => 'pickup', 'pickup_area' => 'Bodija market', 'delivery_coverage' => ['Lagos'], 'delivery_charge' => 'included', 'dispatch_estimate' => 'same_day']))
            ->assertCreated()->assertJsonPath('data.fulfilment.pickup_area', 'Bodija market')->assertJsonPath('data.fulfilment.delivery_coverage', [])
            ->assertJsonPath('data.fulfilment.delivery_charge', null)->assertJsonPath('data.fulfilment.dispatch_estimate', null);
        // delivery only drops the pickup area
        $this->create($shop, $this->chicken(['fulfilment' => 'seller_delivery', 'pickup_area' => 'Bodija market', 'delivery_coverage' => ['Oyo', 'Ogun', 'Oyo'], 'delivery_charge' => 'included', 'dispatch_estimate' => '3_5_days']))
            ->assertCreated()->assertJsonPath('data.fulfilment.pickup_area', null)->assertJsonPath('data.fulfilment.delivery_coverage', ['Oyo', 'Ogun'])
            ->assertJsonPath('data.fulfilment.delivery_charge.value', 'included')->assertJsonPath('data.fulfilment.dispatch_estimate.label', '3-5 days')->assertJsonPath('data.fulfilment.arranged_by', 'seller');
        $this->create($shop, $this->catfish())->assertCreated()->assertJsonPath('data.fulfilment.mode', 'both')->assertJsonPath('data.fulfilment.delivery_charge.label', 'Delivery charge agreed separately');
    }

    public function test_an_unchanged_update_is_not_a_change_and_a_price_change_is_audited_with_old_and_new_values(): void
    {
        $user = $this->seller();
        $shop = $this->draftShop($user);
        $id = $this->createId($shop, $this->catfish(['unit_price' => '3500']));

        // quantities are stored at 6 places; sending the same value in another spelling must not bump the version
        $this->patchJson(self::SELLER."/shops/$shop/listings/$id", ['available_quantity' => '250.50', 'unit_price' => '3500.00', 'title' => 'Fresh catfish'])->assertOk()->assertJsonPath('data.version', 1);

        $this->patchJson(self::SELLER."/shops/$shop/listings/$id", ['unit_price' => '3800', 'available_quantity' => '200', 'version' => 1])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.price.amount', '3800.00');
        $audit = AuditLog::where('action', 'marketplace.listing_updated')->where('resource_id', $id)->firstOrFail();
        $this->assertEquals(['from' => '3500', 'to' => '3800'], $audit->changes['unit_price']);
        $this->assertEquals(['from' => '250.5', 'to' => '200'], $audit->changes['available_quantity']);
        $this->assertEqualsCanonicalizing(['unit_price', 'available_quantity'], $audit->changes['fields']);
    }

    public function test_product_options_describe_kinds_units_and_master_data_without_a_farm(): void
    {
        $this->getJson(self::SELLER.'/product-options')->assertUnauthorized();
        $user = $this->seller();
        $this->assertNull($user->currentFarm());

        $data = $this->signInAs($user)->getJson(self::SELLER.'/product-options')->assertOk()->json('data');
        $kinds = collect($data['kinds'])->keyBy('code');
        $this->assertEqualsCanonicalizing(['livestock', 'fish', 'crop_produce', 'eggs', 'milk', 'feed', 'other'], $kinds->keys()->all());
        $this->assertSame(['head'], collect($kinds['livestock']['units'])->pluck('code')->all());
        $this->assertContains('basket', collect($kinds['crop_produce']['units'])->pluck('code')->all());
        $crate = collect($kinds['eggs']['units'])->firstWhere('code', 'crate');
        $this->assertTrue($crate['requires_package_details']);
        $this->assertTrue(collect($kinds['livestock']['units'])->firstWhere('code', 'head')['integer_only']);
        // existing master data, not a second taxonomy
        $this->assertContains('chicken', collect($kinds['livestock']['products'])->pluck('code')->all());
        $this->assertNotContains('fish', collect($kinds['livestock']['products'])->pluck('code')->all());
        $this->assertSame(['fish'], collect($kinds['fish']['products'])->pluck('code')->all());
        $this->assertContains('yam', collect($kinds['crop_produce']['products'])->pluck('code')->all());
        $this->assertTrue($kinds['other']['custom_name_required']);
        $this->assertSame('NGN', $data['currency']);
        $this->assertSame(6, $data['limits']['max_images_per_listing']);
        $this->assertContains('seller_delivery', collect($data['fulfilment'])->pluck('value')->all());
        $this->assertContains('egg', collect($data['package_content_units'])->pluck('code')->all());
        $this->assertNotContains('crate', collect($data['package_content_units'])->pluck('code')->all());
    }
}
