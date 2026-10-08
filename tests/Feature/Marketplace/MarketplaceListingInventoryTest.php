<?php

namespace Tests\Feature\Marketplace;

use App\Enums\FarmRole;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\MarketplaceListing;
use App\Models\Sale;
use App\Models\Species;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** The optional, informational link between a listing and the shop farm's own inventory. It never moves stock. */
class MarketplaceListingInventoryTest extends ListingTestCase
{
    private string $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->onPlan('farm-pro');
        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
        $this->shop = $this->activeShop($this->owner, ['farm_id' => $this->farm->id]);
    }

    private function stockEggs(string $pieces): void
    {
        $this->postJson('/api/v1/inventory/stock-in', ['output' => 'eggs', 'reason' => 'donation', 'components' => [['quantity' => $pieces, 'unit' => 'piece']],
            'recorded_at' => now()->utc()->subHours(2)->format('Y-m-d\TH:i:s\Z'), 'idempotency_key' => (string) Str::uuid()])->assertCreated();
    }

    private function feedItem(string $name = 'Layer mash'): string
    {
        return $this->postJson('/api/v1/inventory/items', ['name' => $name, 'category' => 'feed', 'stock_unit' => 'kg'])->assertCreated()->json('data.id');
    }

    private function eggsItemId(): string
    {
        return InventoryItem::where('farm_id', $this->farm->id)->where('system_key', 'output:eggs')->value('id');
    }

    private function snapshot(): array
    {
        return [InventoryMovement::count(), Sale::count()];
    }

    public function test_a_listing_links_to_the_shop_farms_own_inventory_without_touching_stock(): void
    {
        $this->stockEggs('90');
        $item = $this->eggsItemId();
        $before = $this->snapshot();

        $r = $this->create($this->shop, $this->eggsCrate(['unit' => 'tray', 'available_quantity' => '3', 'package' => ['quantity' => '30', 'unit' => 'egg'], 'inventory_item_id' => $item]))->assertCreated();
        $r->assertJsonPath('data.inventory_linked', true);
        $id = $r->json('data.id');
        $listing = MarketplaceListing::findOrFail($id);
        $this->assertSame($this->farm->id, $listing->farm_id);
        $this->assertSame($item, $listing->inventory_item_id);
        $this->assertSame('90', rtrim(rtrim((string) $listing->inventory_synced_quantity, '0'), '.'));

        $this->publish($this->shop, $id)->assertOk();
        $this->postJson(self::SELLER."/shops/{$this->shop}/listings/$id/pause")->assertOk();
        $this->publish($this->shop, $id)->assertOk();
        $this->assertSame($before, $this->snapshot(), 'no movement, reservation or sale was created by linking or publishing');
        $this->assertSame('90', $this->getJson('/api/v1/inventory/output-balances')->json('data.eggs.available.quantity'));
    }

    public function test_the_seller_console_shows_live_stock_and_staleness_but_the_public_never_does(): void
    {
        $this->stockEggs('90');
        $id = $this->createId($this->shop, $this->eggsCrate(['unit' => 'egg', 'available_quantity' => '90', 'package' => null, 'inventory_item_id' => $this->eggsItemId()]));
        $this->publish($this->shop, $id)->assertOk();

        $inv = $this->getJson(self::SELLER."/shops/{$this->shop}/listings/$id")->assertOk()->json('data.inventory');
        $this->assertSame(['quantity' => '90', 'unit' => 'piece'], $inv['on_hand']);
        $this->assertFalse($inv['changed_since_link']);
        $this->assertSame('90', $inv['synced']['quantity']);
        $this->assertFalse($inv['unit_comparable'], 'egg and piece are different units: nothing is converted or assumed');
        $this->assertNull($inv['exceeds_stock']);

        // stock changes behind the listing's back (30 eggs sold elsewhere)
        $this->postJson('/api/v1/inventory/stock-out', ['output' => 'eggs', 'reason' => 'internal_use', 'components' => [['quantity' => '30', 'unit' => 'piece']],
            'recorded_at' => now()->utc()->subHour()->format('Y-m-d\TH:i:s\Z'), 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $inv = $this->getJson(self::SELLER."/shops/{$this->shop}/listings/$id")->json('data.inventory');
        $this->assertSame('60', $inv['on_hand']['quantity']);
        $this->assertTrue($inv['changed_since_link'], 'the console warns that stock moved since the link');
        $this->assertStringContainsString('seller', $inv['note']);

        // the declared quantity is NOT adjusted automatically, and the public sees only the seller's declaration
        $this->assertSame('90', $this->getJson(self::SELLER."/shops/{$this->shop}/listings/$id")->json('data.quantity.available'));
        $this->app['auth']->forgetGuards();
        $public = $this->getJson(self::PUBLIC.'/'.$this->slug($id))->assertOk();
        $public->assertJsonPath('data.quantity.available', '90')->assertJsonPath('data.quantity.basis', 'seller_declared');
        foreach ([$this->eggsItemId(), 'inventory', 'on_hand', 'synced', $this->farm->id] as $secret) {
            $this->assertStringNotContainsString($secret, $public->getContent());
        }
    }

    public function test_a_comparable_unit_flags_a_declared_quantity_above_the_stock(): void
    {
        $item = $this->feedItem();
        $store = $this->postJson('/api/v1/storage-locations', ['name' => 'Feed store', 'type' => 'store'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/inventory/stock-in', ['inventory_item_id' => $item, 'storage_location_id' => $store, 'reason' => 'purchase', 'components' => [['quantity' => '100', 'unit' => 'kg']],
            'recorded_at' => now()->utc()->subHours(2)->format('Y-m-d\TH:i:s\Z'), 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $id = $this->createId($this->shop, ['title' => 'Layer mash', 'product_kind' => 'feed', 'custom_product_name' => 'Layer mash', 'unit' => 'kg', 'unit_price' => '650', 'available_quantity' => '150', 'inventory_item_id' => $item]);
        $inv = $this->getJson(self::SELLER."/shops/{$this->shop}/listings/$id")->json('data.inventory');
        $this->assertTrue($inv['unit_comparable']);
        $this->assertTrue($inv['exceeds_stock']);
        $this->patchJson(self::SELLER."/shops/{$this->shop}/listings/$id", ['available_quantity' => '80'])->assertOk();
        $this->assertFalse($this->getJson(self::SELLER."/shops/{$this->shop}/listings/$id")->json('data.inventory.exceeds_stock'));
    }

    public function test_eligible_inventory_lists_only_sellable_items_matching_the_product_kind(): void
    {
        $this->stockEggs('10');
        $feed = $this->feedItem();
        $this->postJson('/api/v1/inventory/items', ['name' => 'Dewormer', 'category' => 'medicine', 'stock_unit' => 'ml'])->assertCreated();
        $this->postJson('/api/v1/inventory/items', ['name' => 'Seed maize', 'category' => 'seed_planting_material', 'stock_unit' => 'kg'])->assertCreated();
        $produce = $this->postJson('/api/v1/inventory/items', ['name' => 'Harvested cassava', 'category' => 'produce', 'stock_unit' => 'kg'])->assertCreated()->json('data.id');

        $all = collect($this->getJson(self::SELLER."/shops/{$this->shop}/listings/eligible-inventory")->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing(['Eggs', 'Layer mash', 'Harvested cassava'], $all->pluck('name')->all(), 'medicine and seed are never sellable');
        $this->assertSame('eggs', $all->firstWhere('name', 'Eggs')['kind']);
        $this->assertSame(['quantity' => '10', 'unit' => 'piece'], $all->firstWhere('name', 'Eggs')['on_hand']);

        $this->assertSame([$this->eggsItemId()], collect($this->getJson(self::SELLER."/shops/{$this->shop}/listings/eligible-inventory?product_kind=eggs")->json('data'))->pluck('id')->all());
        $this->assertSame([$feed], collect($this->getJson(self::SELLER."/shops/{$this->shop}/listings/eligible-inventory?product_kind=feed")->json('data'))->pluck('id')->all());
        $this->assertSame([$produce], collect($this->getJson(self::SELLER."/shops/{$this->shop}/listings/eligible-inventory?product_kind=crop_produce")->json('data'))->pluck('id')->all());
        $this->getJson(self::SELLER."/shops/{$this->shop}/listings/eligible-inventory?product_kind=livestock")->assertStatus(422);
    }

    public function test_ineligible_foreign_and_unknown_items_are_all_refused_the_same_way(): void
    {
        $this->stockEggs('10');
        $feed = $this->feedItem();
        $medicine = $this->postJson('/api/v1/inventory/items', ['name' => 'Dewormer', 'category' => 'medicine', 'stock_unit' => 'ml'])->assertCreated()->json('data.id');
        $seed = $this->postJson('/api/v1/inventory/items', ['name' => 'Seed maize', 'category' => 'seed_planting_material', 'stock_unit' => 'kg'])->assertCreated()->json('data.id');
        $inactive = $this->postJson('/api/v1/inventory/items', ['name' => 'Old feed', 'category' => 'feed', 'stock_unit' => 'kg'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/inventory/items/$inactive", ['is_active' => false])->assertOk();

        [$strangerOwner, $strangerFarm] = $this->otherFarm();
        $foreign = InventoryItem::create(['farm_id' => $strangerFarm->id, 'name' => 'Their feed', 'category' => 'feed', 'stock_unit_id' => InventoryItem::findOrFail($feed)->stock_unit_id, 'created_by' => $strangerOwner->id, 'tracks_lots' => false, 'tracks_expiry' => false, 'is_active' => true]);

        $feedListing = fn (string $item) => $this->create($this->shop, ['title' => 'Feed', 'product_kind' => 'feed', 'custom_product_name' => 'Feed', 'unit' => 'kg', 'unit_price' => '600', 'available_quantity' => '10', 'inventory_item_id' => $item]);
        foreach ([$medicine, $seed, $inactive, $foreign->id, (string) Str::uuid(), $this->eggsItemId()] as $bad) {
            $feedListing($bad)->assertStatus(422)->assertJsonValidationErrors('inventory_item_id');
        }
        $feedListing('not-a-uuid')->assertStatus(422)->assertJsonValidationErrors('inventory_item_id');
        // eggs only back egg listings, feed only feed listings; livestock and fish have no stock to link
        $this->create($this->shop, $this->eggsCrate(['inventory_item_id' => $feed]))->assertStatus(422)->assertJsonValidationErrors('inventory_item_id');
        $this->create($this->shop, $this->chicken(['inventory_item_id' => $feed]))->assertStatus(422)->assertJsonValidationErrors('inventory_item_id');
        $this->assertSame(0, MarketplaceListing::whereNotNull('inventory_item_id')->count());
        $this->assertSame(0, MarketplaceListing::whereNotNull('farm_id')->count());
    }

    public function test_the_link_needs_farm_permissions_and_a_farm_backed_shop(): void
    {
        $this->stockEggs('10');
        $item = $this->eggsItemId();

        // marketplace-only shop: there is no farm inventory
        $solo = $this->seller('Solo');
        $soloShop = $this->activeShop($solo);
        $this->create($soloShop, $this->eggsCrate(['inventory_item_id' => $item]))->assertStatus(422)->assertJsonValidationErrors('inventory_item_id');
        $this->getJson(self::SELLER."/shops/$soloShop/listings/eligible-inventory")->assertStatus(422);

        // a shop manager who is only a farm worker (no marketplace.manage on the farm) may run listings but not link inventory
        $worker = $this->member(FarmRole::FarmWorker, name: 'Wale Worker');
        $this->signInAs($this->owner)->postJson(self::SELLER."/shops/{$this->shop}/members", ['email' => $worker->email, 'role' => 'manager'])->assertCreated();
        $this->signInAs($worker);
        $this->create($this->shop, $this->eggsCrate(['inventory_item_id' => $item]))->assertForbidden();
        $this->getJson(self::SELLER."/shops/{$this->shop}/listings/eligible-inventory")->assertForbidden();
        $own = $this->createId($this->shop, $this->eggsCrate());   // without the link it is fine
        $this->patchJson(self::SELLER."/shops/{$this->shop}/listings/$own", ['inventory_item_id' => $item])->assertForbidden();
        $this->assertNull(MarketplaceListing::find($own)->inventory_item_id);

        // a shop member who is not on the farm at all
        $outsider = $this->seller('Outsider');
        $this->signInAs($this->owner)->postJson(self::SELLER."/shops/{$this->shop}/members", ['email' => $outsider->email, 'role' => 'manager'])->assertCreated();
        $this->signInAs($outsider)->create($this->shop, $this->eggsCrate(['inventory_item_id' => $item]))->assertForbidden();
        // reading a linked listing never leaks the inventory block to them
        $this->signInAs($this->owner);
        $linked = $this->createId($this->shop, $this->eggsCrate(['inventory_item_id' => $item]));
        $this->signInAs($outsider)->getJson(self::SELLER."/shops/{$this->shop}/listings/$linked")->assertOk()->assertJsonPath('data.inventory', null)->assertJsonPath('data.inventory_linked', true);
    }

    public function test_the_link_can_be_changed_and_removed_and_a_product_kind_change_drops_it(): void
    {
        $this->stockEggs('90');
        $id = $this->createId($this->shop, $this->eggsCrate(['inventory_item_id' => $this->eggsItemId()]));
        $this->patchJson(self::SELLER."/shops/{$this->shop}/listings/$id", ['inventory_item_id' => null])->assertOk()->assertJsonPath('data.inventory_linked', false);
        $row = MarketplaceListing::findOrFail($id);
        $this->assertNull($row->inventory_item_id);
        $this->assertNull($row->farm_id);
        $this->assertNull($row->inventory_synced_quantity);

        $this->patchJson(self::SELLER."/shops/{$this->shop}/listings/$id", ['inventory_item_id' => $this->eggsItemId()])->assertOk()->assertJsonPath('data.inventory_linked', true);
        // switching to livestock (no stock) removes the link instead of keeping a meaningless one
        $this->patchJson(self::SELLER."/shops/{$this->shop}/listings/$id", ['product_kind' => 'livestock', 'species_id' => Species::where('code', 'goat')->value('id'), 'unit' => 'head'])->assertOk()->assertJsonPath('data.inventory_linked', false);
        $this->assertNull(MarketplaceListing::findOrFail($id)->inventory_item_id);
    }

    public function test_future_deal_integration_is_documented_and_no_sale_workflow_is_triggered_by_listings(): void
    {
        // Phase 23 has no offers, acceptance or deals: nothing in a listing's lifecycle may create a Sale or move stock.
        $this->stockEggs('60');
        $before = $this->snapshot();
        $id = $this->live($this->shop, $this->eggsCrate(['inventory_item_id' => $this->eggsItemId()]));
        $this->patchJson(self::SELLER."/shops/{$this->shop}/listings/$id", ['available_quantity' => '1'])->assertOk();
        $this->postJson(self::SELLER."/shops/{$this->shop}/listings/$id/archive")->assertOk();
        $this->postJson(self::SELLER."/shops/{$this->shop}/listings/$id/restore")->assertOk();
        $this->assertSame($before, $this->snapshot());
        $this->assertFileExists(base_path('docs/api/PHASE-23-MARKETPLACE-LISTINGS.md'));
        $this->assertStringContainsString('Agreement is not payment or fulfilment', file_get_contents(base_path('docs/api/PHASE-23-MARKETPLACE-LISTINGS.md')));
    }
}
