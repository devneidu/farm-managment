<?php

namespace App\Services\Reports;

use App\Support\Access\FarmContext;

abstract class Report
{
    abstract public function definition(): ReportDefinition;

    /** Read the authoritative data and shape it. The caller has already checked permissions, entitlement and the farm. */
    abstract public function run(FarmContext $ctx, ReportFilters $f): ReportResult;

    /** SQL: the JSON-stored normalised quantity of a measurement column as an exact decimal. */
    protected const QTY = "CAST(JSON_UNQUOTE(JSON_EXTRACT(%s, '\$.normalized.quantity')) AS DECIMAL(30,10))";

    protected const UNIT = "JSON_UNQUOTE(JSON_EXTRACT(%s, '\$.normalized.unit'))";

    protected function qty(string $column = 'r.measurement'): string
    {
        return sprintf(self::QTY, $column);
    }

    protected function unit(string $column = 'r.measurement'): string
    {
        return sprintf(self::UNIT, $column);
    }

    /** SQL fragment: this operational/health record has not been reversed. */
    protected function notReversed(string $table, string $alias): string
    {
        return "NOT EXISTS (select 1 from $table rv where rv.reverses_record_id = $alias.id)";
    }
}
