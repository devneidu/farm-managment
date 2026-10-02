<?php

namespace App\Console\Commands;

use App\Services\Work\ScheduleService;
use Illuminate\Console\Command;

class GenerateScheduledTasks extends Command
{
    protected $signature = 'work:generate-tasks';

    protected $description = 'Top up the rolling task horizon of every active schedule (idempotent; creates tasks only, never operational records).';

    public function handle(ScheduleService $schedules): int
    {
        $this->info('Created '.$schedules->generateDue().' task(s).');

        return self::SUCCESS;
    }
}
