<?php

namespace App\Services\Marketplace;

use App\Enums\ConfirmationStatus;
use App\Enums\DealStatus;
use App\Enums\ListingStatus;
use App\Enums\OfferStatus;
use App\Enums\ShopStatus;
use App\Models\MarketplaceDeal;
use App\Models\MarketplaceDealConfirmation;
use App\Models\MarketplaceDealEvent;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOffer;
use App\Models\MarketplacePurchaseIntent;
use App\Models\MarketplaceShop;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Platform\PlatformConfigService;
use App\Support\Api\ApiHttpException;
use App\Support\Measurement\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns an agreement into a deal summary. Two doors, one rule - a deal exists only once BOTH sides have said yes, and only then does contact change hands:
 *
 *  A. Negotiated: the seller already accepted a buyer's offer (Phase 24); the BUYER confirms within `marketplace_deal_confirmation_hours` of acceptance.
 *  B. Fixed price: the seller confirms a buyer's purchase intent first (a MarketplaceDealConfirmation: no agreement, no contact), then the BUYER confirms
 *     those exact terms, within the same window.
 *
 * A deal freezes the product, unit, quantity, unit price, product total and fulfilment terms. It reserves no stock, takes no money and creates no Sale,
 * invoice or finance record. Farmvest is not an escrow, payment processor or logistics provider.
 *
 * Concurrency. Buyer paths lock the LISTING row, then the intent / offer, then the confirmation; the seller path locks shop, listing, intent, then
 * confirmation (the same order Phase 24 uses), and deal-level actions lock only the deal row, so no two paths can wait on each other in a cycle. The
 * database also refuses a second deal from one offer or confirmation (unique keys) and a second open confirmation on one intent (`open_slot`).
 */
class MarketplaceDealService
{
    public const DEFAULT_CONFIRMATION_HOURS = 72;

    private const LOCK = 'marketplace_deal_create';

    public function __construct(
        private MarketplaceShopService $shops,
        private MarketplaceListingRules $rules,
        private MarketplaceOfferLifecycle $offerLifecycle,
        private MarketplaceConfirmationLifecycle $confirmations,
        private PlatformConfigService $config,
        private AuditLogger $audit,
    ) {}

    public function confirmationHours(): int
    {
        return (int) ($this->config->setting('marketplace_deal_confirmation_hours') ?? self::DEFAULT_CONFIRMATION_HOURS);
    }

    /** The last moment the buyer may turn an accepted offer into a deal. */
    public function offerDeadline(MarketplaceOffer $offer): ?Carbon
    {
        return $offer->responded_at?->copy()->addHours($this->confirmationHours());
    }

    // ------------------------------------------------------------------ A. accepted offer -> deal (buyer)

    /**
     * The buyer confirms an offer the seller accepted. Idempotent: confirming again returns the same deal. `[$deal, $created]`.
     *
     * @param  array{fulfilment_method?: ?string, contact_phone?: ?string}  $data
     * @return array{0: MarketplaceDeal, 1: bool}
     */
    public function confirmOffer(User $buyer, string $offerId, array $data): array
    {
        return MarketplaceReferences::locked(self::LOCK, fn () => DB::transaction(function () use ($buyer, $offerId, $data) {
            $probe = MarketplaceOffer::where('buyer_id', $buyer->id)->findOrFail($offerId);   // another buyer's offer is a 404
            $listing = MarketplaceListing::withTrashed()->lockForUpdate()->findOrFail($probe->listing_id);
            $offer = MarketplaceOffer::lockForUpdate()->findOrFail($offerId);

            if ($deal = MarketplaceDeal::where('offer_id', $offer->id)->first()) {
                return [$this->loaded($deal), false];
            }
            if ($offer->status !== OfferStatus::Accepted) {
                throw new ApiHttpException(409, 'offer_not_accepted', 'Only an offer the seller accepted can become a deal.', details: ['status' => $offer->effectiveStatus()->value]);
            }
            $deadline = $this->offerDeadline($offer);
            if ($deadline->lte(now())) {
                throw new ApiHttpException(409, 'deal_window_closed', 'The time to confirm this accepted offer has passed. Make a new offer if you are still interested.', details: ['deadline' => $deadline->toIso8601String()]);
            }
            $shop = MarketplaceShop::findOrFail($offer->shop_id);
            $this->assertOpenForBusiness($shop, $listing);
            $this->assertSameProduct($offer->unit_id, $offer->product_kind, $offer->species_id, $offer->crop_type_id, $offer->product_name, $listing);
            $quantity = Decimal::trim((string) $offer->quantity);
            $this->assertSupportable($listing, $quantity);
            $method = $this->resolveMethod($listing, $data['fulfilment_method'] ?? null);
            $this->assertSellerReachable($shop);

            $deal = $this->persist($buyer, $listing, $method, $data, [
                'terms_source' => 'accepted_offer', 'offer_id' => $offer->id, 'listing_title' => $offer->listing_title, 'listing_version' => $offer->listing_version,
                'product_kind' => $offer->product_kind, 'species_id' => $offer->species_id, 'crop_type_id' => $offer->crop_type_id, 'product_name' => $offer->product_name,
                'unit_id' => $offer->unit_id, 'unit_code' => $offer->unit_code, 'quantity' => $quantity, 'unit_price' => $this->rules->money((string) $offer->unit_price),
                'product_total' => $this->rules->money((string) $offer->total_amount), 'listed_unit_price' => $this->rules->money((string) $offer->listed_unit_price),
                'currency' => $offer->currency, 'delivery_charge_amount' => null, 'seller_confirmed_by' => $offer->responded_by,
            ]);

            return [$deal, true];
        }));
    }

    // ------------------------------------------------------------------ B. purchase intent -> seller confirmation -> buyer confirmation

    /**
     * The seller confirms a buyer's purchase intent, offering to proceed on exactly these terms. This is NOT an agreement: it exposes no contact and creates
     * no deal until the buyer confirms. Needs `deal.respond`. Repeating the same confirmation returns the open one. `[$confirmation, $created]`.
     *
     * @param  array{fulfilment_method?: ?string, delivery_charge?: ?string}  $data
     * @return array{0: MarketplaceDealConfirmation, 1: bool}
     */
    public function confirmIntent(User $user, string $shopId, string $intentId, array $data): array
    {
        return MarketplaceReferences::locked(self::LOCK, fn () => DB::transaction(function () use ($user, $shopId, $intentId, $data) {
            $shop = $this->shops->memberShop($user, $shopId, lock: true);
            $this->authorize($user, $shop);
            $probe = MarketplacePurchaseIntent::where('shop_id', $shop->id)->findOrFail($intentId);
            $listing = MarketplaceListing::withTrashed()->lockForUpdate()->findOrFail($probe->listing_id);
            $intent = MarketplacePurchaseIntent::lockForUpdate()->findOrFail($intentId);

            $this->assertOpenForBusiness($shop, $listing);
            if ($intent->converted_at !== null) {
                throw new ApiHttpException(409, 'intent_already_converted', 'This purchase request already became a deal. The buyer must record interest again before it can be confirmed once more.');
            }
            $this->assertIntentCurrent($intent, $listing);
            $quantity = Decimal::trim((string) $intent->quantity);
            $this->assertSupportable($listing, $quantity);
            $method = $this->resolveMethod($listing, $data['fulfilment_method'] ?? null);
            $charge = $this->checkedDeliveryCharge($listing, $method, $data['delivery_charge'] ?? null);

            $open = MarketplaceDealConfirmation::where('intent_id', $intent->id)->where('status', ConfirmationStatus::AwaitingBuyer->value)->lockForUpdate()->first();
            if ($open && $open->isDue()) {
                $this->confirmations->settle($open, ConfirmationStatus::Lapsed, 'system', null);
                $open = null;
            }
            if ($open) {
                $same = $open->fulfilment_method === $method && ($open->delivery_charge_amount === null ? null : $this->rules->money((string) $open->delivery_charge_amount)) === $charge;
                if ($same) {
                    return [$open->load($this->confirmationRelations()), false];
                }
                throw new ApiHttpException(409, 'confirmation_pending', 'You already confirmed this request on different terms. Withdraw that confirmation first.', details: ['confirmation_id' => $open->id, 'reference' => $open->reference]);
            }

            $price = $this->rules->money((string) $listing->unit_price);
            $c = new MarketplaceDealConfirmation([
                'intent_id' => $intent->id, 'listing_id' => $listing->id, 'shop_id' => $shop->id, 'buyer_id' => $intent->buyer_id, 'quantity' => $quantity, 'unit_price' => $price,
                'total_amount' => $this->rules->total($price, $quantity)['total'], 'currency' => $listing->currency, 'fulfilment_method' => $method, 'delivery_charge_amount' => $charge,
                'listing_title' => $listing->title, 'listing_version' => $listing->version, 'unit_id' => $listing->unit_id, 'unit_code' => $listing->unit->code, 'product_kind' => $listing->product_kind,
                'species_id' => $listing->species_id, 'crop_type_id' => $listing->crop_type_id, 'product_name' => $this->offerLifecycle->productName($listing),
                'expires_at' => now()->addHours($this->confirmationHours()),
            ]);
            $c->forceFill(['reference' => MarketplaceReferences::next('CNF', MarketplaceDealConfirmation::class), 'confirmed_by' => $user->id, 'status' => ConfirmationStatus::AwaitingBuyer, 'open_slot' => 'O'])->save();
            $this->audit->record($shop->farm_id, $user->id, 'marketplace.deal_confirmation_created', 'marketplace_deal_confirmation', $c->id, $c->reference,
                ['intent_id' => $intent->id, 'listing_id' => $listing->id, 'quantity' => $quantity, 'unit_price' => $price, 'fulfilment_method' => $method]);

            return [$c->load($this->confirmationRelations()), true];
        }));
    }

    /** The seller takes back a confirmation the buyer has not answered. Idempotent. Needs `deal.respond`. */
    public function withdraw(User $user, string $shopId, string $confirmationId): MarketplaceDealConfirmation
    {
        return DB::transaction(function () use ($user, $shopId, $confirmationId) {
            $shop = $this->shops->memberShop($user, $shopId, lock: true);
            $this->authorize($user, $shop);
            $c = MarketplaceDealConfirmation::where('shop_id', $shop->id)->lockForUpdate()->findOrFail($confirmationId);

            if ($c->status === ConfirmationStatus::Withdrawn) {
                return $c->load($this->confirmationRelations());
            }
            if ($c->status !== ConfirmationStatus::AwaitingBuyer || $c->isDue()) {
                throw new ApiHttpException(409, 'confirmation_not_open', 'This confirmation can no longer be withdrawn.', details: ['status' => $c->effectiveStatus()->value]);
            }
            $this->confirmations->settle($c, ConfirmationStatus::Withdrawn, 'seller', $user->id);

            return $c->load($this->confirmationRelations());
        });
    }

    /**
     * The buyer confirms the exact terms the seller confirmed. This is the moment the deal exists and contact becomes available. Idempotent. `[$deal, $created]`.
     *
     * @param  array{contact_phone?: ?string}  $data
     * @return array{0: MarketplaceDeal, 1: bool}
     */
    public function acceptConfirmation(User $buyer, string $confirmationId, array $data): array
    {
        $failure = null;
        // A plain closure, not an arrow function: `$failure` must be shared BY REFERENCE with the caller so a failure that persisted something (a lapse or
        // a void) is raised after the transaction commits.
        $result = MarketplaceReferences::locked(self::LOCK, function () use ($buyer, $confirmationId, $data, &$failure) {
            return DB::transaction(function () use ($buyer, $confirmationId, $data, &$failure) {
                $probe = MarketplaceDealConfirmation::where('buyer_id', $buyer->id)->findOrFail($confirmationId);
                $listing = MarketplaceListing::withTrashed()->lockForUpdate()->findOrFail($probe->listing_id);
                $intent = MarketplacePurchaseIntent::lockForUpdate()->findOrFail($probe->intent_id);
                $c = MarketplaceDealConfirmation::lockForUpdate()->findOrFail($probe->id);

                if ($c->status === ConfirmationStatus::Converted) {
                    return [$this->loaded(MarketplaceDeal::where('confirmation_id', $c->id)->firstOrFail()), false];
                }
                if ($c->isDue()) {
                    $this->confirmations->settle($c, ConfirmationStatus::Lapsed, 'system', null);
                }
                $failure = match ($c->status) {
                    ConfirmationStatus::Lapsed => new ApiHttpException(409, 'confirmation_lapsed', 'The seller\'s confirmation has expired. Ask the seller to confirm again.', details: ['expired_at' => $c->expires_at->toIso8601String()]),
                    ConfirmationStatus::Withdrawn => new ApiHttpException(409, 'confirmation_withdrawn', 'The seller withdrew this confirmation.'),
                    ConfirmationStatus::Voided => new ApiHttpException(409, 'confirmation_voided', 'The request or the listing changed after the seller confirmed, so these terms no longer hold.', details: ['reason' => $c->void_reason]),
                    default => null,
                };
                if ($failure) {
                    return [null, false];
                }

                $shop = MarketplaceShop::findOrFail($c->shop_id);
                $failure = $this->availabilityFailure($shop, $listing);
                if ($failure === null && ! $this->confirmationStillHolds($c, $listing)) {
                    $this->confirmations->settle($c, ConfirmationStatus::Voided, 'system', null, MarketplaceConfirmationLifecycle::VOID_LISTING_CHANGED);   // persists: raised after commit
                    $failure = new ApiHttpException(409, 'confirmation_stale', 'The listing changed after the seller confirmed, so these terms no longer hold.', details: ['reason' => MarketplaceConfirmationLifecycle::VOID_LISTING_CHANGED]);
                }
                if ($failure === null) {
                    $failure = $this->supportFailure($listing, Decimal::trim((string) $c->quantity));
                }
                if ($failure === null && ! $this->sellerReachable($shop)) {
                    $failure = new ApiHttpException(409, 'seller_contact_unavailable', 'The seller has no contact channel configured, so a deal cannot be formed right now.');
                }
                if ($failure) {
                    return [null, false];
                }

                $deal = $this->persist($buyer, $listing, $c->fulfilment_method, $data, [
                    'terms_source' => 'confirmed_intent', 'confirmation_id' => $c->id, 'intent_id' => $c->intent_id, 'listing_title' => $c->listing_title, 'listing_version' => $c->listing_version,
                    'product_kind' => $c->product_kind, 'species_id' => $c->species_id, 'crop_type_id' => $c->crop_type_id, 'product_name' => $c->product_name,
                    'unit_id' => $c->unit_id, 'unit_code' => $c->unit_code, 'quantity' => Decimal::trim((string) $c->quantity), 'unit_price' => $this->rules->money((string) $c->unit_price),
                    'product_total' => $this->rules->money((string) $c->total_amount), 'listed_unit_price' => $this->rules->money((string) $c->unit_price), 'currency' => $c->currency,
                    'delivery_charge_amount' => $c->delivery_charge_amount === null ? null : $this->rules->money((string) $c->delivery_charge_amount), 'seller_confirmed_by' => $c->confirmed_by,
                ]);
                $c->forceFill(['status' => ConfirmationStatus::Converted, 'open_slot' => null, 'settled_at' => now()])->save();
                $intent->forceFill(['converted_at' => now()])->save();
                $this->audit->record($shop->farm_id, $buyer->id, 'marketplace.deal_confirmation_converted', 'marketplace_deal_confirmation', $c->id, $c->reference, ['deal_id' => $deal->id]);

                return [$deal, true];
            });
        });
        if ($failure) {
            throw $failure;
        }

        return $result;
    }

    // ------------------------------------------------------------------ sweep

    /** Persists every lapsed, unanswered seller confirmation as `lapsed`. Safe to run at any time and any number of times. @return int confirmations lapsed */
    public function expireDueConfirmations(): int
    {
        $n = 0;
        MarketplaceDealConfirmation::where('status', ConfirmationStatus::AwaitingBuyer->value)->where('expires_at', '<=', now())->pluck('id')->each(function (string $id) use (&$n) {
            $n += DB::transaction(function () use ($id) {
                $c = MarketplaceDealConfirmation::lockForUpdate()->find($id);

                return $c && $c->isDue() && $this->confirmations->settle($c, ConfirmationStatus::Lapsed, 'system', null) ? 1 : 0;
            });
        });

        return $n;
    }

    // ------------------------------------------------------------------ internals

    /** @return list<string> */
    public function relations(): array
    {
        return ['listing', 'shop', 'buyer', 'offer', 'confirmation', 'events', 'reports'];
    }

    /** @return list<string> */
    public function confirmationRelations(): array
    {
        return ['listing', 'shop', 'buyer', 'deal'];
    }

    private function loaded(MarketplaceDeal $deal): MarketplaceDeal
    {
        return $deal->load($this->relations());
    }

    /**
     * @param  array<string, mixed>  $data  request input (contact_phone)
     * @param  array<string, mixed>  $terms  the frozen commercial terms and the source reference
     */
    private function persist(User $buyer, MarketplaceListing $listing, string $method, array $data, array $terms): MarketplaceDeal
    {
        $delivery = $method === 'seller_delivery';
        $mode = $delivery ? ($listing->delivery_charge ?: 'agreed_separately') : 'not_applicable';
        $phone = isset($data['contact_phone']) && trim((string) $data['contact_phone']) !== '' ? trim((string) $data['contact_phone']) : null;
        if ($mode !== 'agreed_separately') {
            $terms['delivery_charge_amount'] = null;   // included in the unit price, or no delivery: there is no separate charge to record
        }

        $deal = new MarketplaceDeal($terms + [
            'shop_id' => $listing->shop_id, 'listing_id' => $listing->id, 'buyer_id' => $buyer->id, 'fulfilment_method' => $method, 'listing_fulfilment' => $listing->fulfilment,
            'pickup_area' => $delivery ? null : $listing->pickup_area, 'delivery_coverage' => $delivery ? ($listing->delivery_coverage ?: null) : null,
            'dispatch_estimate' => $delivery ? $listing->dispatch_estimate : null, 'delivery_charge_mode' => $mode, 'buyer_contact_phone' => $phone,
        ]);
        $deal->forceFill(['reference' => MarketplaceReferences::next('DEL', MarketplaceDeal::class), 'status' => DealStatus::Accepted])->save();
        MarketplaceDealEvent::create(['deal_id' => $deal->id, 'actor_kind' => 'buyer', 'actor_id' => $buyer->id, 'action' => 'created', 'from_status' => null, 'to_status' => 'accepted', 'code' => $deal->terms_source]);
        $this->audit->record($listing->shop->farm_id, $buyer->id, 'marketplace.deal_created', 'marketplace_deal', $deal->id, $deal->reference,
            ['listing_id' => $listing->id, 'terms_source' => $deal->terms_source, 'quantity' => $deal->quantity, 'unit_price' => $deal->unit_price, 'fulfilment_method' => $method]);

        return $this->loaded($deal);
    }

    private function authorize(User $user, MarketplaceShop $shop): void
    {
        if (! $user->can('respondToDeals', $shop)) {
            throw new AuthorizationException;
        }
    }

    private function availabilityFailure(MarketplaceShop $shop, MarketplaceListing $listing): ?ApiHttpException
    {
        return match (true) {
            $shop->status !== ShopStatus::Active => new ApiHttpException(409, 'shop_not_active', 'The shop is not active right now.', details: ['shop_status' => $shop->status->value]),
            $listing->trashed() || $listing->status !== ListingStatus::Published => new ApiHttpException(409, 'listing_unavailable', 'The listing is not live right now. Try again once it is published.', details: ['listing_status' => $listing->trashed() ? 'deleted' : $listing->status->value]),
            default => null,
        };
    }

    private function assertOpenForBusiness(MarketplaceShop $shop, MarketplaceListing $listing): void
    {
        if ($failure = $this->availabilityFailure($shop, $listing)) {
            throw $failure;
        }
    }

    /** The quantity must still fit what the seller DECLARES available. Nothing is reserved; this is a check, not a promise. */
    private function supportFailure(MarketplaceListing $listing, string $quantity): ?ApiHttpException
    {
        $available = Decimal::trim((string) $listing->available_quantity);
        $min = $listing->min_order_quantity === null ? null : Decimal::trim((string) $listing->min_order_quantity);
        if (Decimal::cmp($quantity, $available) > 0 || ($min !== null && Decimal::cmp($quantity, $min) < 0)) {
            return new ApiHttpException(409, 'quantity_unavailable', 'The quantity no longer fits what the seller has declared as available. Nothing is reserved; ask the seller.',
                details: ['quantity' => $quantity, 'declared_available' => $available, 'min_order_quantity' => $min, 'basis' => 'seller_declared']);
        }

        return null;
    }

    private function assertSupportable(MarketplaceListing $listing, string $quantity): void
    {
        if ($failure = $this->supportFailure($listing, $quantity)) {
            throw $failure;
        }
    }

    /** Does the listing still sell the SAME product in the SAME unit? (An accepted offer keeps its price, so the price is deliberately not compared.) */
    private function assertSameProduct(string $unitId, string $kind, ?string $speciesId, ?string $cropId, string $productName, MarketplaceListing $listing): void
    {
        if ($listing->unit_id !== $unitId || $listing->product_kind !== $kind || $listing->species_id !== $speciesId || $listing->crop_type_id !== $cropId
            || $this->offerLifecycle->productName($listing) !== $productName) {
            throw new ApiHttpException(409, 'deal_terms_stale', 'The listing now sells a different product or unit than the one agreed, so a deal cannot be formed from it.');
        }
    }

    /** A fixed-price intent is stale when the listed price, unit or product moved since the buyer recorded interest. */
    private function assertIntentCurrent(MarketplacePurchaseIntent $intent, MarketplaceListing $listing): void
    {
        $current = $listing->unit_id === $intent->unit_id
            && Decimal::cmp(Decimal::trim((string) $listing->unit_price), Decimal::trim((string) $intent->listed_unit_price)) === 0
            && $this->offerLifecycle->productName($listing) === $intent->product_name;
        if (! $current) {
            throw new ApiHttpException(409, 'intent_stale', 'The listed price, unit or product changed since the buyer recorded interest. The buyer must proceed again at the current listing.');
        }
    }

    private function confirmationStillHolds(MarketplaceDealConfirmation $c, MarketplaceListing $listing): bool
    {
        $offered = match ($listing->fulfilment) {
            'pickup' => ['pickup'], 'seller_delivery' => ['seller_delivery'], default => ['pickup', 'seller_delivery'],
        };

        return $listing->unit_id === $c->unit_id && $listing->product_kind === $c->product_kind && $listing->species_id === $c->species_id && $listing->crop_type_id === $c->crop_type_id
            && Decimal::cmp(Decimal::trim((string) $listing->unit_price), Decimal::trim((string) $c->unit_price)) === 0
            && $this->offerLifecycle->productName($listing) === $c->product_name
            && in_array($c->fulfilment_method, $offered, true)
            && ($c->delivery_charge_amount === null || ($listing->delivery_charge ?: 'agreed_separately') === 'agreed_separately');   // a charge the seller proposed needs a listing that still allows one
    }

    /** The method the listing's `fulfilment` allows. A listing offering both makes the deciding party choose. 422 on `fulfilment_method`. */
    private function resolveMethod(MarketplaceListing $listing, mixed $requested): string
    {
        $allowed = match ($listing->fulfilment) {
            'pickup' => ['pickup'], 'seller_delivery' => ['seller_delivery'], default => ['pickup', 'seller_delivery'],
        };
        if ($requested === null || $requested === '') {
            if (count($allowed) === 1) {
                return $allowed[0];
            }
            throw ValidationException::withMessages(['fulfilment_method' => 'This listing offers both pickup and seller delivery. Choose one.']);
        }
        if (! in_array($requested, $allowed, true)) {
            throw ValidationException::withMessages(['fulfilment_method' => 'This listing does not offer that fulfilment method. Available: '.implode(', ', $allowed).'.']);
        }

        return (string) $requested;
    }

    /** A seller-proposed delivery charge: only for delivery on a listing whose charge is "agreed separately"; kept apart from the product total. Null = to be agreed directly. */
    private function checkedDeliveryCharge(MarketplaceListing $listing, string $method, mixed $amount): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }
        if ($method !== 'seller_delivery') {
            throw ValidationException::withMessages(['delivery_charge' => 'A delivery charge applies only to seller delivery.']);
        }
        if (($listing->delivery_charge ?: 'agreed_separately') !== 'agreed_separately') {
            throw ValidationException::withMessages(['delivery_charge' => 'Delivery is included in the listed price, so no separate charge can be added.']);
        }
        $money = $this->rules->money((string) $amount);
        if (bccomp($money, MarketplaceListingRules::MAX_PRICE, 2) > 0) {
            throw ValidationException::withMessages(['delivery_charge' => 'The delivery charge is too large.']);
        }

        return $money;
    }

    private function sellerReachable(MarketplaceShop $shop): bool
    {
        return $shop->contact_phone || $shop->contact_whatsapp || $shop->contact_email;
    }

    private function assertSellerReachable(MarketplaceShop $shop): void
    {
        if (! $this->sellerReachable($shop)) {
            throw new ApiHttpException(409, 'seller_contact_unavailable', 'The seller has no contact channel configured, so a deal cannot be formed right now.');
        }
    }
}
