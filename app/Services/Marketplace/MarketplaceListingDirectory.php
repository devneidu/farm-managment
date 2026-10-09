<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceCatalogImage;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceListingImage;
use App\Services\Platform\PlatformPlanService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The PUBLIC read side of listings. Everything goes through MarketplaceListing::public(): a draft, paused, archived, restricted or deleted listing,
 * and every listing of a shop that is not active, is indistinguishable from one that does not exist.
 */
class MarketplaceListingDirectory
{
    public function __construct(private MarketplaceImageService $images, private MarketplacePromotionRanking $promotions) {}

    private const WITH = ['unit', 'packageBasisUnit', 'species', 'cropType', 'catalogImage', 'images', 'shop'];

    /**
     * @param  array{q?: string, product_kind?: string, species?: string, crop_type?: string, state?: string, city?: string, min_price?: string, max_price?: string,
     *     unit?: string, negotiable?: bool, fulfilment?: string, delivers_to?: string, shop?: string, verified?: bool, sort?: string, per_page?: int}  $f
     */
    public function list(array $f): LengthAwarePaginator
    {
        $like = isset($f['q']) ? '%'.PlatformPlanService::escapeLike($f['q']).'%' : null;
        $sort = $f['sort'] ?? 'newest';

        $query = MarketplaceListing::public()->with(self::WITH)
            ->when($like, fn (Builder $q) => $q->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('description', 'like', $like)->orWhere('custom_product_name', 'like', $like)
                ->orWhereHas('species', fn ($s) => $s->where('name', 'like', $like))->orWhereHas('cropType', fn ($c) => $c->where('name', 'like', $like))))
            ->when(isset($f['product_kind']), fn (Builder $q) => $q->where('product_kind', $f['product_kind']))
            ->when(isset($f['species']), fn (Builder $q) => $q->whereHas('species', fn ($s) => $s->where('code', $f['species'])))
            ->when(isset($f['crop_type']), fn (Builder $q) => $q->whereHas('cropType', fn ($c) => $c->where('code', $f['crop_type'])))
            ->when(isset($f['state']), fn (Builder $q) => $q->where('state', $f['state']))
            ->when(isset($f['city']), fn (Builder $q) => $q->where('city', $f['city']))
            ->when(isset($f['min_price']), fn (Builder $q) => $q->where('unit_price', '>=', $f['min_price']))
            ->when(isset($f['max_price']), fn (Builder $q) => $q->where('unit_price', '<=', $f['max_price']))
            ->when(isset($f['unit']), fn (Builder $q) => $q->whereHas('unit', fn ($u) => $u->where('code', $f['unit'])))
            ->when(isset($f['negotiable']), fn (Builder $q) => $q->where('negotiable', $f['negotiable']))
            // "pickup" / "seller_delivery" mean "offers it": a listing that does both appears under either.
            ->when(isset($f['fulfilment']), fn (Builder $q) => $q->whereIn('fulfilment', [$f['fulfilment'], 'both']))
            ->when(isset($f['delivers_to']), fn (Builder $q) => $q->whereIn('fulfilment', ['seller_delivery', 'both'])->whereJsonContains('delivery_coverage', $f['delivers_to']))
            ->when(isset($f['shop']), fn (Builder $q) => $q->whereHas('shop', fn ($s) => $s->where('slug', $f['shop'])))
            ->when(isset($f['verified']), fn (Builder $q) => $q->whereHas('shop', fn ($s) => $f['verified'] ? $s->where('verification_status', 'verified') : $s->where('verification_status', '!=', 'verified')));

        $order = function (Builder $q) use ($sort, $like) {
            match (true) {
                $sort === 'price_asc' => $q->orderBy('unit_price')->orderByDesc('published_at'),
                $sort === 'price_desc' => $q->orderByDesc('unit_price')->orderByDesc('published_at'),
                // Without a search term relevance has nothing to rank, so it reads as newest.
                $sort === 'relevance' && $like !== null => $q->orderByRaw('CASE WHEN title LIKE ? THEN 3 WHEN custom_product_name LIKE ? THEN 2 ELSE 1 END DESC', [$like, $like])->orderByDesc('published_at'),
                default => $q->orderByDesc('published_at'),
            };
            // UUIDv7 ids are time-ordered, so the id breaks ties newest-first and keeps pages stable.
            $q->orderByDesc('id');
        };

        // Promoted listings lead the default and relevance orders only (see MarketplacePromotionRanking); price sorts are never bent by payment.
        $page = $this->promotions->page($query, $order, ! in_array($sort, ['price_asc', 'price_desc'], true), $f['per_page'] ?? 20);
        $this->promotions->annotate($page->getCollection());

        return $page;
    }

    public function find(string $slug): MarketplaceListing
    {
        $listing = MarketplaceListing::public()->with(self::WITH)->where('slug', $slug)->firstOrFail();
        $this->promotions->annotate([$listing]);

        return $listing;
    }

    /** A stored seller photo, only while its listing is publicly visible. */
    public function publicImage(string $imageId): MarketplaceListingImage
    {
        $image = MarketplaceListingImage::query()->findOrFail($imageId);
        abort_unless(MarketplaceListing::public()->whereKey($image->listing_id)->exists(), 404);

        return $image;
    }

    public function catalogueImage(string $code): MarketplaceCatalogImage
    {
        return MarketplaceCatalogImage::available()->where('code', $code)->firstOrFail();
    }

    /** The reusable image library for sellers: only entries that have an asset. @return Collection<int, MarketplaceCatalogImage> */
    public function library(?string $productKind): Collection
    {
        return MarketplaceCatalogImage::available()->when($productKind, fn ($q) => $q->where('product_kind', $productKind))->orderBy('sort_order')->orderBy('code')->get();
    }
}
