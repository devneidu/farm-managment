<?php

namespace App\Jobs;

use App\Models\Farm;
use App\Services\Notifications\NotificationGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/** Evaluates one farm's notification conditions on the database queue. Safe to repeat (deduplicated by the notification key). */
class GenerateFarmNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $backoff = 30;

    public int $timeout = 60;

    public function __construct(public readonly string $farmId) {}

    public function handle(NotificationGenerator $generator): void
    {
        $farm = Farm::find($this->farmId);
        if ($farm !== null) {
            $generator->forFarm($farm);
        }
    }
}
