<?php

namespace App\Services\Marketplace;

use App\Enums\OfferStatus;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOffer;
use App\Models\MarketplaceOfferEvent;
use App\Services\Audit\AuditLogger;
use App\Support\Measurement\Decimal;
use Illuminate\Support\Collection;

/**
 * The single place an offer leaves `pending`. Callers hold the locks (listing, then offer) and run inside a transaction; every move is written to the
 * append-only offer history and the audit trail, clears `pending_slot` (so the buyer may offer again) and is a no-op on an offer that is no longer pending.
 */
class MarketplaceOfferLifecycle
{
    public const VOID_LISTING_CHANGED = 'listing_changed';

    public function __construct(private AuditLogger $audit) {}

    /** Moves a PENDING offer to `$to`. Returns false (and writes nothing) when it was not pending. */
    public function move(MarketplaceOffer $offer, OfferStatus $to, string $actorKind, ?string $actorId, ?string $code = null): bool
    {
        if ($offer->status !== OfferStatus::Pending) {
            return false;
        }
        $offer->forceFill([
            'status' => $to, 'pending_slot' => null, 'void_reason' => $to === OfferStatus::Voided ? $code : null,
            'responded_at' => in_array($to, [OfferStatus::Accepted, OfferStatus::Rejected], true) ? now() : null,
            'responded_by' => $actorKind === 'seller' ? $actorId : null,
        ])->save();
        MarketplaceOfferEvent::create([
            'offer_id' => $offer->id, 'actor_kind' => $actorKind, 'actor_id' => $actorId, 'action' => $to->value,
            'from_status' => OfferStatus::Pending->value, 'to_status' => $to->value, 'code' => $code,
        ]);
        $offer->loadMissing('shop');
        $this->audit->record($offer->shop->farm_id, $actorKind === 'system' ? null : $actorId, 'marketplace.offer_'.$to->value, 'marketplace_offer', $offer->id, $offer->reference,
            array_filter(['listing_id' => $offer->listing_id, 'code' => $code]));

        return true;
    }

    public function expire(MarketplaceOffer $offer): bool
    {
        return $this->move($offer, OfferStatus::Expired, 'system', null);
    }

    /** @param  iterable<MarketplaceOffer>  $offers  pending offers (locked) */
    public function expireDue(iterable $offers): int
    {
        $n = 0;
        foreach ($offers as $offer) {
            if ($offer->isDue() && $this->expire($offer)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Is the offer's snapshot still true of the live listing? False when the unit, the listed price, the product, negotiability, the declared available
     * quantity (below the offered quantity) or the minimum order (above it) changed in a way that would make the buyer's proposal mean something else.
     */
    public function stillValid(MarketplaceOffer $offer, MarketplaceListing $listing): bool
    {
        $qty = Decimal::trim((string) $offer->quantity);

        return $listing->negotiable
            && $listing->unit_id === $offer->unit_id
            && Decimal::cmp(Decimal::trim((string) $listing->unit_price), Decimal::trim((string) $offer->listed_unit_price)) === 0
            && $listing->product_kind === $offer->product_kind
            && $listing->species_id === $offer->species_id
            && $listing->crop_type_id === $offer->crop_type_id
            && Decimal::cmp(Decimal::trim((string) $listing->available_quantity), $qty) >= 0
            && ($listing->min_order_quantity === null || Decimal::cmp(Decimal::trim((string) $listing->min_order_quantity), $qty) <= 0)
            && $this->productName($listing) === $offer->product_name;
    }

    /** Called by the listing service, under the listing lock, after a seller edit: settles the listing's open offers against the new state. */
    public function reconcile(MarketplaceListing $listing, ?string $actorId): void
    {
        $listing->loadMissing('species', 'cropType');
        /** @var Collection<int, MarketplaceOffer> $pending */
        $pending = MarketplaceOffer::where('listing_id', $listing->id)->where('status', OfferStatus::Pending->value)->lockForUpdate()->get();
        foreach ($pending as $offer) {
            if ($offer->isDue()) {
                $this->expire($offer);
            } elseif (! $this->stillValid($offer, $listing)) {
                $this->move($offer, OfferStatus::Voided, 'system', null, self::VOID_LISTING_CHANGED);
            }
        }
    }

    public function productName(MarketplaceListing $l): string
    {
        $l->loadMissing('species', 'cropType');

        return mb_substr($l->custom_product_name ?? $l->species?->name ?? $l->cropType?->name ?? MarketplaceProductCatalogue::KINDS[$l->product_kind][0], 0, 120);
    }
}
