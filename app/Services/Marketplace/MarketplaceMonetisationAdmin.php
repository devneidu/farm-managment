<?php

namespace App\Services\Marketplace;

use App\Models\MarketplacePromotion;
use App\Models\MarketplacePromotionPackage;
use App\Models\MarketplaceSellerPlan;
use App\Models\MarketplaceServicePayment;
use App\Models\User;
use App\Services\Platform\PlatformAudit;
use App\Support\Api\ApiHttpException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Platform administration of seller plans, plan prices, promotion packages, and read-only oversight of service payments and promotions. Every write is
 * audited in the same transaction. A price change affects FUTURE checkouts only: a payment keeps the amount frozen when it started, and a paid period
 * keeps the limit it was bought with. Nothing is deleted - plans and packages are switched off.
 */
class MarketplaceMonetisationAdmin
{
    public const INTERVALS = [30, 365];

    public function __construct(private PlatformAudit $audit) {}

    // ------------------------------------------------------------------ seller plans

    public function plans()
    {
        return MarketplaceSellerPlan::with('prices')->orderBy('sort_order')->orderBy('code')->get();
    }

    /** @param  array{code: string, name: string, description?: string|null, listing_limit: int, is_active?: bool, sort_order?: int}  $data */
    public function createPlan(User $actor, array $data): MarketplaceSellerPlan
    {
        return DB::transaction(function () use ($actor, $data) {
            $plan = MarketplaceSellerPlan::create($data + ['is_free' => false, 'is_active' => false]);
            $this->assertActivatable($plan);
            $this->audit->record($actor, 'platform.marketplace_seller_plan_created', 'marketplace_seller_plan', $plan->id, $plan->code, [], $plan->only(['name', 'listing_limit', 'is_active']));

            return $plan->load('prices');
        });
    }

    /** @param  array<string, mixed>  $data */
    public function updatePlan(User $actor, string $id, array $data): MarketplaceSellerPlan
    {
        return DB::transaction(function () use ($actor, $id, $data) {
            $plan = MarketplaceSellerPlan::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($plan->is_free && array_key_exists('is_active', $data) && ! $data['is_active']) {
                throw new ApiHttpException(409, 'free_plan_required', 'The free plan cannot be switched off.');
            }
            $before = $plan->only(['name', 'description', 'listing_limit', 'is_active', 'sort_order']);
            $plan->fill($data)->save();
            $this->assertActivatable($plan);
            $this->audit->record($actor, 'platform.marketplace_seller_plan_updated', 'marketplace_seller_plan', $plan->id, $plan->code, $before, $plan->only(array_keys($before)));

            return $plan->load('prices');
        });
    }

    /** @param  list<array{interval_days: int, amount: string|null, is_active?: bool}>  $prices  amount NULL removes the price (the period can no longer be bought) */
    public function setPrices(User $actor, string $id, array $prices): MarketplaceSellerPlan
    {
        return DB::transaction(function () use ($actor, $id, $prices) {
            $plan = MarketplaceSellerPlan::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($plan->is_free) {
                throw new ApiHttpException(409, 'free_plan_has_no_price', 'The free plan has no price.');
            }
            $before = $plan->prices()->get()->mapWithKeys(fn ($p) => [$p->interval_days.'d' => $p->is_active ? (string) $p->amount : null])->all();
            foreach ($prices as $row) {
                $existing = $plan->prices()->where('interval_days', $row['interval_days'])->first();
                if ($row['amount'] === null) {
                    $existing?->delete();

                    continue;
                }
                $values = ['amount' => $row['amount'], 'currency' => 'NGN', 'is_active' => $row['is_active'] ?? true];
                $existing ? $existing->update($values) : $plan->prices()->create($values + ['interval_days' => $row['interval_days']]);
            }
            $plan->load('prices');
            $this->assertActivatable($plan);
            $after = $plan->prices->mapWithKeys(fn ($p) => [$p->interval_days.'d' => $p->is_active ? (string) $p->amount : null])->all();
            $this->audit->record($actor, 'platform.marketplace_seller_plan_prices_set', 'marketplace_seller_plan', $plan->id, $plan->code, $before, $after);

            return $plan;
        });
    }

    /** A paid plan can be on sale only with a numeric limit and at least one active price. */
    private function assertActivatable(MarketplaceSellerPlan $plan): void
    {
        if ($plan->is_free || ! $plan->is_active) {
            return;
        }
        $problems = [];
        if ($plan->listing_limit === null) {
            $problems['listing_limit'] = ['Set the listing limit before switching this plan on.'];
        }
        if (! $plan->prices()->where('is_active', true)->exists()) {
            $problems['prices'] = ['Set at least one price before switching this plan on.'];
        }
        if ($problems) {
            throw ValidationException::withMessages($problems);
        }
    }

    // ------------------------------------------------------------------ promotion packages

    public function packages()
    {
        return MarketplacePromotionPackage::orderBy('sort_order')->orderBy('duration_days')->get();
    }

    /** @param  array<string, mixed>  $data */
    public function createPackage(User $actor, array $data): MarketplacePromotionPackage
    {
        return DB::transaction(function () use ($actor, $data) {
            $package = MarketplacePromotionPackage::create($data + ['currency' => 'NGN']);
            $this->audit->record($actor, 'platform.marketplace_promotion_package_created', 'marketplace_promotion_package', $package->id, $package->code, [], $package->only(['name', 'duration_days', 'amount', 'is_active']));

            return $package;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function updatePackage(User $actor, string $id, array $data): MarketplacePromotionPackage
    {
        return DB::transaction(function () use ($actor, $id, $data) {
            $package = MarketplacePromotionPackage::whereKey($id)->lockForUpdate()->firstOrFail();
            $before = $package->only(['name', 'description', 'duration_days', 'amount', 'is_active', 'sort_order']);
            $package->fill($data)->save();
            $this->audit->record($actor, 'platform.marketplace_promotion_package_updated', 'marketplace_promotion_package', $package->id, $package->code, array_map('strval', $before), array_map('strval', $package->only(array_keys($before))));

            return $package;
        });
    }

    // ------------------------------------------------------------------ oversight

    /** @param  array{status?: string, purpose?: string, shop_id?: string, needs_attention?: bool, q?: string, per_page?: int}  $f */
    public function payments(array $f): LengthAwarePaginator
    {
        return MarketplaceServicePayment::with('shop')
            ->when(isset($f['status']), fn ($q) => $q->where('status', $f['status']))->when(isset($f['purpose']), fn ($q) => $q->where('purpose', $f['purpose']))
            ->when(isset($f['shop_id']), fn ($q) => $q->where('shop_id', $f['shop_id']))
            ->when(! empty($f['needs_attention']), fn ($q) => $q->whereNotNull('settlement_issue')->whereNull('settled_at'))
            ->when(isset($f['q']), fn ($q) => $q->where('reference', 'like', '%'.addcslashes($f['q'], '%_\\').'%'))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($f['per_page'] ?? 20);
    }

    /** @param  array{state?: string, shop_id?: string, per_page?: int}  $f */
    public function promotions(array $f): LengthAwarePaginator
    {
        return MarketplacePromotion::with(['listing', 'shop'])
            ->when(($f['state'] ?? null) === 'running', fn ($q) => $q->running())
            ->when(($f['state'] ?? null) === 'ended', fn ($q) => $q->where(fn ($w) => $w->where('status', MarketplacePromotion::CANCELLED)->orWhere('expires_at', '<=', now())))
            ->when(isset($f['shop_id']), fn ($q) => $q->where('shop_id', $f['shop_id']))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($f['per_page'] ?? 20);
    }

    /** Stops a promotion early (abuse, mistake). It frees the listing; money is NOT refunded here - refunds are handled outside this API. Repeating it is a no-op. */
    public function cancelPromotion(User $actor, string $id, string $reason): MarketplacePromotion
    {
        return DB::transaction(function () use ($actor, $id, $reason) {
            $promotion = MarketplacePromotion::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($promotion->status === MarketplacePromotion::ACTIVE) {
                $promotion->forceFill(['status' => MarketplacePromotion::CANCELLED, 'open_slot' => null, 'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancel_reason' => $reason])->save();
                $this->audit->record($actor, 'platform.marketplace_promotion_cancelled', 'marketplace_promotion', $promotion->id, $promotion->reference, ['status' => 'active'], ['status' => 'cancelled'], ['reason' => $reason]);
            }

            return $promotion->load(['listing', 'shop']);
        });
    }
}
