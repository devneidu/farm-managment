<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('subscriptions:close-lapsed')->hourly()->withoutOverlapping();
Schedule::command('marketplace:expire-offers')->hourly()->withoutOverlapping();
Schedule::command('marketplace:expire-confirmations')->hourly()->withoutOverlapping();
Schedule::command('marketplace:reconcile-payments')->everyTenMinutes()->withoutOverlapping();
Schedule::command('work:generate-tasks')->dailyAt('00:30')->withoutOverlapping();
Schedule::command('notifications:generate')->hourly()->withoutOverlapping();
Schedule::command('reports:prune-exports')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('queue:monitor database:default --max=100')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=720')->dailyAt('02:30')->withoutOverlapping();
