<?php

namespace Tests\Feature\Marketplace;

use App\Models\CropType;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/** The anonymous feed: visibility, privacy, search, filters, sorting and pagination. */
class MarketplacePublicListingTest extends ListingTestCase
{
    /** Two shops, six published listings and a few that must never appear. @return array<string, string> label => listing id */
    private function market(): array
    {
        $ada = $this->seller('Ada');
        $adaShop = $this->activeShop($ada, ['name' => 'Ada Farms', 'state' => 'Oyo', 'city' => 'Ibadan']);
        $out = [];
        $out['chicken'] = $this->live($adaShop, $this->chicken(['unit_price' => '8000', 'negotiable' => true]));
        $out['catfish'] = $this->live($adaShop, $this->catfish(['unit_price' => '3500', 'pickup_area' => 'Ring road', 'fulfilment' => 'both']));
        $out['eggs'] = $this->live($adaShop, $this->eggsCrate(['unit_price' => '6000', 'negotiable' => false]));
        $out['draft'] = $this->createId($adaShop, $this->yam(['title' => 'Draft only yam']));
        $out['paused'] = $this->live($adaShop, $this->yam(['title' => 'Paused yam']));
        $this->postJson(self::SELLER."/shops/$adaShop/listings/{$out['paused']}/pause")->assertOk();
        $out['archived'] = $this->live($adaShop, $this->yam(['title' => 'Archived yam']));
        $this->postJson(self::SELLER."/shops/$adaShop/listings/{$out['archived']}/archive")->assertOk();

        $bola = $this->seller('Bola');
        $bolaShop = $this->activeShop($bola, ['name' => 'Bola Produce', 'state' => 'Lagos', 'city' => 'Ikeja']);
        $out['yam'] = $this->live($bolaShop, $this->yam(['unit_price' => '2000', 'pickup_area' => 'Mile 12', 'negotiable' => true]));
        $out['tomatoes'] = $this->live($bolaShop, $this->tomatoes(['unit_price' => '15000', 'fulfilment' => 'seller_delivery', 'delivery_coverage' => ['Lagos', 'Ogun'], 'delivery_charge' => 'included', 'dispatch_estimate' => 'same_day']));
        $out['maize'] = $this->live($bolaShop, ['title' => 'Dry maize', 'product_kind' => 'crop_produce', 'crop_type_id' => CropType::where('code', 'maize')->value('id'), 'unit' => 'bag', 'unit_price' => '45000.50', 'available_quantity' => '100',
            'package' => ['quantity' => '50', 'unit' => 'kg'], 'pickup_area' => 'Ikeja depot']);
        $this->signInAs($this->admin())->postJson(self::ADMIN."/shops/$bolaShop/verification", ['decision' => 'verified'])->assertOk();

        // a draft shop's listing and a restricted listing
        $draftShop = $this->draftShop($this->seller('Cee'));
        $out['draft_shop'] = $this->createId($draftShop, $this->chicken(['title' => 'From a draft shop']));
        $this->signInAs($ada);
        $out['restricted'] = $this->live($adaShop, $this->chicken(['title' => 'Restricted chicken']));
        $this->signInAs($this->admin())->postJson(self::ADMIN."/listings/{$out['restricted']}/restrict", ['reason' => 'Prohibited'])->assertOk();
        $this->app['auth']->forgetGuards();

        return $out;
    }

    private function feed(string $query = ''): TestResponse
    {
        return $this->getJson(self::PUBLIC.($query ? "?$query" : ''))->assertOk();
    }

    private function titles(string $query = ''): array
    {
        return collect($this->feed($query)->json('data'))->pluck('title')->all();
    }

    public function test_the_feed_is_anonymous_and_shows_only_published_listings_of_active_shops(): void
    {
        $m = $this->market();
        $feed = $this->feed();
        $feed->assertJsonPath('meta.total', 6);
        $titles = collect($feed->json('data'))->pluck('title')->all();
        foreach (['Draft only yam', 'Paused yam', 'Archived yam', 'From a draft shop', 'Restricted chicken'] as $hidden) {
            $this->assertNotContains($hidden, $titles);
        }
        foreach ($m as $label => $id) {
            if (in_array($label, ['draft', 'paused', 'archived', 'draft_shop', 'restricted'], true)) {
                $this->getJson(self::PUBLIC.'/'.$this->slug($id))->assertNotFound();
            } else {
                $this->getJson(self::PUBLIC.'/'.$this->slug($id))->assertOk()->assertJsonPath('data.id', $id);
            }
        }
        $this->getJson(self::PUBLIC.'/no-such-listing')->assertNotFound();
    }

    public function test_a_public_listing_exposes_an_allow_list_and_never_private_data(): void
    {
        $m = $this->market();
        $body = $this->getJson(self::PUBLIC.'/'.$this->slug($m['catfish']))->assertOk();
        $data = $body->json('data');

        $this->assertEqualsCanonicalizing(['id', 'reference', 'slug', 'title', 'description', 'product', 'price', 'quantity', 'negotiable', 'package', 'fulfilment', 'location', 'image', 'images', 'shop', 'published_at'], array_keys($data));
        $this->assertEqualsCanonicalizing(['id', 'slug', 'name', 'tagline', 'description', 'seller_type', 'categories', 'location', 'verified', 'verified_at', 'farm_backed', 'contact_methods', 'member_since'], array_keys($data['shop']));
        $this->assertSame('seller_declared', $data['quantity']['basis']);
        $this->assertSame('Ring road', $data['fulfilment']['pickup_area']);

        $raw = $body->getContent();
        foreach ([self::PHONE, '2348099998888', 'ada.private@example.com', '12 Secret Street', 'Secret', $this->farm->id] as $secret) {
            $this->assertStringNotContainsString($secret, $raw, "public payload leaked [$secret]");
        }
        foreach (['farm_id', 'inventory', 'created_by', 'updated_by', 'version', 'restricted', 'address_line', 'contact_phone', 'contact_email', 'deleted_at', 'status'] as $key) {
            $this->assertStringNotContainsString('"'.$key.'"', $raw, "public payload exposes [$key]");
        }
        $this->assertSame(['in_app', 'phone', 'whatsapp', 'email'], $data['shop']['contact_methods'], 'channel names only, never values');

        // the list rows are the same allow-list, minus the photo gallery
        $row = $this->feed()->json('data.0');
        $this->assertArrayNotHasKey('images', $row);
        $this->assertArrayNotHasKey('farm_id', $row);
    }

    public function test_a_farm_backed_shops_listing_never_reveals_its_farm_or_inventory(): void
    {
        $this->onPlan('farm-pro');
        $shop = $this->activeShop($this->owner, ['farm_id' => $this->farm->id]);
        $id = $this->live($shop, $this->chicken());
        $this->app['auth']->forgetGuards();
        $raw = $this->getJson(self::PUBLIC.'/'.$this->slug($id))->assertOk()->assertJsonPath('data.shop.farm_backed', true)->getContent();
        $this->assertStringNotContainsString($this->farm->id, $raw);
        $this->assertStringNotContainsString('Green Acres', $raw);
    }

    public function test_search_matches_title_description_product_species_and_crop_names(): void
    {
        $this->market();
        $this->assertSame(['Fresh catfish'], $this->titles('q=catfish'));
        $this->assertSame(['Fresh tomatoes'], $this->titles('q=tomato'));                 // custom product name
        $this->assertSame(['Healthy point-of-lay chickens'], $this->titles('q=vaccinated'));   // description
        $this->assertSame(['Big yam tubers'], $this->titles('q=yam'));                    // crop type name
        $this->assertContains('Healthy point-of-lay chickens', $this->titles('q=chicken')); // species name
        $this->assertSame([], $this->titles('q=zzzzzz'));
        $this->assertSame([], $this->titles('q=%25'));    // wildcards are escaped, not interpreted
        $this->assertSame([], $this->titles('q=_'));
        $this->getJson(self::PUBLIC.'?q='.str_repeat('a', 101))->assertStatus(422);
    }

    public function test_filters_by_kind_master_data_location_unit_negotiable_and_fulfilment(): void
    {
        $this->market();
        $this->assertCount(1, $this->titles('product_kind=fish'));
        $this->assertCount(3, $this->titles('product_kind=crop_produce'));
        $this->assertSame(['Healthy point-of-lay chickens'], $this->titles('species=chicken'));
        $this->assertSame(['Big yam tubers'], $this->titles('crop_type=yam'));
        $this->assertSame(['Dry maize'], $this->titles('crop_type=maize'));
        $this->assertCount(3, $this->titles('state=Oyo'));
        $this->assertCount(3, $this->titles('state=Lagos'));
        $this->assertEqualsCanonicalizing(['Dry maize', 'Fresh tomatoes', 'Big yam tubers'], $this->titles('city=Ikeja'));
        $this->assertSame([], $this->titles('city=Ibadan&state=Lagos'));
        $this->assertSame(['Fresh catfish'], $this->titles('unit=kg'));
        $this->assertSame(['Dry maize'], $this->titles('unit=bag'));
        $this->assertEqualsCanonicalizing(['Healthy point-of-lay chickens', 'Big yam tubers'], $this->titles('negotiable=true'));
        $this->assertCount(4, $this->titles('negotiable=false'));
        // fulfilment: "both" appears under either
        $this->assertContains('Fresh catfish', $this->titles('fulfilment=pickup'));
        $this->assertContains('Fresh catfish', $this->titles('fulfilment=seller_delivery'));
        $this->assertContains('Fresh tomatoes', $this->titles('fulfilment=seller_delivery'));
        $this->assertNotContains('Fresh tomatoes', $this->titles('fulfilment=pickup'));
        $this->assertSame(['Fresh tomatoes'], $this->titles('delivers_to=Ogun'));
        $this->assertEqualsCanonicalizing(['Fresh catfish'], $this->titles('delivers_to=Oyo'));
        $this->assertCount(3, $this->titles('shop='.MarketplaceShop::where('name', 'Bola Produce')->value('slug')));
        $this->assertCount(3, $this->titles('verified=true'));
        $this->assertCount(3, $this->titles('verified=false'));
        $this->assertSame([], $this->titles('state=Kano'));
    }

    public function test_price_filters_are_exact_decimals_and_sorting_is_stable(): void
    {
        $this->market();
        $this->assertSame(['Dry maize'], $this->titles('min_price=45000.50'));      // inclusive and exact
        $this->assertSame([], $this->titles('min_price=45000.51'));
        $this->assertEqualsCanonicalizing(['Big yam tubers', 'Fresh catfish'], $this->titles('max_price=3500'));
        $this->assertSame(['Healthy point-of-lay chickens', 'Fresh eggs by the crate'], collect($this->titles('min_price=6000&max_price=8000'))->sortDesc()->values()->all());
        foreach (['min_price=-1', 'min_price=1.234', 'max_price=abc', 'min_price=500&max_price=100', 'min_price=20&max_price=10', 'min_price=1000&max_price=999.99'] as $bad) {
            $this->getJson(self::PUBLIC.'?'.$bad)->assertStatus(422);
        }

        $this->assertSame(['Big yam tubers', 'Fresh catfish', 'Fresh eggs by the crate', 'Healthy point-of-lay chickens', 'Fresh tomatoes', 'Dry maize'], $this->titles('sort=price_asc'));
        $this->assertSame(array_reverse($this->titles('sort=price_asc')), $this->titles('sort=price_desc'));
        $prices = collect($this->feed('sort=price_asc')->json('data'))->map(fn ($r) => $r['price']['amount_minor'])->all();
        $sorted = $prices;
        sort($sorted);
        $this->assertSame($sorted, $prices);
        $this->assertSame(['Dry maize'], array_slice($this->titles('sort=price_desc'), 0, 1));
        $this->getJson(self::PUBLIC.'?sort=cheapest')->assertStatus(422);
    }

    public function test_newest_and_relevance_ordering(): void
    {
        $this->market();
        $newest = $this->titles('sort=newest');
        $this->assertSame('Dry maize', $newest[0], 'the last published listing comes first');
        $this->assertSame($newest, $this->titles(), 'newest is the default');
        $this->assertSame($newest, $this->titles('sort=relevance'), 'without a search term relevance reads as newest');

        // a title match outranks a description-only match
        $shop = MarketplaceShop::where('name', 'Ada Farms')->firstOrFail();
        $this->signInAs(User::findOrFail($shop->created_by));
        $this->live($shop->id, $this->eggsCrate(['title' => 'Plain crate', 'description' => 'Best catfish feed sponsor']));
        $this->live($shop->id, $this->catfish(['title' => 'Catfish for sale', 'description' => 'Fresh']));
        $ranked = $this->titles('q=catfish&sort=relevance');
        $this->assertSame('Catfish for sale', $ranked[0]);
        $this->assertContains('Plain crate', $ranked);
    }

    public function test_pagination_and_limits(): void
    {
        $this->market();
        $page = $this->feed('per_page=2&page=2');
        $page->assertJsonPath('meta.per_page', 2)->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.last_page', 3)->assertJsonPath('meta.total', 6)->assertJsonCount(2, 'data');
        $all = collect([1, 2, 3])->flatMap(fn ($p) => collect($this->feed("per_page=2&page=$p")->json('data'))->pluck('id'))->all();
        $this->assertCount(6, array_unique($all), 'pages neither overlap nor skip');
        $this->getJson(self::PUBLIC.'?per_page=51')->assertStatus(422);
        $this->getJson(self::PUBLIC.'?per_page=0')->assertStatus(422);
        $this->getJson(self::PUBLIC.'?product_kind=guns')->assertStatus(422);
        $this->getJson(self::PUBLIC.'?fulfilment=courier')->assertStatus(422);
    }

    public function test_the_public_price_estimate_is_exact_non_binding_and_only_for_public_listings(): void
    {
        $m = $this->market();
        $slug = $this->slug($m['maize']);
        $this->getJson(self::PUBLIC."/$slug/price-preview?quantity=3")->assertOk()
            ->assertJsonPath('data.total', '135001.50')->assertJsonPath('data.total_minor', 13500150)->assertJsonPath('data.unit', 'bag')->assertJsonPath('data.binding', false);
        $this->getJson(self::PUBLIC."/$slug/price-preview?quantity=100.01")->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->getJson(self::PUBLIC."/$slug/price-preview")->assertStatus(422);
        $this->getJson(self::PUBLIC.'/'.$this->slug($m['draft']).'/price-preview?quantity=1')->assertNotFound();
        $this->getJson(self::PUBLIC.'/'.$this->slug($m['restricted']).'/price-preview?quantity=1')->assertNotFound();
    }

    public function test_the_public_package_statement_is_shown_as_declared_never_as_a_conversion(): void
    {
        $m = $this->market();
        $data = $this->getJson(self::PUBLIC.'/'.$this->slug($m['eggs']))->assertOk()->json('data');
        $this->assertSame(['quantity' => '30', 'unit' => 'egg', 'description' => 'Standard crate of 30 eggs', 'declared_by' => 'seller', 'is_conversion' => false], $data['package']);
        $this->assertSame('crate', $data['quantity']['unit']);
        $this->assertSame('40', $data['quantity']['available']);
    }

    public function test_closing_a_shop_and_deleting_nothing_changes_the_listings_themselves(): void
    {
        $m = $this->market();
        $shop = MarketplaceShop::where('name', 'Bola Produce')->firstOrFail();
        $this->signInAs(User::findOrFail($shop->created_by))->postJson(self::SELLER."/shops/{$shop->id}/close")->assertOk();
        $this->assertCount(3, $this->titles());
        $this->assertSame('published', MarketplaceListing::find($m['yam'])->status->value);
        $this->postJson(self::SELLER."/shops/{$shop->id}/reopen")->assertOk();
        $this->assertCount(6, $this->titles());
    }
}
