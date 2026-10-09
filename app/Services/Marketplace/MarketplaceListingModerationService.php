<?php

namespace App\Services\Marketplace;

use App\Enums\ListingStatus;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceListingImage;
use App\Models\User;
use App\Services\Platform\PlatformAudit;
use App\Services\Platform\PlatformPlanService;
use App\Support\Api\ApiHttpException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Platform-admin oversight of listings: see every listing in any state, RESTRICT one (off the public feed and frozen against seller edits, with a
 * reason shown to the seller) and LIFT the restriction. There is no separate "hide": restricting is hiding. Nothing is deleted - the listing, its
 * photos and its history stay, and every decision is an append-only history row plus a `platform.marketplace_listing_*` audit entry, in the
 * same transaction as the change and behind a row lock so two admins cannot decide the same listing twice.
 */
class MarketplaceListingModerationService
{
    private const WITH = ['shop', 'unit', 'packageBasisUnit', 'species', 'cropType', 'catalogImage', 'images'];

    public function __construct(private PlatformAudit $audit, private MarketplaceListingHistory $history) {}

    /** @param  array{q?: string, status?: string, shop_id?: string, product_kind?: string, per_page?: int}  $f */
    public function list(array $f): LengthAwarePaginator
    {
        $like = isset($f['q']) ? '%'.PlatformPlanService::escapeLike($f['q']).'%' : null;

        return MarketplaceListing::query()->with(self::WITH)
            ->when($like, fn (Builder $q) => $q->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('reference', 'like', $like)->orWhere('slug', 'like', $like)))
            ->when(isset($f['status']), fn (Builder $q) => $q->where('status', $f['status']))
            ->when(isset($f['shop_id']), fn (Builder $q) => $q->where('shop_id', $f['shop_id']))
            ->when(isset($f['product_kind']), fn (Builder $q) => $q->where('product_kind', $f['product_kind']))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($f['per_page'] ?? 25);
    }

    public function show(string $id): MarketplaceListing
    {
        return MarketplaceListing::withTrashed()->with([...self::WITH, 'events'])->findOrFail($id);
    }

    public function image(string $listingId, string $imageId): MarketplaceListingImage
    {
        return MarketplaceListingImage::where('listing_id', $listingId)->findOrFail($imageId);
    }

    /** draft | published | paused -> restricted, with a reason. Archived and already-restricted listings cannot be restricted. */
    public function restrict(User $actor, string $id, string $reason): MarketplaceListing
    {
        return $this->decide($actor, $id, 'restricted', [ListingStatus::Draft, ListingStatus::Published, ListingStatus::Paused], ListingStatus::Restricted, $reason, function (MarketplaceListing $l) use ($actor, $reason) {
            $l->forceFill(['restricted_at' => now(), 'restricted_reason' => $reason, 'restricted_by' => $actor->id]);
        });
    }

    /** restricted -> paused. The seller then decides whether to publish again; lifting never republishes by itself. */
    public function lift(User $actor, string $id, string $reason): MarketplaceListing
    {
        return $this->decide($actor, $id, 'restriction_lifted', [ListingStatus::Restricted], ListingStatus::Paused, $reason, function (MarketplaceListing $l) {
            $l->forceFill(['restricted_at' => null, 'restricted_reason' => null, 'restricted_by' => null, 'paused_at' => now()]);
        });
    }

    /** @param  list<ListingStatus>  $from */
    private function decide(User $actor, string $id, string $action, array $from, ListingStatus $to, ?string $reason, callable $apply): MarketplaceListing
    {
        return DB::transaction(function () use ($actor, $id, $action, $from, $to, $reason, $apply) {
            $listing = MarketplaceListing::whereKey($id)->lockForUpdate()->firstOrFail();
            $before = $listing->status;
            if ($before === $to) {   // idempotent repeat of the same decision
                return $this->show($id);
            }
            if (! in_array($before, $from, true)) {
                throw new ApiHttpException(409, 'invalid_listing_state', "A listing that is {$before->value} cannot be moved to {$to->value}.", details: ['status' => $before->value]);
            }
            $apply($listing);
            $listing->forceFill(['status' => $to, 'version' => $listing->version + 1])->save();
            $this->history->record($listing, 'platform', $actor->id, $action, $before->value, $to->value, $reason);
            $this->audit->record($actor, 'platform.marketplace_listing_'.$action, 'marketplace_listing', $listing->id, $listing->title, ['status' => $before->value], ['status' => $to->value], $reason ? ['reason' => $reason] : []);

            return $this->show($id);
        });
    }
}
