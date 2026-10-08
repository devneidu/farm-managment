<?php

namespace Tests\Feature\Marketplace;

use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOffer;
use App\Models\MarketplacePurchaseIntent;
use App\Models\Sale;
use App\Models\User;

class MarketplacePurchaseIntentTest extends OfferTestCase
{
    private function intent(array $body = [], ?User $by = null, ?string $slug = null)
    {
        $this->signInAs($by ?? $this->buyer);

        return $this->postJson(self::SELLER.'/listings/'.($slug ?? $this->slug).'/purchase-intent', array_replace(['quantity' => '10'], $body));
    }

    public function test_proceeding_at_the_listed_price_records_interest_only(): void
    {
        $stock = (string) MarketplaceListing::find($this->listingId)->available_quantity;
        $this->intent()->assertCreated()
            ->assertJsonPath('data.kind', 'purchase_intent')->assertJsonPath('data.agreement', 'none')
            ->assertJsonPath('data.terms.unit_price', '8000.00')->assertJsonPath('data.terms.total', '80000.00')
            ->assertJsonPath('data.is_current', true)->assertJsonPath('data.contact', null);

        $this->assertMatchesRegularExpression('/^PIN-\d{4}-\d{5}$/', MarketplacePurchaseIntent::firstOrFail()->reference);
        $this->assertSame(0, MarketplaceOffer::count());
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame($stock, (string) MarketplaceListing::find($this->listingId)->available_quantity);   // nothing reserved
        $this->assertSame(1, AuditLog::where('action', 'marketplace.purchase_intent_recorded')->count());
    }

    public function test_it_is_idempotent_and_a_new_quantity_refreshes_the_same_record(): void
    {
        $first = $this->intent()->assertCreated()->json('data.id');
        $this->intent()->assertOk()->assertJsonPath('data.id', $first);
        $this->assertSame(1, AuditLog::where('action', 'marketplace.purchase_intent_recorded')->count());

        $this->intent(['quantity' => '20'])->assertOk()->assertJsonPath('data.id', $first)->assertJsonPath('data.terms.total', '160000.00');
        $this->assertSame(1, MarketplacePurchaseIntent::count());
        $this->assertSame(2, AuditLog::where('action', 'marketplace.purchase_intent_recorded')->count());
    }

    public function test_it_follows_the_same_quantity_and_visibility_rules_as_an_offer(): void
    {
        $this->intent(['quantity' => '4'])->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->intent(['quantity' => '500'])->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->intent([], $this->sellerUser)->assertForbidden()->assertJsonPath('code', 'cannot_negotiate_own_listing');
        $this->intent([], null, 'unknown-listing')->assertNotFound();
        $this->signInAs($this->sellerUser)->postJson(self::SELLER."/shops/{$this->shopId}/listings/{$this->listingId}/pause")->assertOk();
        $this->intent()->assertNotFound();
        $this->assertSame(0, MarketplacePurchaseIntent::count());
    }

    public function test_it_works_on_fixed_price_listings_and_after_offers_are_used_up(): void
    {
        $fixed = $this->live($this->shopId, $this->chicken(['title' => 'Fixed hens', 'negotiable' => false]));
        $this->intent([], null, $this->slug($fixed))->assertCreated();

        $this->setting('marketplace_max_offers_per_buyer', 1);
        $id = $this->offerId();
        $this->respond('reject', $id)->assertOk();
        $this->offer(['unit_price' => '7500'])->assertStatus(409)->assertJsonPath('code', 'offer_limit_reached');
        $this->intent()->assertCreated();
    }

    public function test_staleness_is_computed_when_read(): void
    {
        $id = $this->intent()->assertCreated()->json('data.id');
        $this->editListing(['unit_price' => '9000'])->assertOk();
        $this->signInAs($this->buyer)->getJson(self::SELLER.'/my/enquiries?kind=purchase_intent')->assertOk()
            ->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.is_current', false)->assertJsonPath('data.0.terms.unit_price', '8000.00');
    }

    public function test_the_buyer_history_mixes_offers_and_intents_and_the_seller_sees_intents_by_name(): void
    {
        $this->offerId();
        $this->intent()->assertCreated();
        $this->getJson(self::SELLER.'/my/enquiries')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson(self::SELLER.'/my/enquiries?kind=offer')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.kind', 'offer');
        $this->getJson(self::SELLER.'/my/enquiries?status=pending')->assertOk()->assertJsonCount(1, 'data');

        $this->signInAs($this->sellerUser)->getJson(self::SELLER."/shops/{$this->shopId}/purchase-intents")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.buyer.name', 'Bola Buyer');
        $staff = $this->addMember($this->sellerUser, $this->shopId, 'staff');
        $this->signInAs($staff)->getJson(self::SELLER."/shops/{$this->shopId}/purchase-intents")->assertOk()->assertJsonCount(1, 'data');
        $this->signInAs($this->buyer)->getJson(self::SELLER."/shops/{$this->shopId}/purchase-intents")->assertNotFound();
    }
}
