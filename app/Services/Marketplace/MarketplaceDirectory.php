<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceShop;
use App\Services\Platform\PlatformPlanService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The PUBLIC read side. Everything goes through MarketplaceShop::public(), so a draft, pending, rejected, suspended or closed shop is
 * indistinguishable from a shop that does not exist.
 */
class MarketplaceDirectory
{
    /** @param  array{q?: string, state?: string, city?: string, category?: string, seller_type?: string, verified?: bool, sort?: string, per_page?: int}  $f */
    public function list(array $f): LengthAwarePaginator
    {
        $like = isset($f['q']) ? '%'.PlatformPlanService::escapeLike($f['q']).'%' : null;
        $sort = $f['sort'] ?? 'newest';

        return MarketplaceShop::public()
            ->when($like, fn (Builder $q) => $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('tagline', 'like', $like)->orWhere('description', 'like', $like)))
            ->when(isset($f['state']), fn (Builder $q) => $q->where('state', $f['state']))
            ->when(isset($f['city']), fn (Builder $q) => $q->where('city', $f['city']))
            ->when(isset($f['seller_type']), fn (Builder $q) => $q->where('seller_type', $f['seller_type']))
            ->when(isset($f['category']), fn (Builder $q) => $q->whereJsonContains('categories', $f['category']))
            ->when(isset($f['verified']), fn (Builder $q) => $f['verified'] ? $q->where('verification_status', 'verified') : $q->where('verification_status', '!=', 'verified'))
            ->when($sort === 'name', fn (Builder $q) => $q->orderBy('name'), fn (Builder $q) => $q->orderByDesc('approved_at'))
            ->orderBy('id')
            ->paginate($f['per_page'] ?? 20);
    }

    public function find(string $slug): MarketplaceShop
    {
        return MarketplaceShop::public()->where('slug', $slug)->firstOrFail();
    }
}
