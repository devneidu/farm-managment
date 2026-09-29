<?php

namespace App\Services\Measurement;

use App\Models\MeasurementDimension;
use App\Models\Unit;
use App\Support\Measurement\MeasurementException;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read access to the standard dimensions and units. Selector lists are ALWAYS filtered by dimension (and optionally
 * family): the frontend never receives - or has to filter - the full unit list.
 */
class UnitCatalogue
{
    /** @return Collection<int, MeasurementDimension> */
    public function dimensions(bool $includeInactive = false): Collection
    {
        return MeasurementDimension::query()
            ->when(! $includeInactive, fn ($q) => $q->where('is_active', true))
            ->with(['units' => fn ($q) => $q->active()->ordered()])
            ->ordered()->get();
    }

    /** @return Collection<int, Unit> */
    public function units(string $dimensionCode, ?string $family = null, bool $includeInactive = false): Collection
    {
        return Unit::query()
            ->with('dimension')
            ->whereHas('dimension', fn ($q) => $q->where('code', $dimensionCode))
            ->when($family, fn ($q) => $q->where('family', $family))
            ->when(! $includeInactive, fn ($q) => $q->active())
            ->ordered()->get();
    }

    /** A unit chosen for a NEW entry: must exist and be selectable. */
    public function selectable(string $code): Unit
    {
        $unit = $this->find($code);
        if (! $unit->is_active || ! $unit->dimension->is_active) {
            throw MeasurementException::unitNotSelectable($code);
        }

        return $unit;
    }

    /** Any known unit, including inactive ones (history stays resolvable). */
    public function find(string $code): Unit
    {
        return Unit::with('dimension')->where('code', $code)->first() ?? throw MeasurementException::unknownUnit($code);
    }
}
