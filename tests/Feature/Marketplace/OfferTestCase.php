<?php

namespace Tests\Feature\Marketplace;

use App\Models\MarketplaceOffer;
use App\Models\PlatformSetting;
use App\Models\User;

/** Shared fixtures for the Phase 24 negotiation tests: a seller with a live negotiable listing and a buyer. */
abstract class OfferTestCase extends ListingTestCase
{
    protected User $sellerUser;

    protected string $shopId;

    protected string $listingId;

    protected string $slug;

    protected User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sellerUser = $this->seller();
        $this->shopId = $this->activeShop($this->sellerUser);
        $this->listingId = $this->live($this->shopId, $this->chicken());   // 8000 per head, 5..120 available, negotiable
        $this->slug = $this->slug($this->listingId);
        $this->buyer = User::factory()->create(['name' => 'Bola Buyer']);
    }

    protected function setting(string $key, mixed $value): void
    {
        PlatformSetting::updateOrCreate(['key' => $key], ['value' => ['value' => $value]]);
    }

    protected function offerUrl(?string $slug = null): string
    {
        return self::SELLER.'/listings/'.($slug ?? $this->slug).'/offers';
    }

    /** POST an offer as $by (default: the buyer). */
    protected function offer(array $body = [], ?User $by = null, ?string $slug = null)
    {
        $this->signInAs($by ?? $this->buyer);

        return $this->postJson($this->offerUrl($slug), array_replace(['quantity' => '10', 'unit_price' => '7000'], $body));
    }

    protected function offerId(array $body = [], ?User $by = null): string
    {
        return $this->offer($body, $by)->assertCreated()->json('data.id');
    }

    protected function respond(string $action, string $offerId, ?User $by = null, ?string $shop = null)
    {
        $this->signInAs($by ?? $this->sellerUser);

        return $this->postJson(self::SELLER.'/shops/'.($shop ?? $this->shopId)."/offers/$offerId/$action");
    }

    protected function offerStatus(?User $by = null)
    {
        $this->signInAs($by ?? $this->buyer);

        return $this->getJson(self::SELLER."/listings/{$this->slug}/offer-status");
    }

    protected function editListing(array $body)
    {
        $this->signInAs($this->sellerUser);

        return $this->patchJson(self::SELLER."/shops/{$this->shopId}/listings/{$this->listingId}", $body);
    }

    protected function stored(string $offerId): MarketplaceOffer
    {
        return MarketplaceOffer::findOrFail($offerId);
    }
}
