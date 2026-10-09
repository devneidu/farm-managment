<?php

namespace App\Console\Commands;

use App\Services\Marketplace\MarketplacePaymentReconciler;
use Illuminate\Console\Command;

class ReconcileMarketplacePayments extends Command
{
    protected $signature = 'marketplace:reconcile-payments';

    protected $description = 'Retry unfinished payment webhooks, re-verify recent pending marketplace service payments and mark long-unpaid ones abandoned';

    public function handle(MarketplacePaymentReconciler $reconciler): int
    {
        $r = $reconciler->run();
        $this->info("Retried {$r['events']} webhook event(s); re-verified {$r['verified']} pending payment(s); abandoned {$r['abandoned']}.");

        return self::SUCCESS;
    }
}
