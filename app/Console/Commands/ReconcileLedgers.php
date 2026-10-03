<?php

namespace App\Console\Commands;

use App\Models\Farm;
use App\Services\Operations\LedgerReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ReconcileLedgers extends Command
{
    protected $signature = 'app:reconcile {--farm= : Restrict to one farm UUID}';

    protected $description = 'Read-only population, stock, source-link, payment and finance reconciliation; exits nonzero on discrepancies.';

    public function handle(LedgerReconciler $reconciler): int
    {
        $id = $this->option('farm');
        if ($id !== null && (! Str::isUuid($id) || ! Farm::whereKey($id)->exists())) {
            $this->error('Unknown farm UUID.');

            return self::FAILURE;
        }
        $farms = 0;
        $issues = 0;
        foreach (Farm::query()->when($id, fn ($q) => $q->whereKey($id))->lazyById(100) as $farm) {
            $farms++;
            foreach ($reconciler->forFarm($farm) as $issue) {
                $issues++;
                $this->line(json_encode(['farm_id' => $farm->id] + $issue, JSON_THROW_ON_ERROR));
            }
        }
        $this->info("Reconciled {$farms} farm(s); {$issues} discrepancy group(s). No data changed.");

        return $issues === 0 ? self::SUCCESS : self::FAILURE;
    }
}
