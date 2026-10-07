<?php

namespace App\Services\Marketplace;

use App\Enums\ShopStatus;
use App\Enums\ShopVerificationStatus;
use App\Models\MarketplaceShop;
use App\Models\User;
use App\Services\Platform\PlatformAudit;
use App\Services\Platform\PlatformPlanService;
use App\Support\Api\ApiHttpException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Platform-admin oversight of seller shops: the review queue, approval (the ONLY way a shop becomes public), rejection, suspension and the
 * verified badge. Reads are for any platform role; every write is an admin write, runs in one transaction with its audit entry
 * (`platform.marketplace_*`) and uses a row lock so two admins cannot decide the same shop twice.
 */
class MarketplaceModerationService
{
    public function __construct(private PlatformAudit $audit) {}

    /** @param  array{q?: string, status?: string, verification_status?: string, farm_backed?: bool, per_page?: int}  $f */
    public function list(array $f): LengthAwarePaginator
    {
        $like = isset($f['q']) ? '%'.PlatformPlanService::escapeLike($f['q']).'%' : null;

        return MarketplaceShop::query()->with(['members' => fn ($q) => $q->where('role', 'owner'), 'members.user:id,name,email'])
            ->when($like, fn (Builder $q) => $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('reference', 'like', $like)->orWhere('slug', 'like', $like)))
            ->when(isset($f['status']), fn (Builder $q) => $q->where('status', $f['status']))
            ->when(isset($f['verification_status']), fn (Builder $q) => $q->where('verification_status', $f['verification_status']))
            ->when(isset($f['farm_backed']), fn (Builder $q) => $f['farm_backed'] ? $q->whereNotNull('farm_id') : $q->whereNull('farm_id'))
            // The review queue reads oldest-first within a status filter; the default listing is newest-first.
            ->when(($f['status'] ?? null) === ShopStatus::PendingReview->value, fn (Builder $q) => $q->orderBy('submitted_at'), fn (Builder $q) => $q->orderByDesc('created_at'))
            ->orderBy('id')
            ->paginate($f['per_page'] ?? 25);
    }

    public function show(string $id): MarketplaceShop
    {
        return MarketplaceShop::with(['members.user:id,name,email'])->findOrFail($id);
    }

    /** pending_review -> active. Records the first approval. */
    public function approve(User $actor, string $id): MarketplaceShop
    {
        return $this->decide($actor, $id, 'approved', [ShopStatus::PendingReview], function (MarketplaceShop $shop) use ($actor) {
            $shop->forceFill(['status' => ShopStatus::Active, 'status_reason' => null, 'approved_at' => $shop->approved_at ?? now(), 'approved_by' => $actor->id]);
        });
    }

    /** pending_review -> rejected, with the reason shown to the seller, who may fix the profile and submit again. */
    public function reject(User $actor, string $id, string $reason): MarketplaceShop
    {
        return $this->decide($actor, $id, 'rejected', [ShopStatus::PendingReview], fn (MarketplaceShop $shop) => $shop->forceFill(['status' => ShopStatus::Rejected, 'status_reason' => $reason]), $reason);
    }

    /** Any non-suspended, submitted shop -> suspended. Hidden from the public at once and frozen against seller edits. */
    public function suspend(User $actor, string $id, string $reason): MarketplaceShop
    {
        return $this->decide($actor, $id, 'suspended', [ShopStatus::PendingReview, ShopStatus::Active, ShopStatus::Closed, ShopStatus::Rejected], fn (MarketplaceShop $shop) => $shop->forceFill(['status' => ShopStatus::Suspended, 'status_reason' => $reason, 'suspended_at' => now()]), $reason);
    }

    /** suspended -> active when the shop had been approved, otherwise back to pending_review (suspension never grants an approval). */
    public function reinstate(User $actor, string $id): MarketplaceShop
    {
        return $this->decide($actor, $id, 'reinstated', [ShopStatus::Suspended], fn (MarketplaceShop $shop) => $shop->forceFill([
            'status' => $shop->approved_at !== null ? ShopStatus::Active : ShopStatus::PendingReview, 'status_reason' => null, 'suspended_at' => null,
        ]));
    }

    /**
     * verified (from pending/unverified/rejected) | rejected (from pending, reason) | unverified (revoke from verified, reason).
     * The badge needs an approved shop; it does not change the publishing status.
     */
    public function verification(User $actor, string $id, ShopVerificationStatus $decision, ?string $reason): MarketplaceShop
    {
        return DB::transaction(function () use ($actor, $id, $decision, $reason) {
            $shop = MarketplaceShop::whereKey($id)->lockForUpdate()->firstOrFail();
            $from = $shop->verification_status;
            $allowed = match ($decision) {
                ShopVerificationStatus::Verified => [ShopVerificationStatus::Pending, ShopVerificationStatus::Unverified, ShopVerificationStatus::Rejected],
                ShopVerificationStatus::Rejected => [ShopVerificationStatus::Pending],
                ShopVerificationStatus::Unverified => [ShopVerificationStatus::Verified],
                ShopVerificationStatus::Pending => [],
            };
            if (! in_array($from, $allowed, true)) {
                throw new ApiHttpException(409, 'invalid_verification_state', "Verification cannot move from {$from->value} to {$decision->value}.", details: ['verification_status' => $from->value]);
            }
            if ($decision === ShopVerificationStatus::Verified && $shop->approved_at === null) {
                throw new ApiHttpException(409, 'shop_not_approved', 'Approve the shop before verifying it.');
            }
            $shop->forceFill([
                'verification_status' => $decision, 'verification_reason' => $decision === ShopVerificationStatus::Verified ? null : $reason,
                'verified_at' => $decision === ShopVerificationStatus::Verified ? now() : null, 'verified_by' => $decision === ShopVerificationStatus::Verified ? $actor->id : null,
            ])->save();
            $this->audit->record($actor, 'platform.marketplace_verification_'.$decision->value, 'marketplace_shop', $shop->id, $shop->name, ['verification_status' => $from->value], ['verification_status' => $decision->value], $reason ? ['reason' => $reason] : []);

            return $shop->load('members.user:id,name,email');
        });
    }

    /** @param  list<ShopStatus>  $from */
    private function decide(User $actor, string $id, string $outcome, array $from, callable $apply, ?string $reason = null): MarketplaceShop
    {
        return DB::transaction(function () use ($actor, $id, $outcome, $from, $apply, $reason) {
            $shop = MarketplaceShop::whereKey($id)->lockForUpdate()->firstOrFail();
            $before = $shop->status;
            if (! in_array($before, $from, true)) {
                throw new ApiHttpException(409, 'invalid_shop_state', "A shop that is {$before->value} cannot be {$outcome}.", details: ['status' => $before->value]);
            }
            $apply($shop);
            $shop->save();
            $this->audit->record($actor, 'platform.marketplace_shop_'.$outcome, 'marketplace_shop', $shop->id, $shop->name, ['status' => $before->value], ['status' => $shop->status->value], $reason ? ['reason' => $reason] : []);

            return $shop->load('members.user:id,name,email');
        });
    }
}
