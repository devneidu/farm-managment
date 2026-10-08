<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceListing;
use App\Models\MarketplacePromotion;
use App\Services\Platform\PlatformConfigService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Page;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * Where paid promotion touches discovery. A promotion is read from its paid window (`starts_at <= now < expires_at`) on listings the public query already
 * admits (published, shop active) - so an expired promotion, a suspended shop or a restricted listing earns nothing without any job running.
 *
 *  - Ranking: on the default (`newest`) and `relevance` sorts, up to `marketplace_max_promoted_per_page` promoted listings that match the buyer's filters are
 *    placed first on page 1; the rest of that page and every later page are the ordinary organic order, so organic listings are never hidden or dropped.
 *    Which promoted listings take the places rotates daily (a hash of the id and the date), so equal payers are treated alike. Price sorts stay pure.
 *  - Disclosure: every listing inside a paid window carries `promotion.label = "Sponsored"` whatever the sort.
 */
class MarketplacePromotionRanking
{
    public const DEFAULT_CAP = 3;

    public function __construct(private PlatformConfigService $config) {}

    public function cap(): int
    {
        return (int) ($this->config->setting('marketplace_max_promoted_per_page') ?? self::DEFAULT_CAP);
    }

    /**
     * @param  Builder<MarketplaceListing>  $base  filtered, UNordered, public query
     * @param  callable(Builder<MarketplaceListing>): void  $organicOrder  applies the normal ordering
     */
    public function page(Builder $base, callable $organicOrder, bool $boost, int $perPage): LengthAwarePaginator
    {
        $cap = min($this->cap(), $perPage);
        $boosted = collect();
        if ($boost && $cap > 0) {
            $ids = (clone $base)->whereIn($base->qualifyColumn('id'), MarketplacePromotion::running()->select('listing_id'))
                ->orderByRaw('MD5(CONCAT('.$base->qualifyColumn('id').', ?))', [now()->format('Y-m-d')])->orderBy($base->qualifyColumn('id'))->limit($cap)->pluck('id');
            if ($ids->isNotEmpty()) {
                $order = $ids->flip();
                $boosted = (clone $base)->whereIn($base->qualifyColumn('id'), $ids)->get()->sortBy(fn ($l) => $order[$l->id])->values();
            }
        }
        $organic = (clone $base)->when($boosted->isNotEmpty(), fn (Builder $q) => $q->whereNotIn($q->qualifyColumn('id'), $boosted->pluck('id')));
        $organicOrder($organic);

        $nb = $boosted->count();
        $total = $nb + (clone $organic)->toBase()->getCountForPagination();
        $current = Paginator::resolveCurrentPage();
        $offset = $current <= 1 ? 0 : ($perPage - $nb) + ($current - 2) * $perPage;
        $limit = $current <= 1 ? $perPage - $nb : $perPage;
        $rows = $limit > 0 ? $organic->offset($offset)->limit($limit)->get() : collect();
        $items = ($current <= 1 ? $boosted : collect())->concat($rows)->values();

        return new Page($items, $total, $perPage, $current, ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page']);
    }

    /** Marks each listing that sits inside a paid window with `sponsored`, in one query. @param  iterable<MarketplaceListing>|Collection<int, MarketplaceListing>  $listings */
    public function annotate(iterable $listings): void
    {
        $listings = collect($listings);
        if ($listings->isEmpty()) {
            return;
        }
        $live = MarketplacePromotion::running()->whereIn('listing_id', $listings->pluck('id'))->pluck('listing_id')->flip();
        foreach ($listings as $listing) {
            $listing->setAttribute('sponsored', $live->has($listing->id));
        }
    }
}
