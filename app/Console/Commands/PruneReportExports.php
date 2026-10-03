<?php

namespace App\Console\Commands;

use App\Services\Reports\ExportService;
use Illuminate\Console\Command;

class PruneReportExports extends Command
{
    protected $signature = 'reports:prune-exports';

    protected $description = 'Delete the private files of report exports past their retention and mark them expired.';

    public function handle(ExportService $exports): int
    {
        $this->info('Expired '.$exports->pruneExpired().' export(s).');

        return self::SUCCESS;
    }
}
