<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceServicePayment;
use Throwable;

/**
 * Safety net for lost webhooks and outages: retries unfinished webhook events, re-verifies recent pending payments with the provider (so a customer who
 * paid but whose webhook never arrived still gets the benefit) and marks long-unpaid ones abandoned. Idempotent; read-time expiry never depends on it.
 */
class MarketplacePaymentReconciler
{
    public function __construct(private MarketplacePaymentWebhook $webhooks, private ServicePaymentSettler $settler, private PaystackClient $paystack) {}

    /** @return array{events: int, verified: int, abandoned: int} */
    public function run(): array
    {
        if (! $this->paystack->configured()) {
            return ['events' => 0, 'verified' => 0, 'abandoned' => 0];
        }
        $events = $this->webhooks->retryUnfinished();
        $verified = $abandoned = 0;
        MarketplaceServicePayment::where('status', MarketplaceServicePayment::PENDING)->whereNull('settled_at')
            ->where('created_at', '>=', now()->subDays((int) config('marketplace.payments.reconcile_lookback_days')))->where('created_at', '<=', now()->subMinutes(5))
            ->orderBy('created_at')->limit(200)->get()->each(function (MarketplaceServicePayment $p) use (&$verified, &$abandoned) {
                try {
                    $fresh = $this->settler->settle($p->reference);
                    $verified++;
                } catch (Throwable) {
                    return;
                }
                if ($fresh && $fresh->status === MarketplaceServicePayment::PENDING && $fresh->created_at->lte(now()->subHours((int) config('marketplace.payments.abandon_after_hours')))) {
                    $fresh->forceFill(['status' => MarketplaceServicePayment::ABANDONED])->save();
                    $abandoned++;
                }
            });

        return ['events' => $events, 'verified' => $verified, 'abandoned' => $abandoned];
    }
}
