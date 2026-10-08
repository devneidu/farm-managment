<?php

namespace Tests\Feature\Marketplace;

use App\Models\AuditLog;
use App\Models\MarketplaceDealContactView;
use App\Models\MarketplaceShop;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/** Contact exchange: nothing private before a deal, minimal and audited after it, only to the two parties and platform admins. */
class MarketplaceDealContactTest extends DealTestCase
{
    private const SELLER_WHATSAPP = '+2348099998888';

    private const SELLER_EMAIL = 'ada.private@example.com';

    private const SELLER_ADDRESS = '12 Secret Street, Bodija';

    /** @return list<string> every private contact value in play */
    private function secrets(): array
    {
        return [self::PHONE, self::SELLER_WHATSAPP, self::SELLER_EMAIL, self::SELLER_ADDRESS, $this->buyer->email, self::BUYER_PHONE, 'Secret Street'];
    }

    private function assertNoContact(TestResponse $response): void
    {
        $body = $response->getContent();
        foreach ($this->secrets() as $secret) {
            $this->assertStringNotContainsString($secret, $body, "private contact '$secret' leaked in ".$response->baseResponse->getStatusCode().' response');
        }
    }

    public function test_nothing_private_is_exposed_before_a_deal_exists(): void
    {
        $offer = $this->acceptedOfferId();                       // accepted in principle only
        $intent = $this->intentId('10');
        $confirmation = $this->sellerConfirm($intent, ['fulfilment_method' => 'pickup'])->assertCreated()->json('data.id');   // confirmed by the seller only

        $this->signInAs($this->buyer);
        foreach (["/my/offers/$offer", '/my/enquiries', "/my/deal-confirmations/$confirmation", "/listings/{$this->slug}/offer-status", "/listings/{$this->fishSlug}/offer-status"] as $path) {
            $this->assertNoContact($this->getJson(self::SELLER.$path)->assertOk());
        }
        $this->assertNoContact($this->getJson(self::PUBLIC)->assertOk());
        $this->assertNoContact($this->getJson(self::PUBLIC.'/'.$this->slug)->assertOk());
        $this->assertNoContact($this->getJson(self::PUBLIC.'/'.$this->fishSlug)->assertOk());

        $this->signInAs($this->sellerUser);
        foreach (["/shops/{$this->shopId}/offers", "/shops/{$this->shopId}/offers/$offer", "/shops/{$this->shopId}/purchase-intents", "/shops/{$this->shopId}/deals"] as $path) {
            $this->assertNoContact($this->getJson(self::SELLER.$path)->assertOk());
        }
        $this->assertSame(0, MarketplaceDealContactView::count());
    }

    public function test_deal_payloads_never_carry_contact_only_the_audited_endpoint_does(): void
    {
        $id = $this->dealId(['contact_phone' => self::BUYER_PHONE]);
        $admin = $this->admin();

        $this->assertNoContact($this->signInAs($this->buyer)->getJson(self::SELLER.'/my/deals')->assertOk());
        $this->assertNoContact($this->getJson(self::SELLER."/my/deals/$id")->assertOk());
        $this->assertNoContact($this->getJson(self::SELLER."/my/offers/{$this->deal($id)->offer_id}")->assertOk());
        $this->assertNoContact($this->getJson(self::SELLER.'/my/enquiries')->assertOk());
        $this->assertNoContact($this->signInAs($this->sellerUser)->getJson(self::SELLER."/shops/{$this->shopId}/deals")->assertOk());
        $this->assertNoContact($this->getJson(self::SELLER."/shops/{$this->shopId}/deals/$id")->assertOk());
        $this->assertNoContact($this->getJson(self::SELLER."/shops/{$this->shopId}/offers/{$this->deal($id)->offer_id}")->assertOk());
        $this->assertNoContact($this->signInAs($admin)->getJson(self::ADMIN.'/deals')->assertOk());
        $this->assertNoContact($this->getJson(self::ADMIN."/deals/$id")->assertOk());
        $this->assertNoContact($this->getJson(self::PUBLIC.'/'.$this->slug)->assertOk());
        $this->assertSame(0, MarketplaceDealContactView::count());
    }

    public function test_the_buyer_receives_the_sellers_contact_and_the_address_only_for_pickup(): void
    {
        $pickup = $this->dealId();
        $this->buyerContact($pickup)->assertOk()
            ->assertJsonPath('data.party', 'seller')->assertJsonPath('data.deal.fulfilment_method', 'pickup')
            ->assertJsonPath('data.contact.preferred_contact_method', 'whatsapp')
            ->assertJsonPath('data.contact.channels.phone', self::PHONE)->assertJsonPath('data.contact.channels.whatsapp', self::SELLER_WHATSAPP)->assertJsonPath('data.contact.channels.email', self::SELLER_EMAIL)
            ->assertJsonPath('data.contact.pickup.address_line', self::SELLER_ADDRESS)->assertJsonPath('data.contact.pickup.area', null)->assertJsonPath('data.contact.delivery', null)
            ->assertJsonStructure(['data' => ['notice']]);

        $delivery = $this->fixedPriceDealId(['fulfilment_method' => 'seller_delivery'], [], User::factory()->create(['name' => 'Delivery Buyer']));
        $deliveryBuyer = $this->deal($delivery)->buyer;
        $response = $this->buyerContact($delivery, $deliveryBuyer)->assertOk()
            ->assertJsonPath('data.deal.fulfilment_method', 'seller_delivery')->assertJsonPath('data.contact.channels.phone', self::PHONE)
            ->assertJsonPath('data.contact.delivery.coverage', ['Oyo', 'Lagos'])->assertJsonPath('data.contact.delivery.dispatch_estimate', '1_2_days')->assertJsonPath('data.contact.pickup', null);
        $this->assertStringNotContainsString('Secret Street', $response->getContent());   // no private address for a delivery deal
    }

    public function test_the_seller_receives_the_buyers_name_email_and_the_optional_phone(): void
    {
        $withPhone = $this->dealId(['contact_phone' => self::BUYER_PHONE]);
        $this->sellerContact($withPhone)->assertOk()
            ->assertJsonPath('data.party', 'buyer')->assertJsonPath('data.contact.name', 'Bola Buyer')->assertJsonPath('data.contact.channels.email', $this->buyer->email)
            ->assertJsonPath('data.contact.channels.phone', self::BUYER_PHONE)->assertJsonPath('data.contact.preferred_contact_method', 'phone');

        $noPhone = User::factory()->create(['name' => 'Email Only']);
        $id = $this->dealId([], $noPhone);
        $response = $this->sellerContact($id)->assertOk()->assertJsonPath('data.contact.preferred_contact_method', 'email')->assertJsonPath('data.contact.channels.email', $noPhone->email);
        $this->assertArrayNotHasKey('phone', $response->json('data.contact.channels'));   // email is the fallback; no phone is ever assumed
        $this->assertStringNotContainsString(self::PHONE, $response->getContent());   // and the seller's own details are not echoed back
    }

    public function test_only_the_two_parties_can_read_contact(): void
    {
        $id = $this->dealId(['contact_phone' => self::BUYER_PHONE]);
        $stranger = User::factory()->create();
        $outsider = $this->seller('Outsider');
        $otherShop = $this->activeShop($outsider);
        $staff = $this->addMember($this->sellerUser, $this->shopId, 'staff');

        $this->buyerContact($id, $stranger)->assertNotFound();
        $this->buyerContact($id, $this->sellerUser)->assertNotFound();           // the seller is not the buyer
        $this->sellerContact($id, $this->buyer)->assertNotFound();               // the buyer is not a member
        $this->sellerContact($id, $stranger)->assertNotFound();
        $this->sellerContact($id, $outsider)->assertNotFound();                  // not a member of this shop
        $this->sellerContact($id, $outsider, $otherShop)->assertNotFound();      // another shop cannot reach this deal
        $this->sellerContact($id, $staff)->assertForbidden();                    // a member without deal.respond
        $this->assertSame(0, MarketplaceDealContactView::count());               // refused attempts are not "disclosures"
    }

    public function test_cancelling_ends_contact_access_but_completed_deals_keep_it(): void
    {
        $gone = $this->dealId();
        $this->buyerContact($gone)->assertOk();
        $this->dealAction('cancel', $gone, ['reason' => 'changed_mind'])->assertOk();
        $this->buyerContact($gone)->assertStatus(409)->assertJsonPath('code', 'deal_contact_unavailable');
        $this->sellerContact($gone)->assertStatus(409)->assertJsonPath('code', 'deal_contact_unavailable');
        $this->signInAs($this->buyer)->getJson(self::SELLER."/my/deals/$gone")->assertOk()->assertJsonPath('data.contact_available', false);

        $done = $this->dealId([], User::factory()->create());
        $buyer = $this->deal($done)->buyer;
        $this->dealAction('complete', $done, [], $buyer)->assertOk();
        $this->asSeller('complete', $done)->assertOk();
        $this->buyerContact($done, $buyer)->assertOk();
        $this->sellerContact($done)->assertOk();
    }

    public function test_every_contact_read_is_recorded_with_field_names_only(): void
    {
        $id = $this->dealId(['contact_phone' => self::BUYER_PHONE]);

        $this->buyerContact($id)->assertOk();
        $this->sellerContact($id)->assertOk();
        $this->sellerContact($id)->assertOk();

        $views = MarketplaceDealContactView::where('deal_id', $id)->orderBy('created_at')->orderBy('id')->get();
        $this->assertCount(3, $views);
        $this->assertSame(['buyer', 'seller', 'seller'], $views->pluck('viewer_kind')->all());
        $this->assertSame($this->buyer->id, $views[0]->viewer_id);
        $this->assertSame($this->sellerUser->id, $views[1]->viewer_id);
        $this->assertEqualsCanonicalizing(['channels.phone', 'channels.whatsapp', 'channels.email', 'address_line'], $views[0]->fields);
        $this->assertEqualsCanonicalizing(['channels.email', 'channels.phone'], $views[1]->fields);

        $audits = AuditLog::where('action', 'marketplace.deal_contact_viewed')->get();
        $this->assertCount(3, $audits);
        $dump = json_encode($audits->map->only(['changes', 'resource_label'])->all()).json_encode($views->pluck('fields')->all());
        foreach ($this->secrets() as $secret) {
            $this->assertStringNotContainsString($secret, $dump, 'the audit trail must hold field names, never contact values');
        }
    }

    public function test_the_contact_log_is_append_only(): void
    {
        $id = $this->dealId();
        $this->buyerContact($id)->assertOk();
        $view = MarketplaceDealContactView::firstOrFail();

        $this->expectException(\LogicException::class);
        $view->update(['viewer_kind' => 'admin']);
    }

    public function test_contact_deletion_is_refused_too(): void
    {
        $id = $this->dealId();
        $this->buyerContact($id)->assertOk();

        $this->expectException(\LogicException::class);
        MarketplaceDealContactView::firstOrFail()->delete();
    }

    public function test_the_sellers_current_contact_is_what_is_disclosed(): void
    {
        $id = $this->dealId();
        MarketplaceShop::whereKey($this->shopId)->update(['contact_phone' => '+2348012345678']);

        $this->buyerContact($id)->assertOk()->assertJsonPath('data.contact.channels.phone', '+2348012345678');
    }

    public function test_platform_admins_read_both_parties_through_a_separate_audited_call(): void
    {
        $id = $this->dealId(['contact_phone' => self::BUYER_PHONE]);
        $this->dealAction('cancel', $id, ['reason' => 'other'])->assertOk();
        $admin = $this->admin();

        $this->signInAs($admin)->getJson(self::ADMIN."/deals/$id/contact")->assertOk()
            ->assertJsonPath('data.seller.channels.phone', self::PHONE)->assertJsonPath('data.buyer.channels.email', $this->buyer->email)->assertJsonPath('data.buyer.channels.phone', self::BUYER_PHONE)
            ->assertJsonPath('data.deal.status', 'cancelled');   // an investigation may need a cancelled deal

        $view = MarketplaceDealContactView::where('viewer_kind', 'admin')->firstOrFail();
        $this->assertSame($admin->id, $view->viewer_id);
        $this->assertContains('buyer.channels.phone', $view->fields);
        $this->assertContains('seller.address_line', $view->fields);
        $audit = AuditLog::where('action', 'platform.marketplace_deal_contact_viewed')->firstOrFail();
        $this->assertNull($audit->farm_id);
        $this->assertStringNotContainsString(self::BUYER_PHONE, json_encode($audit->changes));
    }

    public function test_contact_reads_are_rate_limited(): void
    {
        $id = $this->dealId();
        $this->signInAs($this->buyer);
        foreach (range(1, 30) as $_) {
            $this->getJson(self::SELLER."/my/deals/$id/contact")->assertOk();
        }
        $this->getJson(self::SELLER."/my/deals/$id/contact")->assertStatus(429);
    }
}
