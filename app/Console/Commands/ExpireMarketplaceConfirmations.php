<?php

namespace App\Console\Commands;

use App\Services\Marketplace\MarketplaceDealService;
use Illuminate\Console\Command;

class ExpireMarketplaceConfirmations extends Command
{
    protected $signature = 'marketplace:expire-confirmations';

    protected $description = 'Persist unanswered seller confirmations whose deadline has passed as lapsed (reads and writes already treat them as lapsed; this keeps the stored status and audit trail in step).';

    public function handle(MarketplaceDealService $deals): int
    {
        $this->info('Lapsed '.$deals->expireDueConfirmations().' marketplace confirmation(s).');

        return self::SUCCESS;
    }
}
