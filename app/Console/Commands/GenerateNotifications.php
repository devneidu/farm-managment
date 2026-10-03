<?php

namespace App\Console\Commands;

use App\Enums\MembershipStatus;
use App\Jobs\GenerateFarmNotifications;
use App\Models\Farm;
use App\Services\Notifications\NotificationGenerator;
use Illuminate\Console\Command;

class GenerateNotifications extends Command
{
    protected $signature = 'notifications:generate {--farm= : Only this farm id} {--now : Run in this process instead of queueing one job per farm}';

    protected $description = 'Evaluate notification conditions (insights, task reminders) for every farm with active members and record new in-app notifications (idempotent).';

    public function handle(NotificationGenerator $generator): int
    {
        $farms = Farm::whereHas('memberships', fn ($q) => $q->where('status', MembershipStatus::Active->value))
            ->when($this->option('farm'), fn ($q, $id) => $q->whereKey($id))->orderBy('id');
        $queued = 0;
        $created = 0;
        $farms->each(function (Farm $farm) use ($generator, &$queued, &$created) {
            if ($this->option('now')) {
                $created += $generator->forFarm($farm)['created'];
            } else {
                GenerateFarmNotifications::dispatch($farm->id);
                $queued++;
            }
        });
        $this->info($this->option('now') ? "Created {$created} notification(s)." : "Queued {$queued} farm job(s).");

        return self::SUCCESS;
    }
}
