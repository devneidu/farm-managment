<?php

namespace App\Services\Reports;

/**
 * A rendered report: typed columns, rows keyed by column key, and a small summary of exact totals. Nothing here is stored; a result is
 * always derived from the authoritative data at the moment it is run (or exported).
 *
 * Column types: string, integer, decimal (quantities, exact), money (two decimals), percent, date, datetime.
 */
final class ReportResult
{
    /**
     * @param  list<array{key: string, label: string, type: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $summary
     * @param  list<string>  $notes
     */
    public function __construct(public readonly array $columns, public readonly array $rows, public readonly array $summary = [], public readonly array $notes = []) {}

    /** @return array{key: string, label: string, type: string} */
    public static function col(string $key, string $label, string $type = 'string'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }
}
