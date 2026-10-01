<?php

namespace App\Services\Health;

use App\Enums\CycleStatus;
use App\Enums\Permission;
use App\Events\Health\HealthRecordCreated;
use App\Http\Requests\Health\ReverseHealthRecordRequest;
use App\Http\Requests\Health\StoreHealthRecordRequest;
use App\Models\Farm;
use App\Models\HealthRecord;
use App\Models\HealthRecordMedicine;
use App\Models\InventoryItem;
use App\Models\MedicineProfile;
use App\Models\OperationalRecord;
use App\Models\ProductionCycle;
use App\Services\Inventory\InventoryService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use App\Support\Idempotency\RequestHash;
use App\Support\Measurement\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of health records. A health record is a real farm event: it never changes population (mortality stays
 * a Phase 8 record that a health record may link to) and every medicine line consumes stock through the Phase 9 ledger
 * in the same transaction. Lock order matches Phases 7-9: farm row, cycle row, inventory item rows.
 */
class HealthService
{
    private const RELATIONS = ['reversal', 'medicines.item.stockUnit', 'medicines.lot', 'medicines.movement'];

    public function __construct(private HealthTypeRegistry $types, private InventoryService $inventory) {}

    public function find(FarmContext $ctx, string $id): HealthRecord
    {
        $ctx->authorize(Permission::HealthView);

        return HealthRecord::where('farm_id', $ctx->farm->id)->with(self::RELATIONS)->findOrFail($id);
    }

    public function create(FarmContext $ctx, array $input): HealthRecord
    {
        $ctx->authorize(Permission::HealthCreate);
        $data = Validator::make($input, (new StoreHealthRecordRequest)->rules())->validate();
        if (isset($data['corrects_record_id'])) {
            $ctx->authorize(Permission::HealthReverse);
        }

        return DB::transaction(function () use ($ctx, $data) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $hash = RequestHash::of($data);
            if ($replay = $this->replay($ctx, $data['idempotency_key'], $hash)) {
                return $replay;
            }
            $cycle = ProductionCycle::ofFarm($ctx->farm)->lockForUpdate()->findOrFail($data['production_cycle_id']);
            $this->active($cycle);
            $definition = $this->types->definition($data['type']);
            $kind = $cycle->kind->value;
            if (! in_array($kind, $definition['kinds'], true)) {
                $this->invalid('type', 'This health record type is not applicable to this kind of cycle.');
            }
            $details = (Validator::make(['details' => $data['details'] ?? []], $this->types->detailRules($data['type']))->validate()['details'] ?? []);
            $medicines = $data['medicines'] ?? [];
            if ($definition['medicines'] === 'required' && $medicines === []) {
                $this->invalid('medicines', 'At least one medicine is required for this health record type.');
            }
            if ($definition['medicines'] === 'forbidden' && $medicines !== []) {
                $this->invalid('medicines', 'This health record type does not use medicines; record a treatment or medication instead.');
            }
            $when = $this->when($ctx, $cycle, $data['recorded_at']);
            $this->assertCommonFields($ctx, $cycle, $data, $when);
            $this->assertCorrection($ctx, $cycle, $data);
            $this->assertUniqueLines($medicines);

            $record = HealthRecord::create([
                'farm_id' => $ctx->farm->id, 'production_cycle_id' => $cycle->id, 'type' => $data['type'], 'details' => $details,
                'animals_affected' => isset($data['animals_affected']) ? (int) $data['animals_affected'] : null, 'follow_up_on' => $data['follow_up_on'] ?? null,
                'mortality_record_id' => $data['mortality_record_id'] ?? null, 'recorded_at' => $when, 'notes' => $data['notes'] ?? null,
                'corrects_record_id' => $data['corrects_record_id'] ?? null, 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
                'created_by' => $ctx->membership->user_id, 'request_id' => request()->attributes->get('request_id'),
            ]);
            foreach (array_values($medicines) as $index => $line) {
                $this->addLine($ctx, $record, $index, $line, $when);
            }
            HealthRecordCreated::dispatch($record);

            return $record->load(self::RELATIONS);
        }, 3);
    }

    /** Appends a reversal row and compensating stock movements; the original rows are never edited. */
    public function reverse(FarmContext $ctx, string $id, array $input): HealthRecord
    {
        $ctx->authorize(Permission::HealthReverse);
        $data = Validator::make($input, (new ReverseHealthRecordRequest)->rules())->validate();

        return DB::transaction(function () use ($ctx, $id, $data) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $original = HealthRecord::where('farm_id', $ctx->farm->id)->with('medicines')->findOrFail($id);
            $hash = RequestHash::of(['reverse' => $id] + $data);
            if ($replay = $this->replay($ctx, $data['idempotency_key'], $hash)) {
                return $replay;
            }
            $cycle = ProductionCycle::ofFarm($ctx->farm)->lockForUpdate()->findOrFail($original->production_cycle_id);
            $this->active($cycle);
            if ($original->type === HealthTypeRegistry::REVERSAL || $original->reversal()->exists()) {
                throw new ApiHttpException(409, 'record_already_reversed', 'This health record cannot be reversed again.');
            }
            $when = $this->when($ctx, $cycle, $data['recorded_at']);
            if ($when->lessThan($original->recorded_at)) {
                $this->invalid('recorded_at', 'A reversal cannot precede its original event.');
            }
            $reversal = HealthRecord::create([
                'farm_id' => $ctx->farm->id, 'production_cycle_id' => $cycle->id, 'type' => HealthTypeRegistry::REVERSAL, 'details' => ['reason' => $data['reason']],
                'recorded_at' => $when, 'reverses_record_id' => $original->id, 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
                'created_by' => $ctx->membership->user_id, 'request_id' => request()->attributes->get('request_id'),
            ]);
            foreach ($original->medicines as $line) {
                $this->inventory->reverseForHealth($ctx, $line->id, $reversal->id, $when, $data['reason']);
            }
            HealthRecordCreated::dispatch($reversal);

            return $reversal->load(self::RELATIONS);
        }, 3);
    }

    private function addLine(FarmContext $ctx, HealthRecord $record, int $index, array $line, CarbonImmutable $when): void
    {
        $prefix = 'medicines.'.$index;
        $item = $this->inventory->medicineItemForHealth($ctx, $line['inventory_item_id'], $prefix);
        $result = $this->inventory->measureForItem($ctx, $item, $line['components'], $prefix.'.components');
        $quantity = $result->normalized->value;
        $dose = null;
        if (! empty($line['dose_per_animal'])) {
            $dose = $this->inventory->measureLoose($ctx, $item, $line['dose_per_animal'], $prefix.'.dose_per_animal');
        }
        [$location, $lot] = $this->inventory->healthSource($ctx, $item, $line, $when, $prefix);
        [$days, $source] = $this->withdrawal($item, $line);
        $row = HealthRecordMedicine::create([
            'farm_id' => $ctx->farm->id, 'health_record_id' => $record->id, 'line_no' => $index + 1, 'inventory_item_id' => $item->id, 'item_name' => $item->name,
            'storage_location_id' => $location->id, 'inventory_lot_id' => $lot?->id,
            'quantity_used' => Decimal::trim($quantity), 'measurement' => $result->toArray(), 'dose' => $dose,
            'dosage_instructions' => $line['dosage_instructions'] ?? null, 'withdrawal_days' => $days, 'withdrawal_source' => $source,
            'withdrawal_ends_at' => $days === null || $days === 0 ? null : $when->addDays($days),
        ]);
        $this->inventory->consumeForHealth($ctx, $record->id, $row->id, $item, $location, $lot, $result->toArray(), $when, $prefix);
    }

    /** @return array{0: int|null, 1: string|null} effective withdrawal days and where they came from (explicit line value or the item default). */
    private function withdrawal(InventoryItem $item, array $line): array
    {
        if (array_key_exists('withdrawal_days', $line) && $line['withdrawal_days'] !== null) {
            return [(int) $line['withdrawal_days'], 'explicit'];
        }
        $default = MedicineProfile::where('inventory_item_id', $item->id)->value('default_withdrawal_days');

        return $default === null ? [null, null] : [(int) $default, 'item_default'];
    }

    private function assertUniqueLines(array $medicines): void
    {
        $seen = [];
        foreach (array_values($medicines) as $index => $line) {
            $key = $line['inventory_item_id'].'|'.$line['storage_location_id'].'|'.($line['lot_id'] ?? '');
            if (isset($seen[$key])) {
                $this->invalid('medicines.'.$index, 'Combine repeated item, location and lot into one medicine line.');
            }
            $seen[$key] = true;
        }
    }

    private function assertCommonFields(FarmContext $ctx, ProductionCycle $cycle, array $data, CarbonImmutable $when): void
    {
        if (($data['animals_affected'] ?? null) !== null && (int) $data['animals_affected'] > (int) $cycle->movements()->sum('quantity')) {
            $this->invalid('animals_affected', 'More animals than the current population of this cycle.');
        }
        if (($data['follow_up_on'] ?? null) !== null && $data['follow_up_on'] < $when->setTimezone($ctx->farm->timezone)->toDateString()) {
            $this->invalid('follow_up_on', 'The follow-up cannot be before the event day.');
        }
        if (($data['mortality_record_id'] ?? null) !== null) {
            // Health links to the Phase 8 mortality event; it never creates one, so population has exactly one source.
            $mortality = OperationalRecord::where('farm_id', $ctx->farm->id)->where('production_cycle_id', $cycle->id)->where('type', 'mortality')->find($data['mortality_record_id']);
            if (! $mortality || $mortality->reversal()->exists()) {
                $this->invalid('mortality_record_id', 'Link an existing, non-reversed mortality record of this cycle.');
            }
        }
    }

    private function assertCorrection(FarmContext $ctx, ProductionCycle $cycle, array $data): void
    {
        if (! isset($data['corrects_record_id'])) {
            return;
        }
        $original = HealthRecord::where('farm_id', $ctx->farm->id)->where('production_cycle_id', $cycle->id)->findOrFail($data['corrects_record_id']);
        if (! $original->reversal()->exists() || $original->type !== $data['type'] || HealthRecord::where('corrects_record_id', $original->id)->exists()) {
            throw new ApiHttpException(409, 'invalid_correction', 'Replace a reversed health record once, using the same record type and cycle.');
        }
    }

    private function replay(FarmContext $ctx, string $key, string $hash): ?HealthRecord
    {
        $record = HealthRecord::where('farm_id', $ctx->farm->id)->where('idempotency_key', $key)->first();
        if ($record && ! hash_equals($record->request_hash, $hash)) {
            throw new ApiHttpException(409, 'idempotency_conflict', 'This idempotency key was already used for a different request.');
        }

        return $record?->load(self::RELATIONS);
    }

    private function active(ProductionCycle $cycle): void
    {
        if ($cycle->status !== CycleStatus::Active) {
            throw new ApiHttpException(409, 'cycle_closed', 'Reopen the cycle before recording or correcting health events.');
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
