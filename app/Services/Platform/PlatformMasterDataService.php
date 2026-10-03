<?php

namespace App\Services\Platform;

use App\Enums\BreedingStatus;
use App\Enums\BreedingWorkflow;
use App\Enums\Capability;
use App\Enums\OperationCategory;
use App\Models\Breed;
use App\Models\CropType;
use App\Models\CropVariety;
use App\Models\OperationType;
use App\Models\ReferenceValue;
use App\Models\Species;
use App\Models\SpeciesCapability;
use App\Models\User;
use App\Services\MasterData\SpeciesCapabilityService;
use App\Support\Api\ApiHttpException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Platform administration of the Phase 4 reference data (operation types, species, crop types, SYSTEM breeds/varieties, planting reference
 * lists) and species capability configuration. Stable `code`s, an operation type's category/tracking model and a species'/crop's parent are
 * immutable once created, nothing is ever deleted (use `is_active`), and farms' custom breeds/varieties are not reachable here. Capability
 * changes are checked against the data farms already hold (an active breeding project blocks disabling what it relies on); snapshots already
 * stored on breeding projects are immutable, so reference-value edits only affect projects created afterwards.
 */
class PlatformMasterDataService
{
    /** kind => [model class, parent column, parent class] */
    public const KINDS = [
        'operation-types' => [OperationType::class, null, null],
        'species' => [Species::class, 'operation_type_id', OperationType::class],
        'crop-types' => [CropType::class, 'operation_type_id', OperationType::class],
        'breeds' => [Breed::class, 'species_id', Species::class],
        'varieties' => [CropVariety::class, 'crop_type_id', CropType::class],
        'reference-values' => [ReferenceValue::class, null, null],
    ];

    public function __construct(private PlatformAudit $audit, private SpeciesCapabilityService $capabilities) {}

    /** @param  array<string, mixed>  $f */
    public function list(string $kind, array $f): LengthAwarePaginator
    {
        [$class, $parent] = self::KINDS[$kind];
        $query = $this->query($kind)
            ->when(isset($f['is_active']), fn (Builder $q) => $q->where('is_active', $f['is_active']))
            ->when(isset($f['q']), fn (Builder $q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.PlatformPlanService::escapeLike($f['q']).'%')->orWhere('code', 'like', '%'.PlatformPlanService::escapeLike($f['q']).'%')));
        if ($parent !== null && isset($f['parent_id'])) {
            $query->where($parent, $f['parent_id']);
        }
        if ($kind === 'operation-types' && isset($f['category'])) {
            $query->where('category', $f['category']);
        }
        if ($kind === 'reference-values' && isset($f['list'])) {
            $query->where('list', $f['list']);
        }
        $table = (new $class)->getTable();

        return $query->orderBy($table.'.'.(in_array($kind, ['breeds', 'varieties'], true) ? 'name' : 'sort_order'))->orderBy($table.'.code')->paginate($f['per_page'] ?? 50);
    }

    public function find(string $kind, string $id): Model
    {
        return $this->query($kind)->findOrFail($id);
    }

    /** @param  array<string, mixed>  $data */
    public function create(User $actor, string $kind, array $data): Model
    {
        [$class, $parentColumn, $parentClass] = self::KINDS[$kind];

        return $this->guardUnique(fn () => DB::transaction(function () use ($actor, $kind, $class, $parentColumn, $parentClass, $data) {
            if ($parentColumn !== null) {
                $parent = $parentClass::query()->lockForUpdate()->findOrFail($data[$parentColumn]);
                $this->assertParentFits($kind, $parent);
                if (($data['is_active'] ?? true) && ! $parent->is_active) {
                    throw new ApiHttpException(422, 'parent_inactive', 'The parent record is inactive; activate it first.');
                }
            }
            if ($kind === 'operation-types') {
                $data['tracking_model'] = OperationCategory::from($data['category'])->trackingModel();
            }
            if ($kind === 'species' && isset($data['livestock_group']) && $parent->category === OperationCategory::Aquaculture) {
                throw ValidationException::withMessages(['livestock_group' => 'Aquaculture species have no livestock group.']);
            }
            $record = $class::create($data);
            $this->audit->record($actor, 'platform.master_created', $this->type($kind), $record->id, $this->label($record), [], $this->facts($record));

            return $this->find($kind, $record->id);
        }));
    }

    /** Only presentation/activation fields change; identity (code), category, tracking model and parent are fixed. */
    public function update(User $actor, string $kind, string $id, array $data): Model
    {
        [$class] = self::KINDS[$kind];

        return $this->guardUnique(fn () => DB::transaction(function () use ($actor, $kind, $class, $id, $data) {
            $record = $class::query()->when(in_array($kind, ['breeds', 'varieties'], true), fn ($q) => $q->whereNull('farm_id'))->lockForUpdate()->findOrFail($id);
            $before = $this->facts($record);

            if (($data['is_active'] ?? null) === true && ! $record->is_active) {
                $this->assertParentActive($kind, $record);
            }
            if (($data['is_active'] ?? null) === false && $record->is_active && $kind === 'operation-types') {
                $children = Species::where('operation_type_id', $record->id)->where('is_active', true)->count() + CropType::where('operation_type_id', $record->id)->where('is_active', true)->count();
                if ($children > 0) {
                    throw new ApiHttpException(409, 'has_active_children', 'Deactivate this operation type\'s species and crop types first.', details: ['active_children' => $children]);
                }
            }
            if ($kind === 'species' && array_key_exists('livestock_group', $data) && $data['livestock_group'] !== null && $record->operationType->category === OperationCategory::Aquaculture) {
                throw ValidationException::withMessages(['livestock_group' => 'Aquaculture species have no livestock group.']);
            }

            $record->update($data);
            $this->audit->record($actor, 'platform.master_updated', $this->type($kind), $record->id, $this->label($record), $before, $this->facts($record));

            return $this->find($kind, $record->id);
        }));
    }

    // ---------------------------------------------------------------- capabilities

    /** @return list<array{code: string, label: string, config_schema: array<string, string>}> The only allowed shapes of `reference_config`. */
    public function capabilitySchemas(): array
    {
        return array_map(fn (Capability $c) => [
            'code' => $c->value, 'label' => $c->label(),
            'config_schema' => array_map(fn (array $rules) => implode('|', $rules), $c->configRules()),
        ], Capability::cases());
    }

    /** @return list<array{code: string, label: string, enabled: bool, reference_config: array<string, mixed>|null}> every capability, configured or not */
    public function speciesCapabilities(Species $species): array
    {
        $rows = SpeciesCapability::where('species_id', $species->id)->with('capability')->get()->keyBy(fn ($r) => $r->capability->code);

        return array_map(fn (Capability $c) => [
            'code' => $c->value, 'label' => $c->label(),
            'enabled' => $rows->get($c->value)?->enabled ?? false, 'reference_config' => $rows->get($c->value)?->reference_config,
        ], Capability::cases());
    }

    /** @param  array<string, mixed>|null  $config */
    public function setCapability(User $actor, Species $species, Capability $capability, bool $enabled, ?array $config): array
    {
        return DB::transaction(function () use ($actor, $species, $capability, $enabled, $config) {
            Species::whereKey($species->id)->lockForUpdate()->firstOrFail();
            $current = collect($this->speciesCapabilities($species))->keyBy('code');
            $before = ['enabled' => $current[$capability->value]['enabled'], 'reference_config' => $current[$capability->value]['reference_config']];

            $this->assertCapabilityCompatible($species, $capability, $enabled, $current->map(fn ($c) => $c['enabled'])->all());
            $this->capabilities->set($species, $capability, $enabled, $config);   // validates the config against the capability's allowed keys

            $after = ['enabled' => $enabled, 'reference_config' => $config === [] ? null : $config];
            $this->audit->record($actor, 'platform.species_capability_updated', 'species', $species->id, $species->code.':'.$capability->value, $before, $after);

            return $this->speciesCapabilities($species);
        });
    }

    /**
     * Schema compatibility: workflow capabilities only work together with supports_breeding, and a capability cannot be switched off
     * while an active breeding project of that species relies on it (completed/cancelled projects keep their immutable snapshot).
     *
     * @param  array<string, bool>  $enabledNow
     */
    private function assertCapabilityCompatible(Species $species, Capability $capability, bool $enabled, array $enabledNow): void
    {
        $workflows = array_map(fn (BreedingWorkflow $w) => $w->capability(), BreedingWorkflow::cases());
        $isWorkflow = in_array($capability, $workflows, true);

        if ($enabled && $isWorkflow && ! $enabledNow[Capability::SupportsBreeding->value]) {
            throw new ApiHttpException(422, 'capability_dependency', 'Enable supports_breeding before enabling '.$capability->value.'.');
        }
        if (! $enabled && $capability === Capability::SupportsBreeding) {
            $on = array_values(array_filter($workflows, fn (Capability $w) => $enabledNow[$w->value]));
            if ($on !== []) {
                throw new ApiHttpException(409, 'capability_dependency', 'Disable '.implode(' and ', array_map(fn ($c) => $c->value, $on)).' first.');
            }
        }
        if (! $enabled && ($isWorkflow || $capability === Capability::SupportsBreeding)) {
            $active = DB::table('breeding_projects as b')
                ->join('livestock_batch_details as l', 'l.production_cycle_id', '=', 'b.production_cycle_id')
                ->where('l.species_id', $species->id)->where('b.status', BreedingStatus::Active->value)
                ->when($isWorkflow, fn ($q) => $q->where('b.workflow', collect(BreedingWorkflow::cases())->first(fn ($w) => $w->capability() === $capability)->value))
                ->count();
            if ($active > 0) {
                throw new ApiHttpException(409, 'capability_in_use', 'Active breeding projects of this species rely on this capability.', details: ['active_breeding_projects' => $active]);
            }
        }
    }

    // ---------------------------------------------------------------- helpers

    private function query(string $kind): Builder
    {
        [$class] = self::KINDS[$kind];

        return $class::query()
            ->when(in_array($kind, ['breeds', 'varieties'], true), fn (Builder $q) => $q->whereNull('farm_id'))   // system rows only; a farm's custom rows are the farm's own
            ->when(in_array($kind, ['species', 'crop-types'], true), fn (Builder $q) => $q->with('operationType'))
            ->when($kind === 'breeds', fn (Builder $q) => $q->with('species'))
            ->when($kind === 'varieties', fn (Builder $q) => $q->with('cropType'));
    }

    private function assertParentFits(string $kind, Model $parent): void
    {
        $category = $parent instanceof OperationType ? $parent->category : null;
        $ok = match ($kind) {
            'species' => $category !== OperationCategory::Crop,
            'crop-types' => $category === OperationCategory::Crop,
            default => true,
        };
        if (! $ok) {
            throw ValidationException::withMessages(['operation_type_id' => $kind === 'species' ? 'Species belong to a livestock or aquaculture operation type.' : 'Crop types belong to a crop operation type.']);
        }
    }

    private function assertParentActive(string $kind, Model $record): void
    {
        $parent = match ($kind) {
            'species', 'crop-types' => $record->operationType,
            'breeds' => $record->species,
            'varieties' => $record->cropType,
            default => null,
        };
        if ($parent !== null && ! $parent->is_active) {
            throw new ApiHttpException(422, 'parent_inactive', 'The parent record is inactive; activate it first.');
        }
    }

    private function type(string $kind): string
    {
        return ['operation-types' => 'operation_type', 'species' => 'species', 'crop-types' => 'crop_type', 'breeds' => 'breed', 'varieties' => 'crop_variety', 'reference-values' => 'reference_value'][$kind];
    }

    private function label(Model $record): string
    {
        return ($record->code ?? $record->name).'';
    }

    /** @return array<string, mixed> */
    private function facts(Model $record): array
    {
        return array_intersect_key($record->getAttributes(), array_flip(['code', 'name', 'category', 'tracking_model', 'livestock_group', 'list', 'sort_order', 'is_active', 'operation_type_id', 'species_id', 'crop_type_id']));
    }

    private function guardUnique(callable $work): mixed
    {
        try {
            return $work();
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw ValidationException::withMessages(['name' => 'A record with this code or name already exists.']);
            }
            throw $e;
        }
    }
}
