<?php

namespace App\Console\Commands;

use App\Services\Marketplace\MarketplaceOfferService;
use Illuminate\Console\Command;

class ExpireMarketplaceOffers extends Command
{
    protected $signature = 'marketplace:expire-offers';

    protected $description = 'Persist pending marketplace offers whose deadline has passed as expired (reads and writes already treat them as expired; this keeps the stored status and history in step).';

    public function handle(MarketplaceOfferService $offers): int
    {
        $this->info('Expired '.$offers->expireDueOffers().' marketplace offer(s).');

        return self::SUCCESS;
    }
}
