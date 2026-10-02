<?php

namespace App\Services\Records;

use App\Enums\ConversionContextType;
use App\Enums\CycleStatus;
use App\Enums\Permission;
use App\Events\Production\RecordCreated;
use App\Http\Requests\Records\ReverseRecordRequest;
use App\Http\Requests\Records\StoreRecordRequest;
use App\Models\Farm;
use App\Models\OperationalRecord;
use App\Models\PopulationMovement;
use App\Models\ProductionCycle;
use App\Services\Inventory\InventoryService;
use App\Services\Measurement\PackageConversionService;
use App\Services\Measurement\QuantityNormalizer;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use App\Support\Measurement\ConversionContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecordService
{
    public function __construct(private RecordTypeRegistry $types, private QuantityNormalizer $quantities, private PopulationLedger $ledger, private InventoryService $inventory) {}

    public function find(FarmContext $ctx, string $id): OperationalRecord
    {
        $ctx->authorize(Permission::RecordView);

        return OperationalRecord::where('farm_id', $ctx->farm->id)->with(['reversal', 'attachments', 'inventoryMovement'])->findOrFail($id);
    }

    public function create(FarmContext $ctx, array $input): OperationalRecord
    {
        $ctx->authorize(Permission::RecordCreate);
        $data = Validator::make($input, (new StoreRecordRequest)->rules())->validate();
        if ($data['type'] === 'population_adjustment') {
            $ctx->authorize(Permission::RecordAdjust);
        }
        if (isset($data['corrects_record_id'])) {
            $ctx->authorize(Permission::RecordReverse);
        }

        return DB::transaction(function () use ($ctx, $data) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $hash = $this->hash($data);
            if ($replay = $this->replay($ctx, $data['idempotency_key'], $hash)) {
                return $replay;
            }
            $cycle = ProductionCycle::ofFarm($ctx->farm)->lockForUpdate()->findOrFail($data['production_cycle_id']);
            $this->active($cycle);
            $definition = $this->types->definition($data['type']);
            if ($definition['kind'] !== null && $definition['kind'] !== $cycle->kind->value) {
                $this->invalid('type', 'This record type is not applicable to this kind of cycle.');
            }
            if (isset($definition['capability']) && ! $cycle->livestock?->species->speciesCapabilities()->where('enabled', true)->whereHas('capability', fn ($q) => $q->where('code', $definition['capability']))->exists()) {
                $this->invalid('type', 'The species does not support this record capability.');
            }
            $details = Validator::make(['details' => $data['details']], $this->types->detailRules($data['type']))->validate()['details'];
            $when = $this->when($ctx, $cycle, $data['recorded_at']);
            if (isset($data['corrects_record_id'])) {
                $original = OperationalRecord::where('farm_id', $ctx->farm->id)->where('production_cycle_id', $cycle->id)->findOrFail($data['corrects_record_id']);
                if (! $original->reversal()->exists() || $original->type !== $data['type'] || OperationalRecord::where('corrects_record_id', $original->id)->exists()) {
                    throw new ApiHttpException(409, 'invalid_correction', 'Replace a reversed record once, using the same record type and cycle.');
                }
            }
            $details = $this->cropEvent($cycle, $data['type'], $details);
            $current = $this->ledger->reconcile($cycle);
            $delta = 0;
            $measurement = null;
            if ($data['type'] === 'mortality') {
                $delta = -(int) $details['quantity'];
                $measurement = $this->quantities->normalize($ctx->farm, [['quantity' => $details['quantity'], 'unit' => 'head']], requiredDimensions: ['count'])->toArray();
            } elseif ($data['type'] === 'population_adjustment') {
                foreach (['actual_population', 'expected_population'] as $field) {
                    if (is_bool($details[$field]) || is_array($details[$field])) {
                        $this->invalid('details.'.$field, 'A nonnegative whole count is required.');
                    }
                    $details[$field] = (int) $details[$field];
                }
                if ($details['expected_population'] !== $current) {
                    throw new ApiHttpException(409, 'population_changed', 'The ledger population changed; refresh the expected count before reconciling.');
                }
                if ($cycle->movements()->where('recorded_at', '>', $when)->exists()) {
                    $this->invalid('recorded_at', 'A reconciliation count cannot precede existing population movements.');
                }
                $delta = $details['actual_population'] - $current;
                $details['difference'] = $delta;
                $measurement = $this->quantities->normalize($ctx->farm, [['quantity' => $details['actual_population'], 'unit' => 'head']], requiredDimensions: ['count'])->toArray();
            } elseif (isset($details['components'])) {
                $context = isset($details['context']) ? new ConversionContext(ConversionContextType::from($details['context']['type']), $details['context']['id']) : null;
                $stock = $definition['stock'] ?? null;
                if (isset($details['inventory'])) {
                    // Linked stock: the quantity is measured against the inventory item, so its packages (and only its packages) apply.
                    $ctx->authorize(Permission::InventoryUse);
                    $stockItem = $stock !== null ? $this->inventory->cropStockItemForRecord($ctx, $details['inventory'], $stock) : $this->inventory->feedItemForRecord($ctx, $details['inventory']);
                    if ($context !== null) {
                        $this->invalid('details.context', 'Stock linked to inventory is measured against the inventory item; omit context.');
                    }
                    $context = new ConversionContext(ConversionContextType::InventoryItem, $stockItem->id);
                    if ($stock !== null) {
                        // The item decides the dimension (a liquid is measured by volume, seed may be counted); quantity is never area or planting units.
                        $definition['dimension'] = $stockItem->dimension();
                        $definition['unit'] = $stockItem->stockUnit->code;
                        if (isset($definition['fields']['input_name'])) {
                            $details['input_name'] ??= $stockItem->name;
                        }
                    }
                }
                if ($context !== null) {
                    $context = app(PackageConversionService::class)->resolveContext($ctx->farm, $context->type, $context->id);
                }
                $dimensions = [$definition['dimension']];
                if ($stock !== null && ! isset($stockItem)) {
                    // No stock link: the entered unit decides among the dimensions this event allows; never a guessed package size.
                    $dimensions = $stock['dimensions'];
                    $definition['unit'] = null;
                }
                $measurement = $this->quantities->normalize($ctx->farm, $details['components'], $context, $definition['unit'], $dimensions)->toArray();
            }
            if ($data['type'] === 'crop_harvest' && ($measurement['normalized']['quantity'] === '0' || str_starts_with((string) $measurement['normalized']['quantity'], '-'))) {
                $this->invalid('details.components', 'A harvest quantity must be greater than zero.');
            }
            foreach ($definition['area_fields'] ?? [] as $areaField) {
                if (isset($details[$areaField])) {
                    $details[$areaField] = $this->area($ctx, $areaField, $details[$areaField]);
                }
            }
            $record = $this->insert($ctx, $cycle, $data, $details, $measurement, $delta, $when, $hash);
            if (isset($stockItem)) {
                // One real-world event -> one stock movement, in the same transaction as the record.
                if (($definition['stock']['direction'] ?? 'out') === 'in') {
                    $this->inventory->receiveForRecord($ctx, $record, $stockItem, $details['inventory'], $measurement, $when);
                } else {
                    $this->inventory->consumeForRecord($ctx, $record, $stockItem, $details['inventory'], $measurement, $when);
                }
            }
            $this->ledger->reconcile($cycle);
            RecordCreated::dispatch($record);

            return $record->load(['reversal', 'attachments', 'inventoryMovement']);
        }, 3);
    }

    public function reverse(FarmContext $ctx, string $id, array $input): OperationalRecord
    {
        $ctx->authorize(Permission::RecordReverse);
        $data = Validator::make($input, (new ReverseRecordRequest)->rules())->validate();

        return DB::transaction(function () use ($ctx, $id, $data) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $original = $this->find($ctx, $id);
            $hash = $this->hash(['reverse' => $id, ...$data]);
            if ($replay = $this->replay($ctx, $data['idempotency_key'], $hash)) {
                return $replay;
            }
            $cycle = ProductionCycle::ofFarm($ctx->farm)->lockForUpdate()->findOrFail($original->production_cycle_id);
            $this->active($cycle);
            if ($original->type === 'reversal' || $original->reversal !== null) {
                throw new ApiHttpException(409, 'record_already_reversed', 'This record cannot be reversed again.');
            }
            if ($original->type === 'breeding_outcome') {
                throw new ApiHttpException(409, 'reverse_via_breeding_outcome', 'This population effect belongs to a breeding outcome; reverse the breeding outcome instead.');
            }
            $when = $this->when($ctx, $cycle, $data['recorded_at']);
            if ($when->lessThan($original->recorded_at)) {
                $this->invalid('recorded_at', 'A reversal cannot precede its original event.');
            }
            $this->ledger->reconcile($cycle);
            $record = $this->insert($ctx, $cycle, ['type' => 'reversal', 'reverses_record_id' => $original->id, ...$data], ['reason' => $data['reason']], $original->measurement, -$original->population_delta, $when, $hash);
            $this->inventory->reverseForRecord($ctx, $original, $record);
            $this->ledger->reconcile($cycle);
            RecordCreated::dispatch($record);

            return $record->load(['reversal', 'attachments', 'inventoryMovement']);
        }, 3);
    }

    /**
     * Breeding integration: the population effect of a breeding outcome (or its reversal) as an ordinary Phase 8 record + movement.
     * The caller holds the farm and cycle locks and owns idempotency; the ledger is reconciled before and after.
     */
    public function appendForBreeding(FarmContext $ctx, ProductionCycle $cycle, array $details, int $delta, CarbonImmutable $when, string $key, string $hash, ?OperationalRecord $reverses = null): OperationalRecord
    {
        $this->ledger->reconcile($cycle);
        $measurement = $reverses?->measurement ?? $this->quantities->normalize($ctx->farm, [['quantity' => (string) $delta, 'unit' => 'head']], requiredDimensions: ['count'])->toArray();
        $record = $this->insert($ctx, $cycle, ['type' => $reverses ? 'reversal' : 'breeding_outcome', 'reverses_record_id' => $reverses?->id, 'idempotency_key' => $key], $details, $measurement, $delta, $when, $hash);
        $this->ledger->reconcile($cycle);
        RecordCreated::dispatch($record);

        return $record;
    }

    /**
     * Phase 13 baseline rules. Planting units are the crop baseline (initial_planting_units, immutable); every figure here is
     * derived from it and from the project's own non-reversed records, never from seed quantity, area or stock.
     */
    private function cropEvent(ProductionCycle $cycle, string $type, array $details): array
    {
        if (! in_array($type, ['planting', 'establishment_check', 'crop_loss'], true)) {
            return $details;
        }
        $baseline = (int) $cycle->crop->initial_planting_units;
        $sum = fn (string $recordType, string $field): int => (int) OperationalRecord::where('production_cycle_id', $cycle->id)->where('type', $recordType)->whereDoesntHave('reversal')->get()->sum(fn ($r) => (int) ($r->details[$field] ?? 0));
        if ($type === 'establishment_check') {
            $established = (int) $details['established_units'];
            if ($established > $baseline) {
                $this->invalid('details.established_units', 'Established units cannot exceed the project baseline of '.$baseline.' planting units.');
            }
            $details['established_units'] = $established;
            $details['baseline_units'] = $baseline;
            $details['failed_units'] = $baseline - $established;
            $details['survival_percent'] = CropMetrics::percent($established, $baseline);

            return $details;
        }
        $field = $type === 'planting' ? 'units_planted' : 'units_lost';
        $already = $sum($type, $field);
        $units = (int) $details[$field];
        if ($already + $units > $baseline) {
            $this->invalid('details.'.$field, 'Recorded '.($type === 'planting' ? 'planting' : 'loss').' would total '.($already + $units).' units, more than the project baseline of '.$baseline.' (already recorded: '.$already.').');
        }
        $details[$field] = $units;

        return $details;
    }

    /** An area (treated, affected) normalised on its own (hectare/acre/m²); it is context for the event and never feeds stock, land totals or planting units. */
    private function area(FarmContext $ctx, string $field, array $area): array
    {
        $normalized = $this->quantities->normalize($ctx->farm, [$area], requiredDimensions: ['area'])->toArray();
        if ($normalized['normalized']['quantity'] === '0' || str_starts_with($normalized['normalized']['quantity'], '-')) {
            $this->invalid('details.'.$field, 'The area must be greater than zero.');
        }

        return ['quantity' => $area['quantity'], 'unit' => $area['unit'], 'normalized' => $normalized['normalized']];
    }

    private function insert(FarmContext $ctx, ProductionCycle $cycle, array $data, array $details, ?array $measurement, int $delta, CarbonImmutable $when, string $hash): OperationalRecord
    {
        $record = OperationalRecord::create([
            'farm_id' => $ctx->farm->id, 'production_cycle_id' => $cycle->id, 'type' => $data['type'], 'details' => $details, 'measurement' => $measurement,
            'population_delta' => $delta, 'recorded_at' => $when, 'notes' => $data['notes'] ?? null, 'reverses_record_id' => $data['reverses_record_id'] ?? null,
            'corrects_record_id' => $data['corrects_record_id'] ?? null, 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
            'created_by' => $ctx->membership->user_id, 'request_id' => request()->attributes->get('request_id'),
        ]);
        if ($delta !== 0 || in_array($record->type, ['mortality', 'population_adjustment'])) {
            PopulationMovement::create(['farm_id' => $ctx->farm->id, 'production_cycle_id' => $cycle->id, 'operational_record_id' => $record->id, 'type' => $record->type,
                'source_key' => 'record:'.$record->id, 'quantity' => $delta, 'recorded_at' => $when, 'created_by' => $ctx->membership->user_id]);
        }

        return $record;
    }

    private function replay(FarmContext $ctx, string $key, string $hash): ?OperationalRecord
    {
        $record = OperationalRecord::where('farm_id', $ctx->farm->id)->where('idempotency_key', $key)->first();
        if ($record && ! hash_equals($record->request_hash, $hash)) {
            throw new ApiHttpException(409, 'idempotency_conflict', 'This idempotency key was already used for a different request.');
        }

        return $record?->load(['reversal', 'attachments', 'inventoryMovement']);
    }

    private function hash(array $data): string
    {
        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $sort($item);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($sort($data), JSON_THROW_ON_ERROR));
    }

    private function active(ProductionCycle $cycle): void
    {
        if ($cycle->status !== CycleStatus::Active) {
            throw new ApiHttpException(409, 'cycle_closed', 'Reopen the cycle before recording or correcting events.');
        }
    }

    private function when(FarmContext $ctx, ProductionCycle $cycle, string $value): CarbonImmutable
    {
        $date = CarbonImmutable::parse($value)->utc();
        if ($date->isFuture() || $date->lessThan(CarbonImmutable::parse($cycle->start_date->toDateString(), $ctx->farm->timezone)->startOfDay()->utc())) {
            $this->invalid('recorded_at', 'The event must be between the cycle start and now.');
        }

        return $date;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
