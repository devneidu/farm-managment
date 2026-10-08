<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceDeal;
use App\Models\MarketplaceDealConfirmation;
use App\Models\MarketplaceDealReport;
use App\Models\MarketplaceShop;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read side of deals. A buyer sees only their own deals; a shop member only the deals of a shop they belong to (`deal.view`, all roles); platform admins see
 * every deal. None of these reads returns contact details: contact has its own audited endpoint (MarketplaceDealContact).
 */
class MarketplaceDealDirectory
{
    private const LIST_RELATIONS = ['listing', 'shop', 'buyer', 'offer', 'confirmation'];

    private const DETAIL_RELATIONS = ['listing', 'shop', 'buyer', 'offer', 'confirmation', 'events', 'reports'];

    public function __construct(private MarketplaceShopService $shops) {}

    // ------------------------------------------------------------------ buyer

    /** @param  array{status?: string, per_page?: int}  $f */
    public function buyerDeals(User $buyer, array $f): LengthAwarePaginator
    {
        return MarketplaceDeal::with(self::LIST_RELATIONS)->where('buyer_id', $buyer->id)
            ->when(isset($f['status']), fn (Builder $q) => $q->where('status', $f['status']))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($f['per_page'] ?? 20);
    }

    public function buyerDeal(User $buyer, string $dealId): MarketplaceDeal
    {
        return MarketplaceDeal::with(self::DETAIL_RELATIONS)->where('buyer_id', $buyer->id)->findOrFail($dealId);
    }

    public function buyerConfirmation(User $buyer, string $confirmationId): MarketplaceDealConfirmation
    {
        return MarketplaceDealConfirmation::with('listing', 'shop', 'buyer', 'deal')->where('buyer_id', $buyer->id)->findOrFail($confirmationId);
    }

    // ------------------------------------------------------------------ seller

    /** @param  array{status?: string, listing?: string, per_page?: int}  $f */
    public function shopDeals(User $user, string $shopId, array $f): LengthAwarePaginator
    {
        $shop = $this->viewable($user, $shopId);

        return MarketplaceDeal::with(self::LIST_RELATIONS)->where('shop_id', $shop->id)
            ->when(isset($f['status']), fn (Builder $q) => $q->where('status', $f['status']))
            ->when(isset($f['listing']), fn (Builder $q) => $q->where('listing_id', $f['listing']))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($f['per_page'] ?? 20);
    }

    public function shopDeal(User $user, string $shopId, string $dealId): MarketplaceDeal
    {
        $shop = $this->viewable($user, $shopId);

        return MarketplaceDeal::with(self::DETAIL_RELATIONS)->where('shop_id', $shop->id)->findOrFail($dealId);
    }

    /** Whether this member may act on deals (drives the `can` flags on a seller's response). */
    public function mayRespond(User $user, string $shopId): bool
    {
        return $user->can('respondToDeals', $this->shops->memberShop($user, $shopId));
    }

    // ------------------------------------------------------------------ platform admin

    /** @param  array{status?: string, shop_id?: string, reported?: bool|string|int, q?: string, per_page?: int}  $f */
    public function adminDeals(array $f): LengthAwarePaginator
    {
        return MarketplaceDeal::with(self::LIST_RELATIONS)->withCount('reports')
            ->when(isset($f['status']), fn (Builder $q) => $q->where('status', $f['status']))
            ->when(isset($f['shop_id']), fn (Builder $q) => $q->where('shop_id', $f['shop_id']))
            ->when(isset($f['reported']), fn (Builder $q) => filter_var($f['reported'], FILTER_VALIDATE_BOOLEAN) ? $q->whereHas('reports') : $q->whereDoesntHave('reports'))
            ->when(isset($f['q']), fn (Builder $q) => $q->where('reference', 'like', '%'.addcslashes((string) $f['q'], '%_\\').'%'))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($f['per_page'] ?? 20);
    }

    public function adminDeal(string $dealId): MarketplaceDeal
    {
        return MarketplaceDeal::with([...self::DETAIL_RELATIONS, 'reports.reporter'])->withCount('reports')->findOrFail($dealId);
    }

    /** @param  array{status?: string, reason?: string, deal_id?: string, per_page?: int}  $f */
    public function adminReports(array $f): LengthAwarePaginator
    {
        return MarketplaceDealReport::with('deal', 'reporter')
            ->when(isset($f['status']), fn (Builder $q) => $q->where('status', $f['status']))
            ->when(isset($f['reason']), fn (Builder $q) => $q->where('reason', $f['reason']))
            ->when(isset($f['deal_id']), fn (Builder $q) => $q->where('deal_id', $f['deal_id']))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($f['per_page'] ?? 20);
    }

    // ------------------------------------------------------------------ internals

    private function viewable(User $user, string $shopId): MarketplaceShop
    {
        $shop = $this->shops->memberShop($user, $shopId);
        if (! $user->can('viewDeals', $shop)) {
            throw new AuthorizationException;
        }

        return $shop;
    }
}
