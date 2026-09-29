<?php

namespace App\Services\MasterData;

use App\Enums\OperationCategory;
use App\Models\Breed;
use App\Models\CropType;
use App\Models\CropVariety;
use App\Models\Farm;
use App\Models\OperationType;
use App\Models\Species;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Read side of master data: every selector query lives here so scoping rules are written once. */
class MasterDataCatalogue
{
    public function __construct(private readonly FarmOperationService $operations) {}

    /** @param  array{category?: string|null, available?: bool|null, include_inactive?: bool|null}  $f */
    public function operationTypes(Farm $farm, array $f = []): Collection
    {
        return OperationType::query()->ordered()
            ->when(empty($f['include_inactive']), fn ($q) => $q->active())
            ->when(! empty($f['category']), fn ($q) => $q->where('category', OperationCategory::from($f['category'])))
            ->when(! empty($f['available']), fn ($q) => $q->whereIn('id', $this->operations->availableIds($farm)))
            ->get();
    }

    /** @param  array{operation?: string|null, category?: string|null, available?: bool|null, include_inactive?: bool|null}  $f */
    public function species(Farm $farm, array $f = []): Collection
    {
        $query = Species::query()->ordered()->with(['operationType', 'speciesCapabilities.capability'])
            ->when(empty($f['include_inactive']), fn ($q) => $q->active());

        $this->filterByOperation($query, $farm, $f);

        return $query->get();
    }

    /** @param  array{operation?: string|null, available?: bool|null, include_inactive?: bool|null}  $f */
    public function crops(Farm $farm, array $f = []): Collection
    {
        $query = CropType::query()->ordered()->with('operationType')
            ->when(empty($f['include_inactive']), fn ($q) => $q->active());

        $this->filterByOperation($query, $farm, $f);

        return $query->get();
    }

    /** Active system breeds + this farm's active custom breeds of one species (inactive ones only when asked). */
    public function breeds(Farm $farm, Species $species, bool $includeInactive = false): Collection
    {
        return Breed::query()->visibleTo($farm)->where('species_id', $species->id)
            ->when(! $includeInactive, fn ($q) => $q->active())
            ->orderByRaw('farm_id is not null')->orderBy('name')->get();
    }

    public function varieties(Farm $farm, CropType $crop, bool $includeInactive = false): Collection
    {
        return CropVariety::query()->visibleTo($farm)->where('crop_type_id', $crop->id)
            ->when(! $includeInactive, fn ($q) => $q->active())
            ->orderByRaw('farm_id is not null')->orderBy('name')->get();
    }

    private function filterByOperation(Builder $query, Farm $farm, array $f): void
    {
        $query
            ->when(! empty($f['operation']), fn ($q) => $q->whereHas('operationType', fn ($o) => $o->where('code', $f['operation'])))
            ->when(! empty($f['category']), fn ($q) => $q->whereHas('operationType', fn ($o) => $o->where('category', OperationCategory::from($f['category']))))
            ->when(! empty($f['available']), fn ($q) => $q->whereIn('operation_type_id', $this->operations->availableIds($farm)));
    }
}
