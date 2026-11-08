<?php

namespace App\Services\Reports\Reports;

use App\Enums\Permission;
use App\Models\CustomRecordType;
use App\Models\ProductionCycle;
use App\Services\Records\CustomRecordSchema;
use App\Services\Reports\Report;
use App\Services\Reports\ReportDefinition;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use App\Support\Access\FarmContext;
use App\Support\Measurement\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Records of farm-made record types, per type and batch: how many records, and the exact sum of each number field. Reversed records are excluded.
 * One row per type, batch and number field (a type with no number field gets one row with an empty field), so a CSV stays a flat table whatever
 * fields farms invent. `records` is the number of records of that type in that batch; it repeats on each of its field rows and is counted once in the summary.
 */
class CustomRecordsReport extends Report
{
    public function __construct(private CustomRecordSchema $schema) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition('custom_records', 'Farm-made records', 'records', 'Records of your own record types per batch or crop project in the period, with the total of each number field; reversed records are excluded.',
            [Permission::RecordView, Permission::ProductionCycleView], ['from', 'to', 'production_cycle_id', 'custom_record_type_id']);
    }

    public function run(FarmContext $ctx, ReportFilters $f): ReportResult
    {
        $where = "r.farm_id = ? AND r.type = 'custom' AND r.recorded_at >= ? AND r.recorded_at < ? AND ".$this->notReversed('operational_records', 'r');
        $bindings = [$ctx->farm->id, $f->fromTs(), $f->toTs()];
        if ($f->productionCycleId) {
            $where .= ' AND r.production_cycle_id = ?';
            $bindings[] = $f->productionCycleId;
        }
        if ($f->customRecordTypeId) {
            $where .= ' AND r.custom_record_type_id = ?';
            $bindings[] = $f->customRecordTypeId;
        }
        $typeIds = array_column(DB::select("SELECT DISTINCT r.custom_record_type_id FROM operational_records r WHERE $where", $bindings), 'custom_record_type_id');
        $types = CustomRecordType::whereIn('id', $typeIds)->orderBy('name')->get();

        $rows = [];
        $sums = [];
        $records = 0;
        foreach ($types as $type) {
            $numbers = $this->schema->numberFields($type->fields);
            $select = '';
            foreach ($numbers as $i => $field) {
                // The key is [a-z0-9_] by construction (CustomRecordSchema), and is still quoted in the JSON path.
                $select .= ", SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.details, '\$.\"".$field['key']."\"')) AS DECIMAL(30,10))) AS s$i";
            }
            $data = DB::select("SELECT r.production_cycle_id, COUNT(*) AS records$select FROM operational_records r WHERE $where AND r.custom_record_type_id = ? GROUP BY r.production_cycle_id", [...$bindings, $type->id]);
            $cycles = ProductionCycle::ofFarm($ctx->farm)->whereIn('id', array_column($data, 'production_cycle_id'))->get()->keyBy('id');
            usort($data, fn ($a, $b) => $cycles[$a->production_cycle_id]->reference <=> $cycles[$b->production_cycle_id]->reference);
            foreach ($data as $d) {
                $cycle = $cycles[$d->production_cycle_id];
                $records += (int) $d->records;
                $base = ['type' => $type->name, 'reference' => $cycle->reference, 'name' => $cycle->name, 'records' => (int) $d->records];
                if ($numbers === []) {
                    $rows[] = $base + ['field' => null, 'unit' => null, 'sum' => null];

                    continue;
                }
                foreach ($numbers as $i => $field) {
                    $sum = Decimal::trim((string) ($d->{"s$i"} ?? '0'));
                    $total = $type->id.'|'.$field['key'];
                    $rows[] = $base + ['field' => $field['label'], 'unit' => $field['unit'] ?? null, 'sum' => $sum];
                    $sums[$total] ??= ['type' => $type->name, 'field' => $field['label'], 'unit' => $field['unit'] ?? null, 'sum' => '0'];
                    $sums[$total]['sum'] = Decimal::add($sums[$total]['sum'], $sum);
                }
            }
        }

        return new ReportResult([
            ReportResult::col('type', 'Record type'), ReportResult::col('reference', 'Reference'), ReportResult::col('name', 'Batch / project'),
            ReportResult::col('records', 'Records', 'integer'), ReportResult::col('field', 'Number field'), ReportResult::col('unit', 'Unit'), ReportResult::col('sum', 'Total', 'decimal'),
        ], $rows, ['records' => $records, 'sums' => array_values($sums)], ['Each row is one number field of one record type in one batch; "Records" is the count of that type in that batch and repeats on its field rows.']);
    }
}
