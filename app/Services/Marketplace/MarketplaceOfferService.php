<?php

namespace App\Services\Marketplace;

use App\Enums\DealStatus;
use App\Enums\ListingStatus;
use App\Enums\OfferStatus;
use App\Enums\ShopStatus;
use App\Models\FarmMembership;
use App\Models\MarketplaceDeal;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOffer;
use App\Models\MarketplaceOfferEvent;
use App\Models\MarketplacePurchaseIntent;
use App\Models\MarketplaceShop;
use App\Models\MarketplaceShopMember;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Platform\PlatformConfigService;
use App\Support\Api\ApiHttpException;
use App\Support\Measurement\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Controlled negotiation on negotiable listings. A buyer proposes a unit price and quantity; the shop's owner or manager accepts or rejects. This is NOT
 * a chat, an escrow or a checkout: nothing is reserved, charged, deducted or sold, and no contact detail changes hands (Phase 25 owns deals and contact
 * exchange). An accepted offer is a terminal, immutable record of the terms agreed in principle.
 *
 * Concurrency. Buyer writes lock the LISTING row; seller writes lock shop, then listing, then offer (the order listing edits already use), and the
 * expiry sweep locks a single offer row, so no two paths can wait on each other in a cycle. Beyond the locks, the database itself refuses a second
 * pending offer by one buyer on one listing (`pending_slot` unique key) and a repeated attempt number.
 */
class MarketplaceOfferService
{
    public const DEFAULT_MAX_ATTEMPTS = 3;

    public const DEFAULT_EXPIRY_HOURS = 48;

    public const DEFAULT_MIN_PERCENT = '70';

    public function __construct(
        private MarketplaceShopService $shops,
        private MarketplaceListingRules $rules,
        private MarketplaceOfferLifecycle $lifecycle,
        private MarketplaceConfirmationLifecycle $confirmations,
        private PlatformConfigService $config,
        private AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------ settings

    public function maxAttempts(): int
    {
        return (int) ($this->config->setting('marketplace_max_offers_per_buyer') ?? self::DEFAULT_MAX_ATTEMPTS);
    }

    public function expiryHours(): int
    {
        return (int) ($this->config->setting('marketplace_offer_expiry_hours') ?? self::DEFAULT_EXPIRY_HOURS);
    }

    /** Percentage as a decimal string, e.g. "70" or "72.5". */
    public function minPercent(): string
    {
        $v = $this->config->setting('marketplace_min_offer_percent');

        return $v === null ? self::DEFAULT_MIN_PERCENT : Decimal::trim(bcadd((string) $v, '0', 2));
    }

    /** Lowest acceptable unit price for a listed price: listed x percent / 100, rounded UP to the kobo. Exact decimals. */
    public function minimumUnitPrice(string $listedUnitPrice, string $percent): string
    {
        $raw = bcdiv(bcmul($listedUnitPrice, $percent, 6), '100', 6);

        $down = bcadd($raw, '0', 2);

        return bccomp($raw, $down, 6) === 0 ? $down : bcadd($down, '0.01', 2);
    }

    // ------------------------------------------------------------------ buyer: submit

    /** @param  array{quantity: string, unit_price: string}  $data */
    public function submit(User $buyer, string $slug, array $data): MarketplaceOffer
    {
        $lock = 'marketplace_offer_create';
        DB::select('SELECT GET_LOCK(?, 10)', [$lock]);
        try {
            return DB::transaction(function () use ($buyer, $slug, $data) {
                $listing = $this->lockedLiveListing($slug);
                $this->assertMayEngage($buyer, $listing);
                if (! $listing->negotiable) {
                    throw new ApiHttpException(409, 'listing_not_negotiable', 'This listing is sold at its listed price. Use "Proceed at listed price" instead.');
                }
                // Everything that can be rejected for a reason of its own is checked BEFORE an attempt is used.
                $quantity = $this->rules->orderableQuantity($listing, $data['quantity']);
                $price = $this->checkedOfferPrice($listing, $data['unit_price']);

                $own = MarketplaceOffer::where('listing_id', $listing->id)->where('buyer_id', $buyer->id)->lockForUpdate()->get();
                $this->lifecycle->expireDue($own->filter(fn ($o) => $o->status === OfferStatus::Pending));   // expiry is enforced on write: a lapsed offer frees the slot

                if ($accepted = $own->first(fn ($o) => $o->status === OfferStatus::Accepted)) {
                    throw new ApiHttpException(409, 'offer_already_accepted', 'The seller already accepted an offer from you on this listing.', details: ['offer_id' => $accepted->id, 'reference' => $accepted->reference]);
                }
                if ($pending = $own->first(fn ($o) => $o->status === OfferStatus::Pending)) {
                    throw new ApiHttpException(409, 'offer_pending', 'You already have an open offer on this listing. Wait for the seller to respond or for it to expire.', details: ['offer_id' => $pending->id, 'reference' => $pending->reference, 'expires_at' => $pending->expires_at->toIso8601String()]);
                }
                $max = $this->maxAttempts();
                $used = $own->filter(fn (MarketplaceOffer $o) => $o->status !== OfferStatus::Voided)->count();   // voided offers are refunded
                if ($used >= $max) {
                    throw new ApiHttpException(409, 'offer_limit_reached', 'You have used all your offers on this listing.', details: ['max_attempts' => $max, 'attempts_used' => $used]);
                }

                $offer = new MarketplaceOffer([
                    'listing_id' => $listing->id, 'shop_id' => $listing->shop_id, 'buyer_id' => $buyer->id, 'quantity' => $quantity, 'unit_price' => $price,
                    'total_amount' => $this->rules->total($price, $quantity)['total'], 'currency' => $listing->currency,
                    'listing_title' => $listing->title, 'listing_version' => $listing->version, 'listed_unit_price' => $this->rules->money((string) $listing->unit_price),
                    'unit_id' => $listing->unit_id, 'unit_code' => $listing->unit->code, 'product_kind' => $listing->product_kind, 'species_id' => $listing->species_id,
                    'crop_type_id' => $listing->crop_type_id, 'product_name' => $this->lifecycle->productName($listing),
                    'listing_available_quantity' => $listing->available_quantity, 'listing_min_order_quantity' => $listing->min_order_quantity,
                    'expires_at' => now()->addHours($this->expiryHours()),
                ]);
                $offer->forceFill([
                    'reference' => $this->nextReference('OFR', MarketplaceOffer::class), 'attempt_no' => ((int) $own->max('attempt_no')) + 1,
                    'status' => OfferStatus::Pending, 'pending_slot' => 'P',
                ])->save();
                MarketplaceOfferEvent::create(['offer_id' => $offer->id, 'actor_kind' => 'buyer', 'actor_id' => $buyer->id, 'action' => 'submitted', 'from_status' => null, 'to_status' => 'pending']);
                $this->audit->record(null, $buyer->id, 'marketplace.offer_submitted', 'marketplace_offer', $offer->id, $offer->reference,
                    ['listing_id' => $listing->id, 'quantity' => $quantity, 'unit_price' => $price, 'listed_unit_price' => $offer->listed_unit_price]);

                return $offer->load($this->relations());
            });
        } finally {
            DB::select('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    // ------------------------------------------------------------------ buyer: purchase intent

    /**
     * "Proceed at listed price". Records interest only and is idempotent per buyer and listing: repeating it with the same quantity returns the same
     * record, a different quantity refreshes it. `[$intent, $created]`.
     *
     * @return array{0: MarketplacePurchaseIntent, 1: bool}
     */
    public function recordIntent(User $buyer, string $slug, mixed $quantity): array
    {
        $lock = 'marketplace_intent_create';
        DB::select('SELECT GET_LOCK(?, 10)', [$lock]);
        try {
            return DB::transaction(function () use ($buyer, $slug, $quantity) {
                $listing = $this->lockedLiveListing($slug);
                $this->assertMayEngage($buyer, $listing);
                $qty = $this->rules->orderableQuantity($listing, $quantity);
                $price = $this->rules->money((string) $listing->unit_price);
                $facts = [
                    'quantity' => $qty, 'listed_unit_price' => $price, 'total_amount' => $this->rules->total($price, $qty)['total'], 'currency' => $listing->currency,
                    'unit_id' => $listing->unit_id, 'unit_code' => $listing->unit->code, 'listing_title' => $listing->title, 'listing_version' => $listing->version,
                    'product_name' => $this->lifecycle->productName($listing),
                ];

                $intent = MarketplacePurchaseIntent::where('listing_id', $listing->id)->where('buyer_id', $buyer->id)->lockForUpdate()->first();
                if ($intent === null) {
                    $intent = new MarketplacePurchaseIntent($facts);
                    $intent->forceFill(['reference' => $this->nextReference('PIN', MarketplacePurchaseIntent::class), 'listing_id' => $listing->id, 'shop_id' => $listing->shop_id, 'buyer_id' => $buyer->id])->save();
                    $created = true;
                } else {
                    $intent->fill($facts);
                    $created = false;
                    // A used-up intent (its deal is over, cancelled or completed) is re-armed by the buyer proceeding again; one whose deal is still ACTIVE is not,
                    // so the same request can never become a second deal.
                    if ($intent->converted_at !== null && ! MarketplaceDeal::where('intent_id', $intent->id)->where('status', DealStatus::Accepted->value)->exists()) {
                        $intent->converted_at = null;
                    }
                    if (! $intent->isDirty()) {
                        return [$intent->load($this->intentRelations()), false];   // same interest at the same terms: nothing to record
                    }
                    $this->confirmations->voidOpenFor($intent);   // whatever the seller confirmed no longer matches the buyer's request
                    $intent->save();
                }
                $this->audit->record(null, $buyer->id, 'marketplace.purchase_intent_recorded', 'marketplace_purchase_intent', $intent->id, $intent->reference,
                    ['listing_id' => $listing->id, 'quantity' => $qty, 'listed_unit_price' => $price, 'refreshed' => ! $created]);

                return [$intent->load($this->intentRelations()), $created];
            });
        } finally {
            DB::select('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    // ------------------------------------------------------------------ seller: respond

    /** Accept a pending offer. Idempotent on an offer this call already accepted. */
    public function accept(User $user, string $shopId, string $offerId): MarketplaceOffer
    {
        return $this->decide($user, $shopId, $offerId, OfferStatus::Accepted);
    }

    public function reject(User $user, string $shopId, string $offerId): MarketplaceOffer
    {
        return $this->decide($user, $shopId, $offerId, OfferStatus::Rejected);
    }

    private function decide(User $user, string $shopId, string $offerId, OfferStatus $decision): MarketplaceOffer
    {
        // A failure that must still persist something (a lapsed or invalidated offer) is raised AFTER the transaction commits.
        $failure = null;
        $offer = DB::transaction(function () use ($user, $shopId, $offerId, $decision, &$failure) {
            $shop = $this->shops->memberShop($user, $shopId, lock: true);
            $this->authorize($user, 'respondToOffers', $shop);
            $listingId = MarketplaceOffer::where('shop_id', $shop->id)->findOrFail($offerId)->listing_id;
            $listing = MarketplaceListing::withTrashed()->lockForUpdate()->findOrFail($listingId);
            $offer = MarketplaceOffer::where('shop_id', $shop->id)->lockForUpdate()->findOrFail($offerId);

            if ($offer->status === $decision) {   // the same decision again: already done
                return $offer->load($this->relations());
            }
            if ($offer->status === OfferStatus::Pending && $offer->isDue()) {
                $this->lifecycle->expire($offer);
            }
            $failure = match ($offer->status) {
                OfferStatus::Expired => new ApiHttpException(409, 'offer_expired', 'This offer has expired and can no longer be answered.', details: ['expired_at' => $offer->expires_at->toIso8601String()]),
                OfferStatus::Voided => new ApiHttpException(409, 'offer_voided', 'The listing changed after this offer was made, so the offer is no longer valid.', details: ['reason' => $offer->void_reason]),
                OfferStatus::Accepted, OfferStatus::Rejected => new ApiHttpException(409, 'offer_not_pending', 'This offer has already been answered.', details: ['status' => $offer->status->value]),
                default => null,
            };
            if ($failure) {
                return $offer;
            }

            if ($decision === OfferStatus::Accepted) {
                if (! $this->lifecycle->stillValid($offer, $listing)) {   // belt and braces: edits void offers as they happen, this catches anything that slipped past
                    $this->lifecycle->move($offer, OfferStatus::Voided, 'system', null, MarketplaceOfferLifecycle::VOID_LISTING_CHANGED);
                    $failure = new ApiHttpException(409, 'offer_voided', 'The listing changed after this offer was made, so the offer is no longer valid.', details: ['reason' => MarketplaceOfferLifecycle::VOID_LISTING_CHANGED]);

                    return $offer;
                }
                if ($shop->status !== ShopStatus::Active) {
                    $failure = new ApiHttpException(409, 'shop_not_active', 'The shop must be active to accept offers.', details: ['shop_status' => $shop->status->value]);

                    return $offer;
                }
                if ($listing->status !== ListingStatus::Published) {
                    $failure = new ApiHttpException(409, 'listing_unavailable', 'The listing is not live, so offers on it cannot be accepted right now.', details: ['listing_status' => $listing->status->value]);

                    return $offer;
                }
            }
            $this->lifecycle->move($offer, $decision, 'seller', $user->id);

            return $offer->load($this->relations());
        });
        if ($failure) {
            throw $failure;
        }

        return $offer;
    }

    // ------------------------------------------------------------------ sweep

    /** Persists every lapsed pending offer as `expired`. Safe to run at any time and any number of times. @return int offers expired */
    public function expireDueOffers(): int
    {
        $n = 0;
        MarketplaceOffer::where('status', OfferStatus::Pending->value)->where('expires_at', '<=', now())->pluck('id')->each(function (string $id) use (&$n) {
            $n += DB::transaction(function () use ($id) {
                $offer = MarketplaceOffer::lockForUpdate()->find($id);

                return $offer && $offer->isDue() && $this->lifecycle->expire($offer) ? 1 : 0;
            });
        });

        return $n;
    }

    // ------------------------------------------------------------------ internals

    /** @return list<string> */
    public function relations(): array
    {
        return ['listing.shop', 'shop', 'buyer', 'events', 'deal'];
    }

    /** @return list<string> */
    public function intentRelations(): array
    {
        return ['listing.shop', 'shop', 'buyer', 'confirmations.deal'];
    }

    /** Locks the listing row by public slug. A listing that is not public right now is indistinguishable from one that does not exist. */
    private function lockedLiveListing(string $slug): MarketplaceListing
    {
        $listing = MarketplaceListing::where('slug', $slug)->lockForUpdate()->first();
        $shop = $listing ? MarketplaceShop::find($listing->shop_id) : null;
        abort_unless($listing && $listing->status === ListingStatus::Published && $shop?->status === ShopStatus::Active, 404);
        $listing->setRelation('shop', $shop);
        $listing->load('unit', 'species', 'cropType');

        return $listing;
    }

    /** People who run the shop (and the owner of its linked farm) cannot negotiate with themselves. */
    private function assertMayEngage(User $buyer, MarketplaceListing $listing): void
    {
        $isMember = MarketplaceShopMember::where('shop_id', $listing->shop_id)->where('user_id', $buyer->id)->exists();
        $isFarmOwner = $listing->shop->farm_id !== null
            && FarmMembership::where('farm_id', $listing->shop->farm_id)->where('user_id', $buyer->id)->where('role', FarmMembership::ROLE_OWNER)->where('status', 'active')->exists();
        if ($isMember || $isFarmOwner) {
            throw new ApiHttpException(403, 'cannot_negotiate_own_listing', 'You cannot make an offer or purchase request on your own shop\'s listing.');
        }
    }

    /** `floor <= price < listed`, exact. Throws a 422 on `unit_price`. */
    private function checkedOfferPrice(MarketplaceListing $listing, string $offered): string
    {
        $listed = $this->rules->money((string) $listing->unit_price);
        $price = $this->rules->money($offered);
        $percent = $this->minPercent();
        if (bccomp($price, '0', 2) <= 0) {
            throw ValidationException::withMessages(['unit_price' => 'The offered price must be greater than zero.']);
        }
        if (bccomp($price, $listed, 2) >= 0) {
            throw ValidationException::withMessages(['unit_price' => "An offer must be lower than the listed price of $listed. To buy at the listed price, use \"Proceed at listed price\"."]);
        }
        // price >= listed x percent / 100  <=>  price x 100 >= listed x percent (no division, so nothing to round)
        if (bccomp(bcmul($price, '100', 6), bcmul($listed, $percent, 6), 6) < 0) {
            $min = $this->minimumUnitPrice($listed, $percent);

            throw ValidationException::withMessages(['unit_price' => "The offer is too low. The lowest accepted offer on this listing is $min per {$listing->unit->code} ($percent% of the listed price)."]);
        }

        return $price;
    }

    private function authorize(User $user, string $ability, MarketplaceShop $shop): void
    {
        if (! $user->can($ability, $shop)) {
            throw new AuthorizationException;
        }
    }

    /** @param  class-string<Model>  $model */
    private function nextReference(string $prefix, string $model): string
    {
        $year = now()->format('Y');
        $last = $model::where('reference', 'like', "$prefix-$year-%")->orderByDesc('reference')->value('reference');

        return "$prefix-$year-".str_pad((string) ($last ? ((int) substr($last, -5)) + 1 : 1), 5, '0', STR_PAD_LEFT);
    }
}
