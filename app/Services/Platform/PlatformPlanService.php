<?php

namespace App\Services\Platform;

use App\Enums\BillingInterval;
use App\Enums\Feature;
use App\Enums\Limit;
use App\Enums\SubscriptionStatus;
use App\Models\Entitlement;
use App\Models\Farm;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscription\EntitlementService;
use App\Services\Subscription\SubscriptionService;
use App\Support\Api\ApiHttpException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Platform administration of the Phase 3 plan catalogue. It edits the same plans/prices/entitlements the EntitlementService resolves,
 * so there is no second plan system. Plans are never deleted, only deactivated, and a plan with live subscribers cannot be deactivated.
 * Lowering a limit never removes a farm's data; it only blocks further growth (existing Phase 3 semantics).
 */
class PlatformPlanService
{
    public function __construct(private PlatformAudit $audit, private EntitlementService $entitlements, private SubscriptionService $subscriptions) {}

    /** @param  array{q?: string, is_active?: bool, is_public?: bool, per_page?: int}  $f */
    public function list(array $f): LengthAwarePaginator
    {
        return $this->query()
            ->when(isset($f['q']), fn (Builder $q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.self::escapeLike($f['q']).'%')->orWhere('slug', 'like', '%'.self::escapeLike($f['q']).'%')))
            ->when(isset($f['is_active']), fn (Builder $q) => $q->where('is_active', $f['is_active']))
            ->when(isset($f['is_public']), fn (Builder $q) => $q->where('is_public', $f['is_public']))
            ->orderBy('sort_order')->orderBy('slug')
            ->paginate($f['per_page'] ?? 25);
    }

    public function find(string $id): Plan
    {
        return $this->query()->findOrFail($id);
    }

    public function create(User $actor, array $data): Plan
    {
        return DB::transaction(function () use ($actor, $data) {
            // A new plan is inactive and grants nothing until an admin configures and activates it (deny by default).
            $plan = Plan::create($data + ['is_active' => false, 'is_default' => false]);
            $this->audit->record($actor, 'platform.plan_created', 'plan', $plan->id, $plan->slug, [], $this->planFacts($plan));

            return $this->find($plan->id);
        });
    }

    public function update(User $actor, Plan $plan, array $data): Plan
    {
        return DB::transaction(function () use ($actor, $plan, $data) {
            $plan = Plan::whereKey($plan->id)->lockForUpdate()->firstOrFail();

            if (($data['is_active'] ?? true) === false && $plan->is_active) {
                if ($plan->is_default) {
                    throw new ApiHttpException(409, 'default_plan_protected', 'The default plan cannot be deactivated; make another plan the default first.');
                }
                $subscribers = $this->subscribers($plan);
                if ($subscribers > 0) {
                    throw new ApiHttpException(409, 'plan_in_use', 'This plan still has active subscribers; move them to another plan before deactivating it.', details: ['subscribers' => $subscribers]);
                }
            }

            $before = $this->planFacts($plan);
            $plan->update($data);
            $this->audit->record($actor, 'platform.plan_updated', 'plan', $plan->id, $plan->slug, $before, $this->planFacts($plan));

            return $this->find($plan->id);
        });
    }

    /** Idempotent: making the current default the default again changes and audits nothing. */
    public function makeDefault(User $actor, Plan $plan): Plan
    {
        return DB::transaction(function () use ($actor, $plan) {
            $plans = Plan::lockForUpdate()->get();
            $target = $plans->firstWhere('id', $plan->id) ?? abort(404);

            if ($target->is_default) {
                return $this->find($target->id);
            }
            if (! $target->is_active) {
                throw new ApiHttpException(409, 'plan_not_active', 'Only an active plan can become the default.');
            }
            if ($target->prices()->where('is_active', true)->exists()) {
                throw new ApiHttpException(422, 'default_plan_must_be_free', 'The default plan is the free fallback and cannot have active prices.');
            }

            $previous = $plans->firstWhere('is_default', true);
            $previous?->update(['is_default' => false]);
            $target->update(['is_default' => true]);
            $this->audit->record($actor, 'platform.plan_default_changed', 'plan', $target->id, $target->slug, ['default' => $previous?->slug], ['default' => $target->slug]);

            return $this->find($target->id);
        });
    }

    /**
     * Upserts prices by interval (omitted intervals are untouched; there is no delete - set `is_active` false). Prices are integer kobo.
     *
     * @param  list<array{interval: string, amount_minor: int, is_active?: bool}>  $prices
     */
    public function setPrices(User $actor, Plan $plan, array $prices): Plan
    {
        return DB::transaction(function () use ($actor, $plan, $prices) {
            $plan = Plan::whereKey($plan->id)->lockForUpdate()->firstOrFail();

            if ($plan->is_default && collect($prices)->contains(fn ($p) => ($p['is_active'] ?? true) === true)) {
                throw new ApiHttpException(422, 'default_plan_must_be_free', 'The default plan is the free fallback and cannot have active prices.');
            }

            $before = $this->priceFacts($plan);
            foreach ($prices as $price) {
                PlanPrice::updateOrCreate(
                    ['plan_id' => $plan->id, 'interval' => BillingInterval::from($price['interval']), 'currency' => $plan->currency],
                    ['amount_minor' => $price['amount_minor'], 'is_active' => $price['is_active'] ?? true],
                );
            }
            $this->audit->record($actor, 'platform.plan_prices_updated', 'plan', $plan->id, $plan->slug, $before, $this->priceFacts($plan->refresh()));

            return $this->find($plan->id);
        });
    }

    /**
     * Sets feature flags and limits of a plan. Keys must exist in the Feature/Limit registries; `limits.*` is `{limit: int}` or `{unlimited: true}`.
     *
     * @param  array<string, bool>  $features
     * @param  array<string, array{limit?: int|null, unlimited?: bool}>  $limits
     */
    public function setEntitlements(User $actor, Plan $plan, array $features, array $limits): Plan
    {
        return DB::transaction(function () use ($actor, $plan, $features, $limits) {
            $plan = Plan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $before = $this->entitlementFacts($plan);

            foreach ($features as $key => $enabled) {
                $feature = Feature::from($key);
                PlanEntitlement::updateOrCreate(
                    ['plan_id' => $plan->id, 'entitlement_id' => $this->registryRow($key, Entitlement::TYPE_FEATURE, $feature->label())->id],
                    ['enabled' => $enabled, 'limit_value' => null, 'is_unlimited' => false],
                );
            }
            foreach ($limits as $key => $value) {
                $limit = Limit::from($key);
                $unlimited = (bool) ($value['unlimited'] ?? false);
                PlanEntitlement::updateOrCreate(
                    ['plan_id' => $plan->id, 'entitlement_id' => $this->registryRow($key, Entitlement::TYPE_LIMIT, $limit->label())->id],
                    ['enabled' => null, 'limit_value' => $unlimited ? null : (int) $value['limit'], 'is_unlimited' => $unlimited],
                );
            }

            $this->audit->record($actor, 'platform.plan_entitlements_updated', 'plan', $plan->id, $plan->slug, $before, $this->entitlementFacts($plan));

            return $this->find($plan->id);
        });
    }

    /**
     * Moves one farm onto a plan through the Phase 3 SubscriptionService (the same writer billing will use), which records the
     * subscription event with this admin as actor. No payment is taken; this is the support/complimentary path.
     */
    public function changeFarmPlan(User $actor, Farm $farm, Plan $plan, ?string $interval, string $reason): Subscription
    {
        return DB::transaction(function () use ($actor, $farm, $plan, $interval, $reason) {
            $target = Plan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $current = Subscription::where('farm_id', $farm->id)->with('plan')->first();

            if (! $target->is_active) {
                throw new ApiHttpException(422, 'plan_not_available', 'This plan is not active.');
            }
            if ($current && $current->plan_id === $target->id && $current->status === SubscriptionStatus::Active) {
                throw new ApiHttpException(409, 'plan_unchanged', 'The farm is already on this plan.');
            }

            $subscription = $this->subscriptions->changePlan($farm, $target, $interval !== null && ! $target->is_default ? BillingInterval::from($interval) : null, $actor);
            $this->audit->record($actor, 'platform.farm_plan_changed', 'farm', $farm->id, $farm->name, ['plan' => $current?->plan?->slug], ['plan' => $target->slug], ['reason' => $reason]);

            return $subscription;
        });
    }

    /** @return array{features: array<string, bool>, limits: array<string, mixed>} */
    public function entitlementFacts(Plan $plan): array
    {
        [$features, $limits] = $this->entitlements->forPlan($plan);

        return [
            'features' => array_map(fn ($v) => (bool) $v, $features),
            'limits' => array_map(fn ($v) => $v->unlimited ? 'unlimited' : $v->value, $limits),
        ];
    }

    private function query(): Builder
    {
        return Plan::query()->with('prices')->withCount(['subscriptions as subscribers_count' => fn ($q) => $q->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])]);
    }

    private function subscribers(Plan $plan): int
    {
        return Subscription::where('plan_id', $plan->id)->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])->count();
    }

    private function registryRow(string $key, string $type, string $name): Entitlement
    {
        return Entitlement::firstOrCreate(['key' => $key], ['type' => $type, 'name' => $name]);
    }

    private function planFacts(Plan $plan): array
    {
        return $plan->only(['slug', 'name', 'description', 'currency', 'is_active', 'is_public', 'is_default', 'sort_order']);
    }

    private function priceFacts(Plan $plan): array
    {
        return $plan->prices()->get()->mapWithKeys(fn ($p) => [$p->interval->value => ['amount_minor' => $p->amount_minor, 'is_active' => $p->is_active]])->all();
    }

    public static function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
