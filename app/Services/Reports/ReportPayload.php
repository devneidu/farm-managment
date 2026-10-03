<?php

namespace App\Services\Reports;

use App\Support\Access\FarmContext;

/** The one array shape a report run takes everywhere: the JSON API response and every export file are rendered from it. */
final class ReportPayload
{
    /**
     * @param  array{report: ReportDefinition, filters: ReportFilters, result: ReportResult, generated_at: string}  $run
     * @return array<string, mixed>
     */
    public static function of(FarmContext $ctx, array $run): array
    {
        /** @var ReportDefinition $d */
        $d = $run['report'];
        /** @var ReportResult $r */
        $r = $run['result'];

        return [
            'report' => ['code' => $d->code, 'title' => $d->title, 'family' => $d->family, 'description' => $d->description],
            'farm' => ['id' => $ctx->farm->id, 'name' => $ctx->farm->name, 'currency' => $ctx->farm->currency],
            'filters' => $run['filters']->applied($d), 'timezone' => $ctx->farm->timezone, 'generated_at' => $run['generated_at'],
            'columns' => $r->columns, 'rows' => $r->rows, 'summary' => (object) $r->summary, 'notes' => $r->notes,
        ];
    }

    /**
     * Summary values flattened to label => scalar for files ("totals_by_unit.kg" => "12").
     *
     * @return array<string, string>
     */
    public static function flatSummary(array $summary, string $prefix = ''): array
    {
        $out = [];
        foreach ($summary as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix.' / '.$key;
            if (is_array($value)) {
                $out += self::flatSummary($value, $name);
            } else {
                $out[ucfirst(str_replace('_', ' ', $name))] = $value === null ? '' : (string) $value;
            }
        }

        return $out;
    }
}
