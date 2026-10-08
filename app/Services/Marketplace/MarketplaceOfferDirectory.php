<?php

namespace App\Services\Marketplace;

use App\Enums\ListingStatus;
use App\Enums\OfferStatus;
use App\Enums\ShopStatus;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOffer;
use App\Models\MarketplacePurchaseIntent;
use App\Models\MarketplaceShop;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Page;
use Illuminate\Support\Facades\DB;

/**
 * Read side of negotiation. Pending offers past their deadline are reported as `expired` (derived from `expires_at`), so a read never shows a lapsed
 * offer as open even before the sweep has persisted it. Buyers see only their own records; sellers see their shop's, with the buyer's display name
 * and nothing else about them.
 */
class MarketplaceOfferDirectory
{
    public function __construct(private MarketplaceShopService $shops, private MarketplaceOfferService $offers, private MarketplaceListingRules $rules) {}

    // ------------------------------------------------------------------ buyer

    /** What this buyer may do on a public listing right now: attempts, the price floor, the open offer. */
    public function status(User $buyer, MarketplaceListing $listing): array
    {
        $mine = MarketplaceOffer::where('listing_id', $listing->id)->where('buyer_id', $buyer->id)->orderBy('attempt_no')->get();
        $max = $this->offers->maxAttempts();
        $used = $mine->filter(fn (MarketplaceOffer $o) => $o->status !== OfferStatus::Voided)->count();
        $pending = $mine->first(fn (MarketplaceOffer $o) => $o->effectiveStatus() === OfferStatus::Pending);
        $accepted = $mine->first(fn (MarketplaceOffer $o) => $o->status === OfferStatus::Accepted);
        $percent = $this->offers->minPercent();
        $listed = $this->rules->money((string) $listing->unit_price);
        $intent = MarketplacePurchaseIntent::where('listing_id', $listing->id)->where('buyer_id', $buyer->id)->first();

        $blocked = match (true) {
            ! $listing->negotiable => 'listing_not_negotiable',
            $accepted !== null => 'offer_already_accepted',
            $pending !== null => 'offer_pending',
            $used >= $max => 'offer_limit_reached',
            default => null,
        };

        return [
            'listing' => ['id' => $listing->id, 'slug' => $listing->slug, 'negotiable' => $listing->negotiable, 'unit_price' => $listed, 'currency' => $listing->currency],
            'rules' => [
                'max_attempts' => $max, 'attempts_used' => $used, 'attempts_remaining' => max(0, $max - $used),
                'min_offer_percent' => $percent, 'minimum_unit_price' => $this->offers->minimumUnitPrice($listed, $percent),
                'offer_expiry_hours' => $this->offers->expiryHours(),
            ],
            'can_offer' => $blocked === null,
            'blocked_reason' => $blocked,
            'pending_offer' => $pending?->only(['id', 'reference', 'expires_at']),
            'offers' => $mine->map->only(['id', 'reference', 'attempt_no'])->values()->all(),
            'purchase_intent' => $intent ? ['id' => $intent->id, 'reference' => $intent->reference, 'quantity' => $intent->quantity] : null,
        ];
    }

    /** The public listing a buyer is looking at (404 unless public). */
    public function liveListing(string $slug): MarketplaceListing
    {
        return MarketplaceListing::public()->with('unit', 'species', 'cropType', 'shop')->where('slug', $slug)->firstOrFail();
    }

    public function myOffer(User $buyer, string $offerId): MarketplaceOffer
    {
        return MarketplaceOffer::with($this->offers->relations())->where('buyer_id', $buyer->id)->findOrFail($offerId);
    }

    /**
     * The buyer's enquiry history: offers and purchase intents together, newest first.
     *
     * @param  array{kind?: string, status?: string, per_page?: int, page?: int}  $f
     */
    public function enquiries(User $buyer, array $f): LengthAwarePaginator
    {
        $kind = $f['kind'] ?? null;
        $offers = DB::table('marketplace_offers')->select('id', DB::raw("'offer' as kind"), 'created_at')->where('buyer_id', $buyer->id);
        $this->statusFilter($offers, $f['status'] ?? null, 'marketplace_offers');
        $intents = DB::table('marketplace_purchase_intents')->select('id', DB::raw("'purchase_intent' as kind"), 'created_at')->where('buyer_id', $buyer->id);

        $union = match (true) {
            $kind === 'offer' || isset($f['status']) => $offers,
            $kind === 'purchase_intent' => $intents,
            default => $offers->unionAll($intents),
        };
        $page = DB::query()->fromSub($union, 'e')->orderByDesc('created_at')->orderByDesc('id')->paginate($f['per_page'] ?? 20);

        $ids = $page->getCollection()->groupBy('kind');
        $offerModels = MarketplaceOffer::with($this->offers->relations())->whereIn('id', $ids->get('offer', collect())->pluck('id'))->get()->keyBy('id');
        $intentModels = MarketplacePurchaseIntent::with($this->offers->intentRelations())->whereIn('id', $ids->get('purchase_intent', collect())->pluck('id'))->get()->keyBy('id');

        return new Page(
            $page->getCollection()->map(fn ($row) => $row->kind === 'offer' ? $offerModels->get($row->id) : $intentModels->get($row->id))->filter()->values(),
            $page->total(), $page->perPage(), $page->currentPage(),
        );
    }

    // ------------------------------------------------------------------ seller

    /** @param  array{status?: string, listing?: string, per_page?: int}  $f */
    public function shopOffers(User $user, string $shopId, array $f): LengthAwarePaginator
    {
        $shop = $this->viewable($user, $shopId);
        $query = MarketplaceOffer::with($this->offers->relations())->where('shop_id', $shop->id)
            ->when(isset($f['listing']), fn (Builder $q) => $q->where('listing_id', $f['listing']));
        $this->statusFilter($query, $f['status'] ?? null, 'marketplace_offers');

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($f['per_page'] ?? 20);
    }

    public function shopOffer(User $user, string $shopId, string $offerId): MarketplaceOffer
    {
        $shop = $this->viewable($user, $shopId);

        return MarketplaceOffer::with($this->offers->relations())->where('shop_id', $shop->id)->findOrFail($offerId);
    }

    /** @param  array{listing?: string, per_page?: int}  $f */
    public function shopIntents(User $user, string $shopId, array $f): LengthAwarePaginator
    {
        $shop = $this->viewable($user, $shopId);

        return MarketplacePurchaseIntent::with($this->offers->intentRelations())->where('shop_id', $shop->id)
            ->when(isset($f['listing']), fn (Builder $q) => $q->where('listing_id', $f['listing']))
            ->orderByDesc('updated_at')->orderByDesc('id')->paginate($f['per_page'] ?? 20);
    }

    // ------------------------------------------------------------------ internals

    private function viewable(User $user, string $shopId): MarketplaceShop
    {
        $shop = $this->shops->memberShop($user, $shopId);
        if (! $user->can('viewOffers', $shop)) {
            throw new AuthorizationException;
        }

        return $shop;
    }

    /** `expired` includes pending offers past their deadline; `pending` excludes them. */
    private function statusFilter(Builder|\Illuminate\Database\Query\Builder $q, ?string $status, string $table): void
    {
        match ($status) {
            null => null,
            'pending' => $q->where("$table.status", 'pending')->where("$table.expires_at", '>', now()),
            'expired' => $q->where(fn ($w) => $w->where("$table.status", 'expired')->orWhere(fn ($p) => $p->where("$table.status", 'pending')->where("$table.expires_at", '<=', now()))),
            default => $q->where("$table.status", $status),
        };
    }

    /** Is the listing currently open to new business (for a seller deciding whether Accept will work)? */
    public static function listingOpen(MarketplaceListing $listing, MarketplaceShop $shop): bool
    {
        return $listing->status === ListingStatus::Published && $shop->status === ShopStatus::Active;
    }
}
