<?php

namespace App\Services\MasterData;

use App\Models\Farm;
use App\Models\FarmOperation;
use App\Models\OperationType;
use Illuminate\Support\Facades\DB;

/**
 * Optional, post-onboarding operation selection. Rule: a farm that has selected NO operations is "unconfigured" and
 * nothing is hidden from it; once it selects some, `available` filters narrow selectors to those operations.
 */
class FarmOperationService
{
    /** @return list<string> selected operation type ids */
    public function selectedIds(Farm $farm): array
    {
        return FarmOperation::where('farm_id', $farm->id)->pluck('operation_type_id')->all();
    }

    public function isConfigured(Farm $farm): bool
    {
        return FarmOperation::where('farm_id', $farm->id)->exists();
    }

    /** Operation type ids the farm may currently use: all active ones when unconfigured, else the selected ones. */
    public function availableIds(Farm $farm): array
    {
        $selected = $this->selectedIds($farm);

        return $selected !== [] ? $selected : OperationType::active()->pluck('id')->all();
    }

    /**
     * Replaces the farm's selection (empty = back to unconfigured). Nothing operational depends on it yet.
     *
     * @param  list<string>  $operationTypeIds  validated, active operation ids
     */
    public function sync(Farm $farm, array $operationTypeIds): void
    {
        DB::transaction(function () use ($farm, $operationTypeIds) {
            $ids = array_values(array_unique($operationTypeIds));

            FarmOperation::where('farm_id', $farm->id)->whereNotIn('operation_type_id', $ids)->delete();

            $existing = FarmOperation::where('farm_id', $farm->id)->pluck('operation_type_id')->all();
            foreach (array_diff($ids, $existing) as $id) {
                FarmOperation::create(['farm_id' => $farm->id, 'operation_type_id' => $id]);
            }
        });
    }
}
