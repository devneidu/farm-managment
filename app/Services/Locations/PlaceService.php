<?php

namespace App\Services\Locations;

use App\Enums\Permission;
use App\Enums\PlaceKind;
use App\Events\Locations\PlaceChanged;
use App\Models\Farm;
use App\Models\Location;
use App\Models\Place;
use App\Models\User;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlaceService
{
    // Root depth is zero. Includes a terminal production area/store if present.
    public const MAX_DEPTH = 7;

    public static function ancestryRelation(): string
    {
        return implode('.', array_fill(0, self::MAX_DEPTH, 'parent'));
    }

    public function find(Farm $farm, PlaceKind $kind, string $id): Place
    {
        return $kind->model()::ofFarm($farm)->with(self::ancestryRelation())->findOrFail($id);
    }

    /** Future operational modules must use this scoped gate when assigning a new activity. */
    public function selectable(Farm $farm, PlaceKind $kind, string $id): Place
    {
        $place = $this->find($farm, $kind, $id);
        for ($node = $place; $node !== null; $node = $node->parent) {
            if (! $node->is_active) {
                throw new ApiHttpException(409, 'location_inactive', 'Choose an active place with active ancestors.');
            }
        }

        return $place;
    }

    public function listing(Farm $farm, PlaceKind $kind, array $filters): LengthAwarePaginator
    {
        if (isset($filters['type']) && ! array_key_exists($filters['type'], $kind->types())) {
            throw ValidationException::withMessages(['type' => 'Choose a type from the catalogue for this resource kind.']);
        }
        if (isset($filters['parent_id'])) {
            Location::ofFarm($farm)->findOrFail($filters['parent_id']);
        }
        $query = $kind->model()::ofFarm($farm)->with(self::ancestryRelation());
        if (! ($filters['include_inactive'] ?? false)) {
            $query->where('is_active', true);
        }
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if ($filters['top_level'] ?? false) {
            $query->whereNull($kind->parentColumn());
        } elseif (isset($filters['parent_id'])) {
            $query->where($kind->parentColumn(), $filters['parent_id']);
        }
        if (isset($filters['search'])) {
            // Literal substring, not a caller-controlled LIKE wildcard pattern.
            $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower(Place::cleanName($filters['search'])));
            $query->whereRaw("normalized_name LIKE ? ESCAPE '!'", ['%'.$search.'%']);
        }

        return $query->orderBy('normalized_name')->orderBy('id')->paginate($filters['per_page'] ?? 50);
    }

    /** Every mutation takes the same farm lock, serializing moves, archive checks and sibling checks. */
    public function save(FarmContext $ctx, User $actor, PlaceKind $kind, array $data, ?string $id = null): Place
    {
        $ctx->authorize(Permission::LocationManage);
        try {
            $place = DB::transaction(function () use ($ctx, $actor, $kind, $data, $id) {
                Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
                $place = $id === null ? new ($kind->model()) : $kind->model()::ofFarm($ctx->farm)->lockForUpdate()->findOrFail($id);
                $before = $place->exists ? $this->snapshot($place) : [];
                $parentColumn = $kind->parentColumn();
                $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $place->$parentColumn;
                $parentId = $parentId === null ? null : Str::lower($parentId);
                $place->fill(array_intersect_key($data, array_flip(['name', 'type', 'is_active'])));
                $place->farm_id = $ctx->farm->id;
                $place->$parentColumn = $parentId;
                $place->is_active ??= true;
                $place->name = Place::cleanName($place->name);
                if ($place->name === '' || mb_strlen($place->name) > 100) {
                    throw ValidationException::withMessages(['name' => 'A name of 1–100 characters is required.']);
                }
                if (! array_key_exists($place->type, $kind->types())) {
                    throw ValidationException::withMessages(['type' => 'Choose a type from the catalogue for this resource kind.']);
                }

                $locations = Location::ofFarm($ctx->farm)->lockForUpdate()->get()->keyBy('id');
                if ($parentId !== null && ! $locations->has($parentId)) {
                    throw new ApiHttpException(404, 'not_found', 'Parent location not found.');
                }
                $depth = $this->validateAncestors($place, $locations, $parentId, $before);
                if ($kind === PlaceKind::Location && $place->exists) {
                    $this->validateDescendants($ctx->farm, $place, $locations, $depth);
                }
                if ($kind->model()::ofFarm($ctx->farm)->where($parentColumn, $parentId)
                    ->where('normalized_name', Str::lower($place->name))
                    ->when($id !== null, fn ($q) => $q->where('id', '!=', $place->id))->exists()) {
                    throw $this->duplicate();
                }

                $after = $this->snapshot($place);
                $changes = [];
                foreach ($after as $key => $value) {
                    if (! array_key_exists($key, $before) || $before[$key] !== $value) {
                        $changes[$key] = ['old' => $before[$key] ?? null, 'new' => $value];
                    }
                }
                if ($changes !== []) {
                    $place->save();
                    $action = $id === null ? 'created' : (isset($changes['is_active'])
                        ? ($place->is_active ? 'reactivated' : 'deactivated') : 'updated');
                    PlaceChanged::dispatch($action, $place, $actor, $changes);
                }

                return $place;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicate();
        }

        return $place->load(self::ancestryRelation());
    }

    private function validateAncestors(Place $place, Collection $locations, ?string $parentId, array $before): int
    {
        $depth = 0;
        $seen = $place->exists && $place->kind() === PlaceKind::Location ? [$place->id => true] : [];
        $requiresActive = $before === [] || $place->is_active || ($before['parent_id'] ?? null) !== $parentId;
        while ($parentId !== null) {
            if (isset($seen[$parentId])) {
                throw new ApiHttpException(409, 'location_cycle', 'A location cannot be its own ancestor.');
            }
            $seen[$parentId] = true;
            $parent = $locations->get($parentId);
            if ($requiresActive && ! $parent->is_active) {
                throw new ApiHttpException(409, 'location_inactive', 'Choose an active parent location with active ancestors.');
            }
            if (++$depth > self::MAX_DEPTH) {
                throw $this->depthError();
            }
            $parentId = $parent->parent_id;
        }

        return $depth;
    }

    private function validateDescendants(Farm $farm, Place $place, Collection $locations, int $depth): void
    {
        $levels = [$place->id => $depth];
        $frontier = [$place->id];
        while ($frontier !== []) {
            $next = [];
            foreach ($locations as $child) {
                if (! in_array($child->parent_id, $frontier, true)) {
                    continue;
                }
                if (isset($levels[$child->id])) {
                    throw new ApiHttpException(409, 'location_cycle', 'A location cannot be its own ancestor.');
                }
                if (! $place->is_active && $child->is_active) {
                    throw $this->activeChildren();
                }
                $levels[$child->id] = $levels[$child->parent_id] + 1;
                if ($levels[$child->id] > self::MAX_DEPTH) {
                    throw $this->depthError();
                }
                $next[] = $child->id;
            }
            $frontier = $next;
        }
        foreach ([PlaceKind::ProductionArea, PlaceKind::StorageLocation] as $kind) {
            foreach ($kind->model()::ofFarm($farm)->whereIn('location_id', array_keys($levels))->lockForUpdate()->get() as $child) {
                if (! $place->is_active && $child->is_active) {
                    throw $this->activeChildren();
                }
                if ($levels[$child->location_id] + 1 > self::MAX_DEPTH) {
                    throw $this->depthError();
                }
            }
        }
    }

    private function snapshot(Place $place): array
    {
        return ['name' => $place->name, 'type' => $place->type, 'parent_id' => $place->{$place->kind()->parentColumn()}, 'is_active' => $place->is_active];
    }

    private function duplicate(): ApiHttpException
    {
        return new ApiHttpException(409, 'duplicate_location', 'This kind of place already has that name under this parent, including inactive places.');
    }

    private function depthError(): ApiHttpException
    {
        return new ApiHttpException(409, 'location_depth_exceeded', 'Places may have at most seven ancestors.');
    }

    private function activeChildren(): ApiHttpException
    {
        return new ApiHttpException(409, 'location_has_active_children', 'Deactivate or move active descendants before deactivating this location.');
    }
}
