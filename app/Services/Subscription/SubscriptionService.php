<?php

namespace App\Services\Subscription;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use App\Models\Farm;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Support\Api\ApiHttpException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Subscription lifecycle. Every mutation runs in a transaction on a locked subscription row and appends
 * a subscription_events row, so plan history is never lost by overwriting plan_id.
 *
 * Payment collection (checkout/webhooks) is deliberately NOT here: no billing provider is chosen yet.
 * Whatever confirms a payment later (webhook processor / admin tooling) calls changePlan().
 */
class SubscriptionService
{
    /** Every farm gets a subscription on the default plan the moment it exists (see Farm::booted). */
    public function startDefault(Farm $farm): Subscription
    {
        return DB::transaction(function () use ($farm) {
            $existing = Subscription::where('farm_id', $farm->id)->first();

            if ($existing) {
                return $existing;
            }

            $plan = Plan::where('is_default', true)->where('is_active', true)->orderBy('sort_order')->first()
                ?? throw new RuntimeException('No active default plan is configured; run the plan provisioning (PlanSeeder).');

            $subscription = new Subscription;
            $subscription->forceFill([
                'farm_id' => $farm->id,
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => now(),
            ])->save();

            $this->record($subscription, SubscriptionEventType::Created, toPlan: $plan, toStatus: SubscriptionStatus::Active);

            return $subscription;
        });
    }

    /**
     * Moves the farm onto $plan (upgrade or downgrade). Existing data and memberships are untouched; limits
     * only block further growth. A paid plan starts a new period of $interval from now.
     */
    public function changePlan(Farm $farm, Plan $plan, ?BillingInterval $interval = null, ?User $actor = null): Subscription
    {
        if (! $plan->is_active) {
            throw new ApiHttpException(422, 'plan_not_available', 'This plan is not available.');
        }

        return DB::transaction(function () use ($farm, $plan, $interval, $actor) {
            $subscription = $this->lock($farm);
            $from = $subscription->plan_id;
            $fromStatus = $subscription->status;

            $subscription->forceFill(['plan_id' => $plan->id, 'status' => SubscriptionStatus::Active, 'cancel_at_period_end' => false, 'cancelled_at' => null, 'ends_at' => null]);

            if ($plan->is_default) {
                $subscription->forceFill(['billing_interval' => null, 'current_period_start' => null, 'current_period_end' => null]);
            } else {
                $interval ??= BillingInterval::Monthly;
                $subscription->forceFill([
                    'billing_interval' => $interval,
                    'current_period_start' => now(),
                    'current_period_end' => $interval->addTo(now()),
                ]);
            }

            $subscription->save();

            $this->record($subscription, SubscriptionEventType::PlanChanged, $from, $plan, $fromStatus, SubscriptionStatus::Active, $actor);

            return $subscription->load('plan');
        });
    }

    /** Ends the paid subscription at the close of the current period; access continues until then. */
    public function cancel(Farm $farm, User $actor): Subscription
    {
        return DB::transaction(function () use ($farm, $actor) {
            $subscription = $this->lock($farm);
            $subscription->loadMissing('plan');

            if ($subscription->plan->is_default || ! $subscription->isCurrent()) {
                throw new ApiHttpException(409, 'subscription_not_cancellable', 'There is no paid subscription to cancel.');
            }

            if ($subscription->cancel_at_period_end) {
                throw new ApiHttpException(409, 'subscription_already_cancelled', 'The subscription is already set to end at the close of the current period.');
            }

            $subscription->forceFill(['cancel_at_period_end' => true, 'cancelled_at' => now()])->save();

            $this->record($subscription, SubscriptionEventType::CancellationScheduled, $subscription->plan_id, $subscription->plan, $subscription->status, $subscription->status, $actor);

            return $subscription;
        });
    }

    /** Withdraws a scheduled cancellation while the paid period is still running. */
    public function resume(Farm $farm, User $actor): Subscription
    {
        return DB::transaction(function () use ($farm, $actor) {
            $subscription = $this->lock($farm);
            $subscription->loadMissing('plan');

            if (! $subscription->cancel_at_period_end || ! $subscription->isCurrent()) {
                throw new ApiHttpException(409, 'subscription_not_cancelled', 'There is no scheduled cancellation to resume.');
            }

            $subscription->forceFill(['cancel_at_period_end' => false, 'cancelled_at' => null])->save();

            $this->record($subscription, SubscriptionEventType::Resumed, $subscription->plan_id, $subscription->plan, $subscription->status, $subscription->status, $actor);

            return $subscription;
        });
    }

    /**
     * Marks paid subscriptions whose period has ended as cancelled (cancellation was scheduled) or expired.
     * Entitlement resolution already stops honouring them at the period end; this records the state change.
     *
     * @return int number of subscriptions closed
     */
    public function closeLapsed(): int
    {
        $closed = 0;

        Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', now())
            ->pluck('id')
            ->each(function (string $id) use (&$closed) {
                DB::transaction(function () use ($id, &$closed) {
                    $subscription = Subscription::whereKey($id)->lockForUpdate()->first();

                    if (! $subscription || ! $subscription->status->grantsAccess() || $subscription->current_period_end === null || $subscription->current_period_end->isFuture()) {
                        return;
                    }

                    $from = $subscription->status;
                    $status = $subscription->cancel_at_period_end ? SubscriptionStatus::Cancelled : SubscriptionStatus::Expired;

                    $subscription->forceFill(['status' => $status, 'ends_at' => $subscription->current_period_end])->save();

                    $this->record(
                        $subscription,
                        $status === SubscriptionStatus::Cancelled ? SubscriptionEventType::Cancelled : SubscriptionEventType::Expired,
                        $subscription->plan_id, $subscription->plan_id ? Plan::find($subscription->plan_id) : null, $from, $status,
                    );
                    $closed++;
                });
            });

        return $closed;
    }

    private function lock(Farm $farm): Subscription
    {
        return Subscription::where('farm_id', $farm->id)->lockForUpdate()->first()
            ?? $this->startDefault($farm)->fresh();
    }

    private function record(
        Subscription $subscription,
        SubscriptionEventType $type,
        Plan|string|null $fromPlan = null,
        ?Plan $toPlan = null,
        ?SubscriptionStatus $fromStatus = null,
        ?SubscriptionStatus $toStatus = null,
        ?User $actor = null,
    ): void {
        SubscriptionEvent::create([
            'subscription_id' => $subscription->id,
            'farm_id' => $subscription->farm_id,
            'type' => $type,
            'from_plan_id' => $fromPlan instanceof Plan ? $fromPlan->id : $fromPlan,
            'to_plan_id' => $toPlan?->id,
            'from_status' => $fromStatus?->value,
            'to_status' => $toStatus?->value,
            'actor_user_id' => $actor?->id,
            'occurred_at' => now(),
        ]);
    }
}
