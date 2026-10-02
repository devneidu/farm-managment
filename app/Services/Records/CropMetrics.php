<?php

namespace App\Services\Records;

use App\Enums\CycleKind;
use App\Enums\Permission;
use App\Models\OperationalRecord;
use App\Models\ProductionCycle;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use App\Support\Measurement\Decimal;

/** Read model for a crop project: everything is derived from the planting-unit baseline and the project's own non-reversed records. */
class CropMetrics
{
    /** Whole-number-friendly percentage with at most two decimals: 47 of 50 is "94". */
    public static function percent(int $part, int $whole): string
    {
        if ($whole <= 0) {
            return '0';
        }

        return Decimal::trim(Decimal::round(Decimal::div(Decimal::mul((string) $part, '100'), (string) $whole), 2));
    }

    public function summary(FarmContext $ctx, string $cycleId): array
    {
        $ctx->authorize(Permission::RecordView);
        $cycle = ProductionCycle::ofFarm($ctx->farm)->with(['crop.cropType', 'crop.unitType', 'productionArea'])->findOrFail($cycleId);
        if ($cycle->kind !== CycleKind::Crop) {
            throw new ApiHttpException(409, 'not_a_crop_project', 'Crop project detail is only available for crop projects.');
        }
        $baseline = (int) $cycle->crop->initial_planting_units;
        $records = OperationalRecord::where('farm_id', $ctx->farm->id)->where('production_cycle_id', $cycle->id)
            ->whereIn('type', ['land_preparation', 'planting', 'establishment_check', 'growth_stage', 'crop_loss', 'crop_harvest', 'fertilizer_application', 'pesticide_application', 'irrigation', 'weeding', 'pest_observation'])
            ->whereDoesntHave('reversal')->orderBy('recorded_at')->orderBy('id')->get()->groupBy('type');
        $of = fn (string $type) => $records->get($type, collect());
        $sum = fn (string $type, string $field) => (int) $of($type)->sum(fn ($r) => (int) ($r->details[$field] ?? 0));
        $latest = fn (string $type) => $of($type)->last();

        $planted = $sum('planting', 'units_planted');
        $lost = $sum('crop_loss', 'units_lost');
        $check = $latest('establishment_check');
        $stage = $latest('growth_stage');
        $harvest = [];
        foreach ($of('crop_harvest') as $record) {
            $n = $record->measurement['normalized'];
            $harvest[$n['unit']] = Decimal::add($harvest[$n['unit']] ?? '0', (string) $n['quantity']);
        }
        $causes = [];
        foreach ($of('crop_loss') as $record) {
            $causes[$record->details['cause']] = ($causes[$record->details['cause']] ?? 0) + (int) $record->details['units_lost'];
        }

        return [
            'production_cycle_id' => $cycle->id, 'name' => $cycle->name, 'reference' => $cycle->reference, 'status' => $cycle->status->value,
            'crop_type' => ['id' => $cycle->crop->cropType->id, 'code' => $cycle->crop->cropType->code, 'name' => $cycle->crop->cropType->name],
            'production_area' => $cycle->productionArea ? ['id' => $cycle->productionArea->id, 'name' => $cycle->productionArea->name] : null,
            'planting_date' => $cycle->start_date->toDateString(),
            'baseline' => ['planting_unit_type' => $cycle->crop->unitType->code, 'planting_unit_label' => $cycle->crop->unitType->name, 'initial_planting_units' => $baseline],
            'planting' => ['units_planted' => $planted, 'units_remaining_to_plant' => max(0, $baseline - $planted), 'events' => $of('planting')->count()],
            'establishment' => $check ? [
                'record_id' => $check->id, 'assessed_at' => $check->recorded_at->toISOString(), 'established_units' => $check->details['established_units'],
                'failed_units' => $check->details['failed_units'], 'survival_percent' => $check->details['survival_percent'], 'assessments' => $of('establishment_check')->count(),
            ] : null,
            'growth_stage' => $stage ? ['record_id' => $stage->id, 'stage' => $stage->details['stage'], 'recorded_at' => $stage->recorded_at->toISOString()] : null,
            'losses' => ['units_lost' => $lost, 'events' => $of('crop_loss')->count(), 'by_cause' => (object) $causes],
            'harvest' => [
                'events' => $of('crop_harvest')->count(), 'last_harvest_at' => $latest('crop_harvest')?->recorded_at->toISOString(),
                'totals' => array_map(fn ($unit, $quantity) => ['unit' => $unit, 'quantity' => $quantity], array_keys($harvest), array_values($harvest)),
            ],
            'activity' => [
                'land_preparation' => $of('land_preparation')->count(), 'fertilizer_applications' => $of('fertilizer_application')->count(),
                'pesticide_applications' => $of('pesticide_application')->count(), 'irrigation' => $of('irrigation')->count(),
                'weeding' => $of('weeding')->count(), 'pest_observations' => $of('pest_observation')->count(),
            ],
        ];
    }
}
