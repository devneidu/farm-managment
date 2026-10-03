<?php

namespace App\Jobs;

use App\Services\Reports\ExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Renders one queued report export on the database queue. The job carries only the export id: the farm, the requesting user and the exact filters
 * are read from the export row, and access is re-checked when it runs. Failures are recorded on the export (status failed) instead of retried,
 * because a report that fails deterministically will fail again; the user simply requests a new export.
 */
class GenerateReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly string $exportId) {}

    public function handle(ExportService $exports): void
    {
        $exports->generate($this->exportId);
    }
}
