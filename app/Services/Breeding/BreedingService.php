<?php

namespace App\Services\Breeding;

use App\Enums\BreedingStatus;
use App\Enums\BreedingWorkflow;
use App\Enums\CycleKind;
use App\Enums\CycleStatus;
use App\Enums\Permission;
use App\Events\Breeding\BreedingOutcomeRecorded;
use App\Http\Requests\Breeding\CancelBreedingProjectRequest;
use App\Http\Requests\Breeding\ListBreedingProjectsRequest;
use App\Http\Requests\Breeding\ReverseBreedingOutcomeRequest;
use App\Http\Requests\Breeding\StoreBreedingCheckRequest;
use App\Http\Requests\Breeding\StoreBreedingOutcomeRequest;
use App\Http\Requests\Breeding\StoreBreedingProjectRequest;
use App\Http\Requests\Breeding\UpdateBreedingProjectRequest;
use App\Models\BreedingCheck;
use App\Models\BreedingOutcome;
use App\Models\BreedingParent;
use App\Models\BreedingProject;
use App\Models\Farm;
use App\Models\InventoryItem;
use App\Models\OperationalRecord;
use App\Models\ProductionCycle;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\OutputStockService;
use App\Services\Inventory\StockReasonCatalogue;
use App\Services\Records\RecordService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use App\Support\Idempotency\RequestHash;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of breeding data. A project keeps three things apart: the biological reference (immutable snapshot),
 * the expectation (derived or manual, estimates only) and the actual outcome. Population changes ONLY when a confirmed
 * outcome with live offspring is recorded, through one Phase 8 operational record + population movement in the same
 * transaction; expected quantities, eggs set and failed outcomes never touch it. Lock order matches Phases 7-10:
 * farm row, cycle row, then the project row.
 */
class BreedingService
{
    private const RELATIONS = ['parents', 'checks', 'outcomes.reversal', 'stockMovements'];

    public function __construct(private BreedingReference $reference, private RecordService $records, private InventoryService $inventory, private OutputStockService $outputs) {}

    public function find(FarmContext $ctx, string $id): BreedingProject
    {
        $ctx->authorize(Permission::BreedingView);

        return BreedingProject::where('farm_id', $ctx->farm->id)->with(self::RELATIONS)->findOrFail($id);
    }

    public function list(FarmContext $ctx, array $input): LengthAwarePaginator
    {
        $ctx->authorize(Permission::BreedingView);
        $f = Validator::make($input, (new ListBreedingProjectsRequest)->rules())->validate();
        $q = BreedingProject::where('farm_id', $ctx->farm->id)->with(self::RELATIONS);
        if (isset($f['production_cycle_id'])) {
            ProductionCycle::ofFarm($ctx->farm)->findOrFail($f['production_cycle_id']);
            $q->where('production_cycle_id', $f['production_cycle_id']);
        }
        foreach (['workflow', 'status'] as $filter) {
            if (isset($f[$filter])) {
                $q->where($filter, $f[$filter]);
            }
        }

        return $q->orderByDesc('start_date')->orderByDesc('id')->paginate($f['per_page'] ?? 50);
    }

    public function create(FarmContext $ctx, array $input): BreedingProject
    {
        $ctx->authorize(Permission::BreedingCreate);
        $data = Validator::make($input, (new StoreBreedingProjectRequest)->rules())->validate();
        $workflow = BreedingWorkflow::from($data['workflow']);
        if (! empty($data['consume_egg_stock'])) {
            $ctx->authorize(Permission::InventoryUse); // the eggs leave stock: no bypass of inventory permissions through the breeding endpoint
        }

        return DB::transaction(function () use ($ctx, $data, $workflow) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $hash = RequestHash::of($data);
            $replay = BreedingProject::where('farm_id', $ctx->farm->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($replay) {
                $this->assertSameRequest($replay->request_hash, $hash);

                return $replay->load(self::RELATIONS);
            }
            $cycle = ProductionCycle::ofFarm($ctx->farm)->with('livestock.species')->lockForUpdate()->findOrFail($data['production_cycle_id']);
            $this->activeCycle($cycle);
            if ($cycle->kind !== CycleKind::Livestock) {
                $this->invalid('production_cycle_id', 'Breeding applies to livestock cycles only.');
            }
            $row = $this->reference->capabilityRow($cycle, $workflow);
            if (! $row) {
                $this->invalid('workflow', 'This species does not support the '.$workflow->value.' breeding workflow.');
            }
            $this->assertWorkflowFields($workflow, $data, creating: true);
            if ((! empty($data['consume_egg_stock']) || isset($data['egg_storage_location_id'])) && $workflow !== BreedingWorkflow::Incubation) {
                $this->invalid('consume_egg_stock', 'Only an incubation project takes eggs from stock.');
            }
            if (isset($data['egg_storage_location_id']) && empty($data['consume_egg_stock'])) {
                $this->invalid('egg_storage_location_id', 'Choose a storage location only together with consume_egg_stock.');
            }
            $this->assertStart($ctx, $cycle, $data['start_date']);
            $snapshot = $this->reference->snapshot($cycle, $workflow, $row, CarbonImmutable::now());
            $expectation = $this->expectation($snapshot, $data['start_date'], $data);
            $parents = $this->parents($ctx, $cycle, $data['parents'] ?? []);
            $number = BreedingProject::where('farm_id', $ctx->farm->id)->count() + 1;
            $project = BreedingProject::create([
                'farm_id' => $ctx->farm->id, 'production_cycle_id' => $cycle->id, 'reference' => 'BRD-'.now()->format('Y').'-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
                'workflow' => $workflow, 'status' => BreedingStatus::Active, 'start_date' => $data['start_date'],
                'eggs_set' => $data['eggs_set'] ?? null, 'females_bred' => $data['females_bred'] ?? null, 'expected_offspring' => $data['expected_offspring'] ?? null,
                'reference_snapshot' => $snapshot, 'expectation_source' => $expectation['source'],
                'expected_date' => $expectation['date'], 'expected_from' => $expectation['from'], 'expected_to' => $expectation['to'],
                'notes' => $data['notes'] ?? null, 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash, 'created_by' => $ctx->membership->user_id,
            ]);
            foreach ($parents as $parent) {
                BreedingParent::create(['farm_id' => $ctx->farm->id, 'breeding_project_id' => $project->id] + $parent);
            }
            if (! empty($data['consume_egg_stock'])) {
                // One real-world event (eggs put into the incubator) -> the project AND the stock-out, atomically; too few eggs rolls both back.
                $this->takeEggs($ctx, $project, $cycle, (int) $data['eggs_set'], $data['egg_storage_location_id'] ?? null, $this->setAt($ctx, $data['start_date']));
            }

            return $project->load(self::RELATIONS);
        }, 3);
    }

    /** Edits are allowed only while the project is active; the reference snapshot, workflow and cycle never change. */
    public function update(FarmContext $ctx, string $id, array $input): BreedingProject
    {
        $ctx->authorize(Permission::BreedingCreate);
        $data = Validator::make($input, (new UpdateBreedingProjectRequest)->rules())->validate();

        return DB::transaction(function () use ($ctx, $id, $data) {
            [$cycle, $project] = $this->lockProject($ctx, $id);
            $this->activeCycle($cycle);
            $this->activeProject($project);
            $workflow = $project->workflow;
            $this->assertWorkflowFields($workflow, $data);
            $start = $data['start_date'] ?? $project->start_date->toDateString();
            if (isset($data['start_date'])) {
                $this->assertStart($ctx, $cycle, $start);
                if ($project->checks()->where('checked_on', '<', $start)->exists()) {
                    $this->invalid('start_date', 'The start cannot be after an existing check.');
                }
            }
            $eggs = $data['eggs_set'] ?? $project->eggs_set;
            if ($eggs !== null && $project->checks()->where('fertile_count', '>', $eggs)->exists()) {
                $this->invalid('eggs_set', 'Fewer eggs than a recorded fertile count.');
            }
            $manual = isset($data['expected_date']) || isset($data['expected_from']);
            $changed = [];
            if ($manual || ! empty($data['revert_to_reference'])) {
                $e = $this->expectation($project->reference_snapshot, $start, $data);
                $changed = ['expectation_source' => $e['source'], 'expected_date' => $e['date'], 'expected_from' => $e['from'], 'expected_to' => $e['to']];
            } elseif (isset($data['start_date']) && $project->expectation_source !== 'manual') {
                $e = $this->reference->expectation($project->reference_snapshot, $start);
                $changed = ['expectation_source' => $e['source'], 'expected_date' => $e['date'], 'expected_from' => $e['from'], 'expected_to' => $e['to']];
            } elseif (isset($data['start_date'])) {
                $this->assertManualAfterStart($start, $project->expected_date?->toDateString() ?? $project->expected_from?->toDateString());
            }
            foreach (['start_date', 'eggs_set', 'females_bred', 'expected_offspring', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $changed[$field] = $data[$field];
                }
            }
            $this->reconcileEggStock($ctx, $project, $cycle, $data);
            $project->update($changed);

            return $project->load(self::RELATIONS);
        }, 3);
    }

    public function addCheck(FarmContext $ctx, string $id, array $input): BreedingProject
    {
        $ctx->authorize(Permission::BreedingCreate);
        $data = Validator::make($input, (new StoreBreedingCheckRequest)->rules())->validate();

        return DB::transaction(function () use ($ctx, $id, $data) {
            [$cycle, $project] = $this->lockProject($ctx, $id);
            $this->activeCycle($cycle);
            $this->activeProject($project);
            if (($data['fertile_count'] ?? null) !== null) {
                if ($project->workflow !== BreedingWorkflow::Incubation) {
                    $this->invalid('fertile_count', 'Fertile counts apply to incubation only.');
                }
                if ($data['fertile_count'] > $project->eggs_set) {
                    $this->invalid('fertile_count', 'More fertile eggs than eggs set.');
                }
            }
            $today = CarbonImmutable::now($ctx->farm->timezone)->toDateString();
            if ($data['checked_on'] < $project->start_date->toDateString() || $data['checked_on'] > $today) {
                $this->invalid('checked_on', 'A check must fall between the start date and today.');
            }
            BreedingCheck::create(['farm_id' => $ctx->farm->id, 'breeding_project_id' => $project->id, 'checked_on' => $data['checked_on'], 'result' => $data['result'],
                'fertile_count' => $data['fertile_count'] ?? null, 'notes' => $data['notes'] ?? null, 'created_by' => $ctx->membership->user_id]);

            return $project->load(self::RELATIONS);
        }, 3);
    }

    public function cancel(FarmContext $ctx, string $id, array $input): BreedingProject
    {
        $ctx->authorize(Permission::BreedingCreate);
        $data = Validator::make($input, (new CancelBreedingProjectRequest)->rules())->validate();

        return DB::transaction(function () use ($ctx, $id, $data) {
            [$cycle, $project] = $this->lockProject($ctx, $id);
            $this->activeCycle($cycle);
            $this->activeProject($project);
            // Eggs are never assumed to be usable again: only an explicit quantity goes back to available stock.
            $this->returnEggs($ctx, $project, $cycle, $data);
            $project->update(['status' => BreedingStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => $data['reason']]);

            return $project->load(self::RELATIONS);
        }, 3);
    }

    /** Records the actual outcome. Live offspring join the project's cycle population automatically (Phase 8 ledger), exactly once per key; nothing else changes population. */
    public function recordOutcome(FarmContext $ctx, string $id, array $input): BreedingOutcome
    {
        $ctx->authorize(Permission::BreedingCreate);
        $data = Validator::make($input, (new StoreBreedingOutcomeRequest)->rules())->validate();
        if (! empty($data['corrects_outcome_id'])) {
            $ctx->authorize(Permission::BreedingReverse);
        }

        return DB::transaction(function () use ($ctx, $id, $data) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $hash = RequestHash::of(['project' => $id] + $data);
            if ($replay = $this->replay($ctx, $data['idempotency_key'], $hash)) {
                return $replay;
            }
            [$cycle, $project] = $this->lockProject($ctx, $id, lockFarm: false);
            $this->activeCycle($cycle);
            if ($project->status !== BreedingStatus::Active) {
                throw new ApiHttpException(409, 'project_not_active', 'Only an active breeding project can receive an outcome; reverse the current outcome first to correct it.');
            }
            $live = (int) $data['live_count'];
            $loss = (int) ($data['loss_count'] ?? 0);
            if ($project->workflow === BreedingWorkflow::Incubation && $live + $loss > $project->eggs_set) {
                $this->invalid('live_count', 'Hatched plus lost cannot exceed the eggs set.');
            }
            $when = $this->when($ctx, $project, $data['recorded_at']);
            $this->assertCorrection($ctx, $project, $data['corrects_outcome_id'] ?? null);
            $outcome = BreedingOutcome::create([
                'farm_id' => $ctx->farm->id, 'breeding_project_id' => $project->id, 'production_cycle_id' => $cycle->id, 'kind' => BreedingOutcome::OUTCOME,
                'live_count' => $live, 'loss_count' => $data['loss_count'] ?? null, 'outcome_date' => $when->setTimezone($ctx->farm->timezone)->toDateString(),
                'corrects_outcome_id' => $data['corrects_outcome_id'] ?? null, 'recorded_at' => $when,
                'notes' => $data['notes'] ?? null, 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
                'created_by' => $ctx->membership->user_id, 'request_id' => request()->attributes->get('request_id'),
            ]);
            if ($live > 0) {
                $record = $this->records->appendForBreeding($ctx, $cycle, ['breeding_project_id' => $project->id, 'breeding_outcome_id' => $outcome->id, 'reference' => $project->reference, 'workflow' => $project->workflow->value],
                    $live, $when, 'breeding:'.$outcome->id, $hash);
                DB::table('breeding_outcomes')->where('id', $outcome->id)->update(['operational_record_id' => $record->id]);
            }
            $project->update(['status' => BreedingStatus::Completed]);
            BreedingOutcomeRecorded::dispatch($outcome);

            return $this->outcomeFresh($outcome);
        }, 3);
    }

    /** Appends a reversal row (and the compensating population record); the project becomes active again so a replacement can be recorded. */
    public function reverseOutcome(FarmContext $ctx, string $projectId, string $outcomeId, array $input): BreedingOutcome
    {
        $ctx->authorize(Permission::BreedingReverse);
        $data = Validator::make($input, (new ReverseBreedingOutcomeRequest)->rules())->validate();

        return DB::transaction(function () use ($ctx, $projectId, $outcomeId, $data) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $original = BreedingOutcome::where('farm_id', $ctx->farm->id)->where('breeding_project_id', $projectId)->findOrFail($outcomeId);
            $hash = RequestHash::of(['reverse' => $outcomeId] + $data);
            if ($replay = $this->replay($ctx, $data['idempotency_key'], $hash)) {
                return $replay;
            }
            [$cycle, $project] = $this->lockProject($ctx, $projectId, lockFarm: false);
            $this->activeCycle($cycle);
            if ($original->kind === BreedingOutcome::REVERSAL || $original->reversal()->exists()) {
                throw new ApiHttpException(409, 'outcome_already_reversed', 'This outcome cannot be reversed again.');
            }
            $when = CarbonImmutable::parse($data['recorded_at'])->utc();
            if ($when->isFuture() || $when->lessThan($original->recorded_at)) {
                $this->invalid('recorded_at', 'A reversal must be between the original event and now.');
            }
            $reversal = BreedingOutcome::create([
                'farm_id' => $ctx->farm->id, 'breeding_project_id' => $project->id, 'production_cycle_id' => $cycle->id, 'kind' => BreedingOutcome::REVERSAL,
                'reverses_outcome_id' => $original->id, 'reason' => $data['reason'], 'recorded_at' => $when, 'idempotency_key' => $data['idempotency_key'],
                'request_hash' => $hash, 'created_by' => $ctx->membership->user_id, 'request_id' => request()->attributes->get('request_id'),
            ]);
            if ($original->operational_record_id !== null) {
                $originalRecord = OperationalRecord::where('farm_id', $ctx->farm->id)->findOrFail($original->operational_record_id);
                $record = $this->records->appendForBreeding($ctx, $cycle, ['reason' => $data['reason'], 'breeding_outcome_id' => $reversal->id], -$originalRecord->population_delta, $when, 'breeding:'.$reversal->id, $hash, $originalRecord);
                DB::table('breeding_outcomes')->where('id', $reversal->id)->update(['operational_record_id' => $record->id]);
            }
            if ($project->status === BreedingStatus::Completed) {
                $project->update(['status' => BreedingStatus::Active]);
            }
            BreedingOutcomeRecorded::dispatch($reversal);

            return $this->outcomeFresh($reversal);
        }, 3);
    }

    /** Derived view of the project timeline: start, expected outcome (exact date or window), checks and the actual outcome. */
    public function milestones(FarmContext $ctx, string $id): array
    {
        $project = $this->find($ctx, $id);
        $today = CarbonImmutable::now($ctx->farm->timezone)->toDateString();
        $milestones = [['code' => 'started', 'date' => $project->start_date->toDateString(), 'from' => null, 'to' => null, 'status' => 'done']];
        $expected = ['code' => 'expected_outcome', 'date' => $project->expected_date?->toDateString(), 'from' => $project->expected_from?->toDateString(), 'to' => $project->expected_to?->toDateString(), 'status' => 'not_estimated'];
        $actual = $project->outcomes->first(fn ($o) => $o->kind === BreedingOutcome::OUTCOME && $o->reversal === null);
        if ($expected['date'] !== null || $expected['from'] !== null) {
            $last = $expected['date'] ?? $expected['to'];
            $expected['status'] = $actual ? 'done' : ($project->status === BreedingStatus::Cancelled ? 'cancelled' : ($last < $today ? 'overdue' : 'pending'));
        }
        $milestones[] = $expected;
        foreach ($project->checks as $check) {
            $milestones[] = ['code' => 'check', 'date' => $check->checked_on->toDateString(), 'from' => null, 'to' => null, 'status' => 'done', 'result' => $check->result];
        }
        if ($actual) {
            $milestones[] = ['code' => 'outcome', 'date' => $actual->outcome_date->toDateString(), 'from' => null, 'to' => null, 'status' => 'done'];
        }

        return $milestones;
    }

    // ------------------------------------------------------------------ internals

    /** @return array{0: ProductionCycle, 1: BreedingProject} locked farm -> cycle -> project, all farm scoped. */
    private function lockProject(FarmContext $ctx, string $id, bool $lockFarm = true): array
    {
        if ($lockFarm) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
        }
        $cycleId = BreedingProject::where('farm_id', $ctx->farm->id)->findOrFail($id)->production_cycle_id;
        $cycle = ProductionCycle::ofFarm($ctx->farm)->with('livestock.species')->lockForUpdate()->findOrFail($cycleId);
        $project = BreedingProject::where('farm_id', $ctx->farm->id)->lockForUpdate()->findOrFail($id);

        return [$cycle, $project];
    }

    /** When the eggs went into the incubator: now for a start today, otherwise the end of the start day so same-day collections precede it. */
    private function setAt(FarmContext $ctx, string $startDate): CarbonImmutable
    {
        $endOfDay = CarbonImmutable::parse($startDate, $ctx->farm->timezone)->endOfDay()->utc();

        return $endOfDay->isFuture() ? CarbonImmutable::now() : $endOfDay;
    }

    /** The farm's egg stock item (never created here: nothing to take from a farm that never had eggs) and the store the eggs leave. */
    private function takeEggs(FarmContext $ctx, BreedingProject $project, ProductionCycle $cycle, int $eggs, ?string $locationId, CarbonImmutable $when): void
    {
        $item = $this->outputs->find($ctx, StockReasonCatalogue::KIND_EGGS, forUpdate: true);
        $location = $item ? $this->outputs->issuingLocation($ctx, $locationId, 'egg_storage_location_id') : null;
        if ($item === null || $location === null) {
            throw new ApiHttpException(409, 'insufficient_stock', 'There are no eggs in stock to put into incubation.', details: ['available' => ['quantity' => '0', 'unit' => 'piece'], 'requested' => ['quantity' => (string) $eggs, 'unit' => 'piece']]);
        }
        $this->inventory->consumeForBreeding($ctx, $project->id, $cycle->id, $item, $location, $eggs, $when);
    }

    /** Editing eggs_set of a stock-linked project keeps the ledger explained: more eggs set = more taken; fewer = only what the farmer says returned. */
    private function reconcileEggStock(FarmContext $ctx, BreedingProject $project, ProductionCycle $cycle, array $data): void
    {
        $stock = $this->inventory->incubationStock($ctx, $project->id);
        $diff = isset($data['eggs_set']) ? (int) $data['eggs_set'] - (int) $project->eggs_set : 0;
        if (isset($data['egg_storage_location_id']) && ! isset($data['eggs_returned_to_stock'])) {
            $this->invalid('egg_storage_location_id', 'Choose a storage location only together with eggs_returned_to_stock.');
        }
        if ($stock['consumed'] === 0) {
            if (isset($data['eggs_returned_to_stock'])) {
                $this->invalid('eggs_returned_to_stock', 'No eggs were taken from stock for this project.');
            }

            return;
        }
        if ($diff > 0) {
            if (isset($data['eggs_returned_to_stock'])) {
                $this->invalid('eggs_returned_to_stock', 'Eggs are returned only when eggs_set is reduced.');
            }
            $ctx->authorize(Permission::InventoryUse);
            $this->takeEggs($ctx, $project, $cycle, $diff, $stock['storage_location_id'], CarbonImmutable::now());
        } elseif (isset($data['eggs_returned_to_stock'])) {
            if ($diff >= 0 || (int) $data['eggs_returned_to_stock'] > -$diff) {
                $this->invalid('eggs_returned_to_stock', 'Cannot return more eggs than eggs_set was reduced by.');
            }
            $this->returnEggs($ctx, $project, $cycle, $data);
        }
    }

    /** Writes the explicit return (if any): at most what the project still holds out of stock, to the named store or where the eggs came from. */
    private function returnEggs(FarmContext $ctx, BreedingProject $project, ProductionCycle $cycle, array $data): void
    {
        $returned = $data['eggs_returned_to_stock'] ?? null;
        if ($returned === null) {
            if (isset($data['egg_storage_location_id'])) {
                $this->invalid('egg_storage_location_id', 'Choose a storage location only together with eggs_returned_to_stock.');
            }

            return; // no instruction = no inventory increase
        }
        $ctx->authorize(Permission::InventoryUse);
        $stock = $this->inventory->incubationStock($ctx, $project->id);
        if ($stock['consumed'] === 0) {
            $this->invalid('eggs_returned_to_stock', 'No eggs were taken from stock for this project.');
        }
        if ((int) $returned > $stock['net']) {
            $this->invalid('eggs_returned_to_stock', 'Cannot return more eggs than this project took from stock (still out: '.$stock['net'].').');
        }
        $item = InventoryItem::ofFarm($ctx->farm)->with('stockUnit.dimension')->lockForUpdate()->findOrFail($stock['inventory_item_id']);
        $location = $this->outputs->returnLocation($ctx, $data['egg_storage_location_id'] ?? null, $stock['storage_location_id']);
        $this->inventory->returnForBreeding($ctx, $project->id, $cycle->id, $item, $location, (int) $returned, CarbonImmutable::now());
    }

    /** @return array{source: string, date: ?string, from: ?string, to: ?string} manual override if supplied, else derived from the snapshot. */
    private function expectation(array $snapshot, string $start, array $data): array
    {
        if (isset($data['expected_date']) || isset($data['expected_from'])) {
            $first = $data['expected_date'] ?? $data['expected_from'];
            $this->assertManualAfterStart($start, $first);

            return ['source' => 'manual', 'date' => $data['expected_date'] ?? null, 'from' => $data['expected_from'] ?? null, 'to' => $data['expected_to'] ?? null];
        }

        return $this->reference->expectation($snapshot, $start);
    }

    private function assertManualAfterStart(string $start, ?string $expected): void
    {
        if ($expected !== null && $expected < $start) {
            $this->invalid('expected_date', 'An expected date cannot be before the start date.');
        }
    }

    /** Incubation fields exist only for incubation, pregnancy fields only for pregnancy; `$creating` also demands the required ones. */
    private function assertWorkflowFields(BreedingWorkflow $workflow, array $data, bool $creating = false): void
    {
        $incubation = $workflow === BreedingWorkflow::Incubation;
        if ($creating && $incubation && ($data['eggs_set'] ?? null) === null) {
            $this->invalid('eggs_set', 'Eggs set is required for incubation.');
        }
        if (! $incubation && ($data['eggs_set'] ?? null) !== null) {
            $this->invalid('eggs_set', 'Eggs set applies to incubation workflows only.');
        }
        if ($incubation && ($data['females_bred'] ?? null) !== null) {
            $this->invalid('females_bred', 'Females bred applies to pregnancy workflows only.');
        }
    }

    private function assertStart(FarmContext $ctx, ProductionCycle $cycle, string $start): void
    {
        if ($start < $cycle->start_date->toDateString() || $start > CarbonImmutable::now($ctx->farm->timezone)->toDateString()) {
            $this->invalid('start_date', 'The start must be between the cycle start and today.');
        }
    }

    /** Parent sources are livestock cycles of the SAME farm and species (group-managed; no individual identity). */
    private function parents(FarmContext $ctx, ProductionCycle $cycle, array $parents): array
    {
        $rows = [];
        foreach ($parents as $index => $parent) {
            $source = ProductionCycle::ofFarm($ctx->farm)->with('livestock')->find($parent['production_cycle_id']);
            if (! $source || $source->kind !== CycleKind::Livestock || $source->livestock?->species_id !== $cycle->livestock->species_id) {
                $this->invalid('parents.'.$index.'.production_cycle_id', 'A parent must be a livestock cycle of this farm with the same species.');
            }
            $rows[$parent['role'].'|'.$source->id] = ['role' => $parent['role'], 'parent_cycle_id' => $source->id, 'head_count' => $parent['head_count'] ?? null];
        }
        if (count($rows) !== count($parents)) {
            $this->invalid('parents', 'Each parent role and cycle may appear once.');
        }

        return array_values($rows);
    }

    private function assertCorrection(FarmContext $ctx, BreedingProject $project, ?string $correctsId): void
    {
        if ($correctsId === null) {
            return;
        }
        $original = BreedingOutcome::where('farm_id', $ctx->farm->id)->where('breeding_project_id', $project->id)->findOrFail($correctsId);
        if ($original->kind !== BreedingOutcome::OUTCOME || ! $original->reversal()->exists() || BreedingOutcome::where('corrects_outcome_id', $original->id)->exists()) {
            throw new ApiHttpException(409, 'invalid_correction', 'Replace a reversed outcome of this project once.');
        }
    }

    private function replay(FarmContext $ctx, string $key, string $hash): ?BreedingOutcome
    {
        $outcome = BreedingOutcome::where('farm_id', $ctx->farm->id)->where('idempotency_key', $key)->first();
        if ($outcome) {
            $this->assertSameRequest($outcome->request_hash, $hash);
        }

        return $outcome ? $this->outcomeFresh($outcome) : null;
    }

    private function outcomeFresh(BreedingOutcome $outcome): BreedingOutcome
    {
        return BreedingOutcome::with('reversal')->findOrFail($outcome->id);
    }

    private function assertSameRequest(string $stored, string $hash): void
    {
        if (! hash_equals($stored, $hash)) {
            throw new ApiHttpException(409, 'idempotency_conflict', 'This idempotency key was already used for a different request.');
        }
    }

    private function activeCycle(ProductionCycle $cycle): void
    {
        if ($cycle->status !== CycleStatus::Active) {
            throw new ApiHttpException(409, 'cycle_closed', 'Reopen the cycle before recording or changing breeding events.');
        }
    }

    private function activeProject(BreedingProject $project): void
    {
        if ($project->status !== BreedingStatus::Active) {
            throw new ApiHttpException(409, 'project_not_active', 'Only an active breeding project can be changed.');
        }
    }

    private function when(FarmContext $ctx, BreedingProject $project, string $value): CarbonImmutable
    {
        $date = CarbonImmutable::parse($value)->utc();
        $floor = CarbonImmutable::parse($project->start_date->toDateString(), $ctx->farm->timezone)->startOfDay()->utc();
        if ($date->isFuture() || $date->lessThan($floor)) {
            $this->invalid('recorded_at', 'The outcome must be between the project start and now.');
        }

        return $date;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
