<?php

namespace App\Console\Commands;

use App\Services\Subscription\SubscriptionService;
use Illuminate\Console\Command;

class CloseLapsedSubscriptions extends Command
{
    protected $signature = 'subscriptions:close-lapsed';

    protected $description = 'Mark paid subscriptions whose period has ended as cancelled or expired (entitlements already stop applying at the period end).';

    public function handle(SubscriptionService $subscriptions): int
    {
        $this->info('Closed '.$subscriptions->closeLapsed().' lapsed subscription(s).');

        return self::SUCCESS;
    }
}
