<?php

namespace Tests\Feature\Marketplace;

use App\Models\MarketplaceDeal;
use App\Models\MarketplaceDealConfirmation;
use App\Models\MarketplacePurchaseIntent;
use App\Models\User;

/** Shared fixtures for the Phase 25 deal tests, on top of the negotiation fixtures: accepted offers, purchase intents, confirmations and deals. */
abstract class DealTestCase extends OfferTestCase
{
    protected const BUYER_PHONE = '+2348055501234';

    /** A second listing: fixed price, pickup OR seller delivery, charge agreed separately (8000 -> 3500 per kg of catfish, 250.5 kg declared). */
    protected string $fishListingId;

    protected string $fishSlug;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAs($this->sellerUser);
        $this->fishListingId = $this->live($this->shopId, $this->catfish(['pickup_area' => 'Bodija market']));
        $this->fishSlug = $this->slug($this->fishListingId);
    }

    // ------------------------------------------------------------------ A. accepted offer

    /** An offer on the chicken listing that the seller has accepted. */
    protected function acceptedOfferId(?User $buyer = null, array $body = []): string
    {
        $id = $this->offerId($body, $buyer);
        $this->respond('accept', $id)->assertOk();

        return $id;
    }

    protected function confirmOffer(string $offerId, array $body = [], ?User $by = null)
    {
        $this->signInAs($by ?? $this->buyer);

        return $this->postJson(self::SELLER."/my/offers/$offerId/deal", $body);
    }

    /** Accepted offer -> deal in one go; returns the deal id. */
    protected function dealId(array $body = [], ?User $buyer = null): string
    {
        $buyer ??= $this->buyer;

        return $this->confirmOffer($this->acceptedOfferId($buyer), $body, $buyer)->assertCreated()->json('data.id');
    }

    // ------------------------------------------------------------------ B. fixed-price intent

    protected function intentId(string $quantity = '10', ?User $by = null, ?string $slug = null): string
    {
        $this->signInAs($by ?? $this->buyer);

        return $this->postJson(self::SELLER.'/listings/'.($slug ?? $this->fishSlug).'/purchase-intent', ['quantity' => $quantity])->json('data.id');
    }

    protected function sellerConfirm(string $intentId, array $body = [], ?User $by = null, ?string $shop = null)
    {
        $this->signInAs($by ?? $this->sellerUser);

        return $this->postJson(self::SELLER.'/shops/'.($shop ?? $this->shopId)."/purchase-intents/$intentId/confirm", $body);
    }

    protected function buyerConfirm(string $confirmationId, array $body = [], ?User $by = null)
    {
        $this->signInAs($by ?? $this->buyer);

        return $this->postJson(self::SELLER."/my/deal-confirmations/$confirmationId/confirm", array_replace(['accept_terms' => true], $body));
    }

    /** Intent -> seller confirmation (pickup unless told otherwise); returns the confirmation id. */
    protected function confirmationId(array $body = [], string $quantity = '10', ?User $buyer = null): string
    {
        $intent = $this->intentId($quantity, $buyer);

        return $this->sellerConfirm($intent, array_replace(['fulfilment_method' => 'pickup'], $body))->assertCreated()->json('data.id');
    }

    /** Intent -> both confirmations -> deal; returns the deal id. */
    protected function fixedPriceDealId(array $sellerBody = [], array $buyerBody = [], ?User $buyer = null): string
    {
        $buyer ??= $this->buyer;

        return $this->buyerConfirm($this->confirmationId($sellerBody, '10', $buyer), $buyerBody, $buyer)->assertCreated()->json('data.id');
    }

    // ------------------------------------------------------------------ acting on a deal

    protected function dealAction(string $action, string $dealId, array $body = [], ?User $by = null, ?string $shop = null)
    {
        $this->signInAs($by ?? $this->buyer);
        $path = $shop === null ? self::SELLER."/my/deals/$dealId/$action" : self::SELLER."/shops/$shop/deals/$dealId/$action";

        return $this->postJson($path, $body);
    }

    protected function asSeller(string $action, string $dealId, array $body = [], ?User $by = null)
    {
        return $this->dealAction($action, $dealId, $body, $by ?? $this->sellerUser, $this->shopId);
    }

    protected function buyerContact(string $dealId, ?User $by = null)
    {
        $this->signInAs($by ?? $this->buyer);

        return $this->getJson(self::SELLER."/my/deals/$dealId/contact");
    }

    protected function sellerContact(string $dealId, ?User $by = null, ?string $shop = null)
    {
        $this->signInAs($by ?? $this->sellerUser);

        return $this->getJson(self::SELLER.'/shops/'.($shop ?? $this->shopId)."/deals/$dealId/contact");
    }

    protected function deal(string $id): MarketplaceDeal
    {
        return MarketplaceDeal::findOrFail($id);
    }

    protected function confirmation(string $id): MarketplaceDealConfirmation
    {
        return MarketplaceDealConfirmation::findOrFail($id);
    }

    protected function intentRow(string $id): MarketplacePurchaseIntent
    {
        return MarketplacePurchaseIntent::findOrFail($id);
    }
}
