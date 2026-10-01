<?php

namespace App\Services\Production;

use App\Enums\CycleKind;
use App\Enums\CycleStatus;
use App\Enums\Limit;
use App\Enums\Permission;
use App\Enums\PlaceKind;
use App\Enums\TrackingModel;
use App\Events\Production\CycleChanged;
use App\Http\Requests\Production\CloseCycleRequest;
use App\Http\Requests\Production\ReopenCycleRequest;
use App\Http\Requests\Production\StoreCycleRequest;
use App\Http\Requests\Production\UpdateCycleRequest;
use App\Models\Breed;
use App\Models\CropType;
use App\Models\CropVariety;
use App\Models\Farm;
use App\Models\OperationalRecord;
use App\Models\OperationType;
use App\Models\Place;
use App\Models\PopulationMovement;
use App\Models\ProductionCycle;
use App\Models\ProductionCycleEvent;
use App\Models\ReferenceValue;
use App\Models\Species;
use App\Models\Unit;
use App\Models\User;
use App\Services\Locations\PlaceService;
use App\Services\Measurement\QuantityNormalizer;
use App\Services\Records\PopulationLedger;
use App\Services\Subscription\EntitlementService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CycleService
{
    public const BASELINE_FIELDS = ['kind', 'operation_type_id', 'species_id', 'breed_id', 'initial_population', 'start_date', 'crop_type_id', 'crop_variety_id', 'planting_material_type', 'planting_unit_type', 'initial_planting_units', 'planting_date'];

    public function __construct(private readonly QuantityNormalizer $quantities, private readonly PlaceService $places, private readonly EntitlementService $entitlements) {}

    public static function relations(): array
    {
        return ['operation', 'productionArea.'.PlaceService::ancestryRelation(), 'livestock.species', 'livestock.breed', 'crop.cropType', 'crop.variety', 'crop.materialType', 'crop.unitType'];
    }

    public function find(Farm $farm, string $id): ProductionCycle
    {
        return ProductionCycle::ofFarm($farm)->with(self::relations())->withSum('movements as current_population', 'quantity')->findOrFail($id);
    }

    public function listing(Farm $farm, array $f): LengthAwarePaginator
    {
        if (isset($f['production_area_id'])) {
            $this->places->find($farm, PlaceKind::ProductionArea, $f['production_area_id']);
        }
        $q = ProductionCycle::ofFarm($farm)->with(self::relations())->withSum('movements as current_population', 'quantity');
        foreach (['kind', 'status', 'operation_type_id', 'production_area_id'] as $key) {
            if (isset($f[$key])) {
                $q->where($key, $f[$key]);
            }
        }
        if (isset($f['species_id'])) {
            $q->whereHas('livestock', fn ($q) => $q->where('species_id', $f['species_id']));
        }
        if (isset($f['crop_type_id'])) {
            $q->whereHas('crop', fn ($q) => $q->where('crop_type_id', $f['crop_type_id']));
        }
        if (isset($f['search'])) {
            $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower(Place::cleanName($f['search']))).'%';
            $q->where(fn ($q) => $q->whereRaw("normalized_name LIKE ? ESCAPE '!'", [$search])->orWhereRaw("LOWER(reference) LIKE ? ESCAPE '!'", [$search]));
        }

        return $q->orderByDesc('start_date')->orderBy('id')->paginate($f['per_page'] ?? 50);
    }

    public function assertNoBaselineInput(array $data): void
    {
        if (array_intersect(array_keys($data), self::BASELINE_FIELDS)) {
            throw new ApiHttpException(409, 'baseline_locked', 'Starting identity and baseline are immutable. Future population corrections require an adjustment event.');
        }
    }

    public function create(FarmContext $ctx, User $actor, array $data): ProductionCycle
    {
        $ctx->authorize(Permission::ProductionCycleCreate);
        $data = Validator::make($data, (new StoreCycleRequest)->rules())->validate();
        try {
            $id = DB::transaction(function () use ($ctx, $actor, $data) {
                Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
                $this->entitlements->assertCapacity($ctx->farm, Limit::ActiveCycles);
                $kind = CycleKind::from($data['kind']);
                $operation = OperationType::lockForUpdate()->findOrFail($data['operation_type_id']);
                $this->active($operation, 'operation_type_id');
                $expectedTracking = $kind === CycleKind::Livestock ? TrackingModel::Population : TrackingModel::PlantingUnits;
                if ($operation->tracking_model !== $expectedTracking) {
                    $this->invalid('operation_type_id', 'The operation uses a different tracking model.');
                }
                $start = $data[$kind === CycleKind::Livestock ? 'start_date' : 'planting_date'];
                $this->dates($start, $data);
                $areaId = $this->area($ctx->farm, $data['production_area_id'] ?? null);
                $name = $this->name($data['name']);
                $number = ProductionCycle::ofFarm($ctx->farm)->count() + 1;
                $cycle = ProductionCycle::create([
                    'farm_id' => $ctx->farm->id, 'kind' => $kind, 'name' => $name, 'normalized_name' => Str::lower($name),
                    'reference' => ($kind === CycleKind::Livestock ? 'BAT' : 'CRP').'-'.now()->format('Y').'-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
                    'operation_type_id' => $operation->id, 'production_area_id' => $areaId, 'status' => CycleStatus::Active,
                    'start_date' => $start, 'expected_end_date' => $data['expected_end_date'] ?? null, 'notes' => $data['notes'] ?? null, 'created_by' => $actor->id,
                ]);
                if ($kind === CycleKind::Livestock) {
                    $this->createLivestock($ctx->farm, $cycle, $data, $actor);
                } else {
                    $this->createCrop($ctx->farm, $cycle, $data);
                }
                $cycle = $this->find($ctx->farm, $cycle->id);
                $this->record($cycle, $actor, 'created', [], $this->snapshot($cycle), null, CarbonImmutable::parse($start, $ctx->farm->timezone)->startOfDay()->utc());

                return $cycle->id;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'cycles_farm_kind_name_unique')) {
                throw new ApiHttpException(409, 'duplicate_cycle_name', 'This farm already has a cycle of this kind with that name, including closed cycles.');
            }
            throw $e;
        }

        return $this->find($ctx->farm, $id);
    }

    private function createLivestock(Farm $farm, ProductionCycle $cycle, array $data, User $actor): void
    {
        $species = Species::lockForUpdate()->findOrFail($data['species_id']);
        $this->active($species, 'species_id');
        if ($species->operation_type_id !== $cycle->operation_type_id) {
            $this->invalid('species_id', 'Species does not belong to this operation.');
        }
        $breed = isset($data['breed_id']) ? Breed::visibleTo($farm)->lockForUpdate()->findOrFail($data['breed_id']) : null;
        if ($breed) {
            $this->active($breed, 'breed_id');
            if ($breed->species_id !== $species->id) {
                $this->invalid('breed_id', 'Breed does not belong to this species.');
            }
        }
        $measurement = $this->quantities->normalize($farm, [['quantity' => $data['initial_population'], 'unit' => 'head']], requiredDimensions: ['count'])->toArray();
        $cycle->livestock()->create(['species_id' => $species->id, 'breed_id' => $breed?->id, 'initial_population' => $data['initial_population'], 'baseline_measurement' => $measurement]);
        PopulationMovement::create([
            'farm_id' => $farm->id, 'production_cycle_id' => $cycle->id, 'type' => 'initial', 'source_key' => 'initial',
            'quantity' => $data['initial_population'], 'recorded_at' => CarbonImmutable::parse($cycle->start_date->toDateString(), $farm->timezone)->startOfDay()->utc(), 'created_by' => $actor->id,
        ]);
    }

    private function createCrop(Farm $farm, ProductionCycle $cycle, array $data): void
    {
        $crop = CropType::lockForUpdate()->findOrFail($data['crop_type_id']);
        $this->active($crop, 'crop_type_id');
        if ($crop->operation_type_id !== $cycle->operation_type_id) {
            $this->invalid('crop_type_id', 'Crop does not belong to this operation.');
        }
        $variety = isset($data['crop_variety_id']) ? CropVariety::visibleTo($farm)->lockForUpdate()->findOrFail($data['crop_variety_id']) : null;
        if ($variety) {
            $this->active($variety, 'crop_variety_id');
            if ($variety->crop_type_id !== $crop->id) {
                $this->invalid('crop_variety_id', 'Variety does not belong to this crop.');
            }
        }
        $material = $this->reference(ReferenceValue::PLANTING_MATERIAL_TYPE, $data['planting_material_type'], 'planting_material_type');
        $unit = $this->reference(ReferenceValue::PLANTING_UNIT_TYPE, $data['planting_unit_type'], 'planting_unit_type');
        $measurement = $this->quantities->normalize($farm, [['quantity' => $data['initial_planting_units'], 'unit' => 'planting_unit']], requiredDimensions: ['count'])->toArray();
        $cycle->crop()->create(array_merge([
            'crop_type_id' => $crop->id, 'crop_variety_id' => $variety?->id,
            'planting_material_type_id' => $material->id, 'planting_unit_type_id' => $unit->id,
            'initial_planting_units' => $data['initial_planting_units'], 'baseline_measurement' => $measurement,
            'expected_germination_date' => $data['expected_germination_date'] ?? null,
        ], $this->areaMeasurement($farm, $data['area'] ?? null)));
    }

    public function update(FarmContext $ctx, User $actor, string $id, array $data): ProductionCycle
    {
        $ctx->authorize(Permission::ProductionCycleUpdate);
        $this->assertNoBaselineInput($data);
        $data = Validator::make($data, (new UpdateCycleRequest)->rules())->validate();
        try {
            DB::transaction(function () use ($ctx, $actor, $id, $data) {
                Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
                $cycle = ProductionCycle::ofFarm($ctx->farm)->lockForUpdate()->findOrFail($id);
                if ($cycle->status !== CycleStatus::Active) {
                    throw new ApiHttpException(409, 'cycle_closed', 'Reopen the cycle before making ordinary edits.');
                }
                $cycle->load(self::relations());
                $before = $this->snapshot($cycle);
                if ($cycle->kind === CycleKind::Livestock && (array_key_exists('area', $data) || array_key_exists('expected_germination_date', $data))) {
                    $this->invalid('area', 'Area and germination date belong only to crop projects.');
                }
                $this->dates($cycle->start_date->toDateString(), $data);
                if (array_key_exists('name', $data)) {
                    $cycle->name = $this->name($data['name']);
                    $cycle->normalized_name = Str::lower($cycle->name);
                }
                foreach (['notes', 'expected_end_date'] as $key) {
                    if (array_key_exists($key, $data)) {
                        $cycle->$key = $data[$key];
                    }
                }
                if (array_key_exists('production_area_id', $data) && strtolower($data['production_area_id'] ?? '') !== ($cycle->production_area_id ?? '')) {
                    $cycle->production_area_id = $this->area($ctx->farm, $data['production_area_id']);
                }
                if ($cycle->crop) {
                    if (array_key_exists('area', $data)) {
                        $cycle->crop->fill($this->areaMeasurement($ctx->farm, $data['area']));
                    }
                    if (array_key_exists('expected_germination_date', $data)) {
                        $cycle->crop->expected_germination_date = $data['expected_germination_date'];
                    }
                    if ($cycle->crop->isDirty()) {
                        $cycle->crop->save();
                        $cycle->touch();
                    }
                }
                if ($cycle->isDirty()) {
                    $cycle->save();
                }
                $this->record($cycle, $actor, 'updated', $before, $this->snapshot($cycle));
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'cycles_farm_kind_name_unique')) {
                throw new ApiHttpException(409, 'duplicate_cycle_name', 'This name is already used for this kind of cycle.');
            }
            throw $e;
        }

        return $this->find($ctx->farm, $id);
    }

    public function transition(FarmContext $ctx, User $actor, string $id, bool $close, array $data): ProductionCycle
    {
        $ctx->authorize($close ? Permission::ProductionCycleClose : Permission::ProductionCycleReopen);
        $data = Validator::make($data, ($close ? new CloseCycleRequest : new ReopenCycleRequest)->rules())->validate();
        DB::transaction(function () use ($ctx, $actor, $id, $close, $data) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $cycle = ProductionCycle::ofFarm($ctx->farm)->lockForUpdate()->findOrFail($id);
            if (($cycle->status === CycleStatus::Closed) === $close) {
                throw new ApiHttpException(409, 'invalid_status_transition', 'The cycle is already in the requested state.');
            }
            $cycle->load(self::relations());
            $before = $this->snapshot($cycle);
            if ($close) {
                if ($data['end_date'] < $cycle->start_date->toDateString()) {
                    $this->invalid('end_date', 'Completion date must not precede the start/planting date.');
                }
                if ($data['end_date'] > now($ctx->farm->timezone)->toDateString()) {
                    $this->invalid('end_date', 'Actual completion date cannot be in the future.');
                }
                $this->reconcile($cycle);
                $endExclusive = CarbonImmutable::parse($data['end_date'], $ctx->farm->timezone)->addDay()->startOfDay()->utc();
                if (OperationalRecord::where('production_cycle_id', $cycle->id)->where('recorded_at', '>=', $endExclusive)->exists()) {
                    $this->invalid('end_date', 'Completion cannot precede recorded operational events.');
                }
            } else {
                $this->entitlements->assertCapacity($ctx->farm, Limit::ActiveCycles);
            }
            $cycle->status = $close ? CycleStatus::Closed : CycleStatus::Active;
            $cycle->end_date = $close ? $data['end_date'] : null;
            $cycle->save();
            $this->record($cycle, $actor, $close ? 'closed' : 'reopened', $before, $this->snapshot($cycle), $data['reason']);
        }, 3);

        return $this->find($ctx->farm, $id);
    }

    /** Shared ledger reconciliation under the farm/cycle locks, including operational effects. */
    public function reconcile(ProductionCycle $cycle): void
    {
        app(PopulationLedger::class)->reconcile($cycle);
        if ($cycle->kind === CycleKind::Crop && (! $cycle->crop || $cycle->crop->initial_planting_units < 1)) {
            throw new ApiHttpException(409, 'cycle_reconciliation_failed', 'The crop starting baseline is missing or invalid.');
        }
    }

    private function record(ProductionCycle $cycle, User $actor, string $action, array $before, array $after, ?string $reason = null, mixed $recordedAt = null): void
    {
        $changes = [];
        foreach ($after as $key => $value) {
            if (! array_key_exists($key, $before) || $before[$key] !== $value) {
                $changes[$key] = ['old' => $before[$key] ?? null, 'new' => $value];
            }
        }
        if ($changes === []) {
            return;
        }
        $event = ProductionCycleEvent::create(['farm_id' => $cycle->farm_id, 'production_cycle_id' => $cycle->id, 'action' => $action, 'actor_id' => $actor->id, 'changes' => $changes, 'reason' => $reason, 'recorded_at' => $recordedAt ?? now()]);
        CycleChanged::dispatch($event);
    }

    private function snapshot(ProductionCycle $cycle): array
    {
        return array_merge($cycle->only(['name', 'production_area_id', 'notes']), [
            'kind' => $cycle->kind->value, 'status' => $cycle->status->value, 'reference' => $cycle->reference,
            'operation_type_id' => $cycle->operation_type_id, 'start_date' => $cycle->start_date->toDateString(),
            'expected_end_date' => $cycle->expected_end_date?->toDateString(), 'end_date' => $cycle->end_date?->toDateString(),
            'livestock' => $cycle->livestock?->attributesToArray(), 'crop' => $cycle->crop?->attributesToArray(),
        ]);
    }

    private function areaMeasurement(Farm $farm, ?array $area): array
    {
        if ($area === null) {
            return ['area_measurement' => null, 'area_normalized_quantity' => null, 'area_normalized_unit_id' => null];
        }
        $result = $this->quantities->normalize($farm, [$area], requiredDimensions: ['area']);

        return ['area_measurement' => $result->toArray(), 'area_normalized_quantity' => $result->normalized->value, 'area_normalized_unit_id' => Unit::where('code', $result->normalized->unit->code)->firstOrFail()->id];
    }

    private function area(Farm $farm, ?string $id): ?string
    {
        return $id === null ? null : $this->places->selectable($farm, PlaceKind::ProductionArea, $id)->id;
    }

    private function reference(string $list, string $code, string $field): ReferenceValue
    {
        $row = ReferenceValue::where('list', $list)->where('code', $code)->where('is_active', true)->lockForUpdate()->first();
        if (! $row) {
            $this->invalid($field, 'Choose an active code from the planting reference catalogue.');
        }

        return $row;
    }

    private function active($row, string $field): void
    {
        if (! $row->is_active) {
            $this->invalid($field, 'Choose an active catalogue entry.');
        }
    }

    private function name(string $name): string
    {
        $name = Place::cleanName($name);
        if ($name === '' || mb_strlen($name) > 100) {
            $this->invalid('name', 'A name of 1–100 characters is required.');
        }

        return $name;
    }

    private function dates(string $start, array $data): void
    {
        foreach (['expected_end_date', 'expected_germination_date'] as $key) {
            if (isset($data[$key]) && $data[$key] < $start) {
                $this->invalid($key, 'The date must not precede the start/planting date.');
            }
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
