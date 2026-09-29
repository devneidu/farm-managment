<?php

namespace App\Services\MasterData;

use App\Events\MasterData\CustomMasterDataChanged;
use App\Models\Breed;
use App\Models\CropType;
use App\Models\CropVariety;
use App\Models\Species;
use App\Models\User;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and maintains a farm's CUSTOM breeds and crop varieties. The farm always comes from the FarmContext (never
 * from the request); system rows are never touched; nothing is hard-deleted (deactivate/reactivate instead, so
 * future records that reference an item stay understandable).
 */
class CustomMasterDataService
{
    public function createBreed(FarmContext $ctx, User $actor, Species $species, string $name): Breed
    {
        if (! $species->is_active) {
            throw ValidationException::withMessages(['species_id' => 'This species is not active.']);
        }

        return $this->create($ctx, $actor, Breed::class, $species->id, $name);
    }

    public function createVariety(FarmContext $ctx, User $actor, CropType $crop, string $name): CropVariety
    {
        if (! $crop->is_active) {
            throw ValidationException::withMessages(['crop_type_id' => 'This crop is not active.']);
        }

        return $this->create($ctx, $actor, CropVariety::class, $crop->id, $name);
    }

    /**
     * @param  array{name?: string, is_active?: bool}  $changes
     */
    public function update(FarmContext $ctx, User $actor, Breed|CropVariety $record, array $changes): Breed|CropVariety
    {
        // Defence in depth: controllers only look records up through the farm's own custom scope.
        if ($record->farm_id !== $ctx->farm->id) {
            throw new ApiHttpException(404, 'not_found', 'Resource not found.');
        }

        $updated = $this->guardDuplicate(function () use ($record, $changes, $ctx) {
            return DB::transaction(function () use ($record, $changes, $ctx) {
                $record = $record::query()->lockForUpdate()->findOrFail($record->id);
                $diff = [];

                if (isset($changes['name']) && $changes['name'] !== $record->name) {
                    $this->assertNameFree($record::class, $record->{$record::parentColumn()}, $ctx, $changes['name'], $record->id);
                    $diff['name'] = ['old' => $record->name, 'new' => $changes['name']];
                    $record->name = $changes['name'];
                }
                if (isset($changes['is_active']) && (bool) $changes['is_active'] !== $record->is_active) {
                    $diff['is_active'] = ['old' => $record->is_active, 'new' => (bool) $changes['is_active']];
                    $record->is_active = (bool) $changes['is_active'];
                }

                if ($diff !== []) {
                    $record->save();
                }

                return [$record, $diff];
            });
        });

        [$record, $diff] = $updated;

        foreach ($this->actionsFor($diff) as $action) {
            CustomMasterDataChanged::dispatch($action, $record, $actor, $diff);
        }

        return $record;
    }

    private function create(FarmContext $ctx, User $actor, string $class, string $parentId, string $name): Breed|CropVariety
    {
        $record = $this->guardDuplicate(function () use ($ctx, $class, $parentId, $name) {
            return DB::transaction(function () use ($ctx, $class, $parentId, $name) {
                $this->assertNameFree($class, $parentId, $ctx, $name);

                return $class::create([
                    $class::parentColumn() => $parentId,
                    'farm_id' => $ctx->farm->id,
                    'name' => $name,
                ]);
            });
        });

        CustomMasterDataChanged::dispatch(CustomMasterDataChanged::CREATED, $record, $actor);

        return $record;
    }

    /** A name may not repeat within the parent among system rows and THIS farm's rows (case/space-insensitive). */
    private function assertNameFree(string $class, string $parentId, FarmContext $ctx, string $name, ?string $exceptId = null): void
    {
        $existing = $class::query()
            ->visibleTo($ctx->farm)
            ->where($class::parentColumn(), $parentId)
            ->where('normalized_name', $class::normalizeName($name))
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->first();

        if ($existing) {
            throw new ApiHttpException(409, 'duplicate_name', $existing->is_active
                ? 'An item with this name already exists.'
                : 'An inactive item with this name already exists; reactivate it instead.', details: [
                    'existing_id' => $existing->id,
                    'source' => $existing->source(),
                    'is_active' => $existing->is_active,
                ]);
        }
    }

    /** Turns the unique-index race (two simultaneous creates) into the same 409 as the pre-check. */
    private function guardDuplicate(callable $work): mixed
    {
        try {
            return $work();
        } catch (UniqueConstraintViolationException) {
            throw new ApiHttpException(409, 'duplicate_name', 'An item with this name already exists.');
        }
    }

    /** @return list<string> */
    private function actionsFor(array $diff): array
    {
        $actions = [];
        if (isset($diff['name'])) {
            $actions[] = CustomMasterDataChanged::UPDATED;
        }
        if (isset($diff['is_active'])) {
            $actions[] = $diff['is_active']['new'] ? CustomMasterDataChanged::REACTIVATED : CustomMasterDataChanged::DEACTIVATED;
        }

        return $actions;
    }
}
