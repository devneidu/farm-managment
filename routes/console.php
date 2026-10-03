<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('subscriptions:close-lapsed')->hourly()->withoutOverlapping();
Schedule::command('work:generate-tasks')->dailyAt('00:30')->withoutOverlapping();
Schedule::command('notifications:generate')->hourly()->withoutOverlapping();
Schedule::command('reports:prune-exports')->dailyAt('02:00')->withoutOverlapping();
