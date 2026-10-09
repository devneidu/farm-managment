<?php

namespace App\Services\Marketplace;

use App\Enums\ListingStatus;
use App\Enums\ShopStatus;
use App\Models\MarketplaceListing;
use App\Models\MarketplacePromotion;
use App\Models\MarketplaceSellerPlan;
use App\Models\MarketplaceServicePayment;
use App\Models\MarketplaceShop;
use App\Models\MarketplaceShopSubscription;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY place a Farmvest service payment turns into a benefit.
 *
 *  1. Ask the provider (server to server) what really happened to this reference. A browser redirect, a query string or a webhook body is never believed.
 *  2. Activate only when the provider says `success` AND the amount (kobo) and currency equal what was frozen at checkout. Anything else is recorded, never granted.
 *  3. Grant inside one transaction that locks the payment row (and the shop), checks `settled_at` and writes it: running this any number of times, from a
 *     webhook, from the verify endpoint and from the reconcile command at the same moment, grants the benefit exactly once.
 *
 * A provider outage throws PaymentGatewayException and changes nothing, so the caller can retry later.
 */
class ServicePaymentSettler
{
    private const PROMOTION_LOCK = 'marketplace_promotion';

    public function __construct(private PaystackClient $paystack, private MarketplaceSellerAllowance $allowance, private AuditLogger $audit) {}

    /** @throws PaymentGatewayException */
    public function settle(string $reference): ?MarketplaceServicePayment
    {
        $payment = MarketplaceServicePayment::where('reference', $reference)->first();
        if ($payment === null) {
            return null;               // not one of ours: ignore
        }
        if ($payment->settled_at !== null) {
            return $payment;           // already granted: nothing to verify, nothing to do
        }

        $truth = $this->paystack->verify($payment->reference);
        $outcome = $this->judge($payment, $truth);

        if ($outcome !== 'success') {
            $this->record($payment, $truth['status'], $outcome);

            return $payment->refresh();
        }

        return $payment->purpose === 'promotion' ? $this->grantPromotion($payment->id, $truth['status']) : $this->grantSubscription($payment->id, $truth['status']);
    }

    /** @param  array{status: string, reference: string|null, amount: string|null, currency: string|null}  $truth */
    private function judge(MarketplaceServicePayment $payment, array $truth): string
    {
        return match (true) {
            $truth['status'] === 'success' && $truth['reference'] !== $payment->reference => 'reference_mismatch',
            $truth['status'] === 'success' && $truth['currency'] !== $payment->currency => 'currency_mismatch',
            $truth['status'] === 'success' && ! $this->sameAmount($truth['amount'], (string) $payment->amount) => 'amount_mismatch',
            $truth['status'] === 'success' => 'success',
            in_array($truth['status'], ['failed', 'reversed'], true) => 'gateway_failed',
            default => 'pending',      // abandoned / ongoing / pending / not_found: the customer may still pay
        };
    }

    private function sameAmount(?string $kobo, string $naira): bool
    {
        return $kobo !== null && preg_match('/^\d+$/', $kobo) === 1 && ltrim($kobo, '0') === ltrim(PaystackClient::toKobo($naira), '0');
    }

    private function record(MarketplaceServicePayment $payment, string $gatewayStatus, string $outcome): void
    {
        DB::transaction(function () use ($payment, $gatewayStatus, $outcome) {
            $p = MarketplaceServicePayment::whereKey($payment->id)->lockForUpdate()->first();
            if ($p->settled_at !== null) {
                return;                // a concurrent settlement won: never downgrade a granted payment
            }
            $failed = $outcome !== 'pending';
            $p->forceFill(['gateway_status' => $gatewayStatus, 'verified_at' => now(), 'status' => $failed ? MarketplaceServicePayment::FAILED : ($p->status === MarketplaceServicePayment::ABANDONED ? $p->status : MarketplaceServicePayment::PENDING),
                'failure_reason' => $failed ? $outcome : null])->save();
            if ($failed) {
                $shop = MarketplaceShop::find($p->shop_id);
                $this->audit->record($shop?->farm_id, null, 'marketplace.service_payment_rejected', 'marketplace_service_payment', $p->id, $p->reference, ['reason' => $outcome, 'gateway_status' => $gatewayStatus]);
            }
        });
    }

    private function grantSubscription(string $paymentId, string $gatewayStatus): MarketplaceServicePayment
    {
        return DB::transaction(function () use ($paymentId, $gatewayStatus) {
            $payment = MarketplaceServicePayment::whereKey($paymentId)->lockForUpdate()->first();
            if ($payment->settled_at !== null) {
                return $payment;
            }
            $shop = MarketplaceShop::whereKey($payment->shop_id)->lockForUpdate()->first();   // serialises renewals of one shop
            $this->markPaid($payment, $gatewayStatus);
            $plan = MarketplaceSellerPlan::find($payment->plan_id);
            $last = MarketplaceShopSubscription::where('shop_id', $shop->id)->where('ends_at', '>', now())->orderByDesc('ends_at')->first();
            if ($last && $last->plan_id !== $payment->plan_id) {
                return $this->issue($payment, 'subscription_active_other_plan');
            }
            $starts = $last ? $last->ends_at->copy() : now();   // a renewal extends the paid period: it begins where the current one ends
            $period = MarketplaceShopSubscription::create([
                'shop_id' => $shop->id, 'plan_id' => $payment->plan_id, 'payment_id' => $payment->id, 'interval_days' => $payment->interval_days,
                'listing_limit' => $plan->listing_limit, 'plan_name' => $plan->name, 'starts_at' => $starts, 'ends_at' => $starts->copy()->addDays($payment->interval_days),
            ]);
            $payment->forceFill(['settled_at' => now(), 'settlement_issue' => null])->save();
            $this->audit->record($shop->farm_id, null, 'marketplace.subscription_activated', 'marketplace_shop_subscription', $period->id, $period->plan_name,
                ['payment' => $payment->reference, 'starts_at' => $period->starts_at->toIso8601String(), 'ends_at' => $period->ends_at->toIso8601String(), 'listing_limit' => $period->listing_limit]);

            return $payment;
        });
    }

    private function grantPromotion(string $paymentId, string $gatewayStatus): MarketplaceServicePayment
    {
        return MarketplaceReferences::locked(self::PROMOTION_LOCK, fn () => DB::transaction(function () use ($paymentId, $gatewayStatus) {
            $payment = MarketplaceServicePayment::whereKey($paymentId)->lockForUpdate()->first();
            if ($payment->settled_at !== null) {
                return $payment;
            }
            $shop = MarketplaceShop::whereKey($payment->shop_id)->lockForUpdate()->first();
            $listing = MarketplaceListing::whereKey($payment->listing_id)->lockForUpdate()->first();
            $this->markPaid($payment, $gatewayStatus);
            // A suspended shop or a restricted/archived listing earns no promotion; the money is kept on record for an administrator, never silently converted.
            if ($shop->status !== ShopStatus::Active) {
                return $this->issue($payment, 'shop_not_eligible');
            }
            if (! in_array($listing->status, [ListingStatus::Published, ListingStatus::Paused], true)) {
                return $this->issue($payment, 'listing_not_eligible');
            }
            // Free the slot of a promotion that has run out (expiry is read-time, so its slot is released lazily, here).
            MarketplacePromotion::where('listing_id', $listing->id)->whereNotNull('open_slot')->where('expires_at', '<=', now())->update(['open_slot' => null]);
            if (MarketplacePromotion::where('listing_id', $listing->id)->whereNotNull('open_slot')->exists()) {
                return $this->issue($payment, 'listing_already_promoted');
            }
            $package = $payment->package_id ? DB::table('marketplace_promotion_packages')->where('id', $payment->package_id)->first() : null;
            $days = (int) ($package->duration_days ?? 0);
            try {
                $promotion = new MarketplacePromotion([
                    'shop_id' => $shop->id, 'listing_id' => $listing->id, 'payment_id' => $payment->id, 'package_id' => $payment->package_id, 'package_name' => $package->name ?? $payment->subject_label,
                    'duration_days' => $days, 'amount' => $payment->amount, 'currency' => $payment->currency, 'status' => MarketplacePromotion::ACTIVE,
                    'starts_at' => now(), 'expires_at' => now()->addDays($days),
                ]);
                $promotion->forceFill(['reference' => MarketplaceReferences::next('MPR', MarketplacePromotion::class), 'open_slot' => 'A'])->save();
            } catch (UniqueConstraintViolationException) {
                return $this->issue($payment, 'listing_already_promoted');
            }
            $payment->forceFill(['settled_at' => now(), 'settlement_issue' => null])->save();
            $this->audit->record($shop->farm_id, null, 'marketplace.promotion_activated', 'marketplace_promotion', $promotion->id, $promotion->reference,
                ['payment' => $payment->reference, 'listing_id' => $listing->id, 'starts_at' => $promotion->starts_at->toIso8601String(), 'expires_at' => $promotion->expires_at->toIso8601String()]);

            return $payment;
        }));
    }

    private function markPaid(MarketplaceServicePayment $payment, string $gatewayStatus): void
    {
        $payment->forceFill(['status' => MarketplaceServicePayment::PAID, 'gateway_status' => $gatewayStatus, 'failure_reason' => null, 'verified_at' => now(), 'paid_at' => $payment->paid_at ?? now()])->save();
    }

    private function issue(MarketplaceServicePayment $payment, string $issue): MarketplaceServicePayment
    {
        $payment->forceFill(['settlement_issue' => $issue])->save();
        $shop = MarketplaceShop::find($payment->shop_id);
        $this->audit->record($shop?->farm_id, null, 'marketplace.service_payment_unsettled', 'marketplace_service_payment', $payment->id, $payment->reference, ['issue' => $issue]);

        return $payment;
    }
}
