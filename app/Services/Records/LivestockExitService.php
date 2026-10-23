<?php

namespace App\Services\Records;

use App\Enums\CycleKind;
use App\Enums\CycleStatus;
use App\Enums\Permission;
use App\Http\Requests\LivestockExits\ListLivestockExitsRequest;
use App\Http\Requests\LivestockExits\StoreLivestockExitRequest;
use App\Http\Requests\Records\ReverseRecordRequest;
use App\Models\Farm;
use App\Models\InventoryItem;
use App\Models\OperationalRecord;
use App\Models\PopulationMovement;
use App\Models\ProductionCycle;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\OutputStockService;
use App\Services\Measurement\QuantityNormalizer;
use App\Services\Sales\SaleService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use App\Support\Idempotency\RequestHash;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * One front door for "animals leave a batch": sold, died, slaughtered, culled, given away, moved to another batch.
 *
 * It owns no data. Each type is written by the service that already owns it - a sale by SaleService (quick sell: buyer, price, payment),
 * a death by RecordService (the ordinary mortality record) - or, for the four new exits, by RecordService::appendForExit as a
 * `livestock_exit` record (details.exit_type) plus its population movement (a move adds a `livestock_move_in` record on the other batch,
 * pointing back at the first through linked_record_id). A slaughter also receives the meat into the species' own stock item in the same
 * transaction. The list is a read over those same records: nothing is copied.
 *
 * One request idempotency key is hashed into every internal key, so a retry replays and never duplicates. Lock order matches the rest of
 * the system: farm row, production cycle rows (id order), then invoice/payment/inventory rows inside the called services.
 */
class LivestockExitService
{
    public const TYPES = ['sold', 'died', 'slaughtered', 'culled', 'given_away', 'moved'];

    public const CULL_REASONS = ['poor_production', 'sick_injured', 'old', 'other'];

    public const LABELS = ['sold' => 'Sold', 'died' => 'Died', 'slaughtered' => 'Slaughtered', 'culled' => 'Culled', 'given_away' => 'Given away', 'moved' => 'Moved to another batch'];

    /** The record types that make up the exit list (a move's arrival on the other batch is not an exit). */
    private const EXIT_RECORD_TYPES = ['mortality', 'livestock_sale', 'livestock_exit'];

    public function __construct(
        private RecordService $records, private SaleService $sales, private OutputStockService $outputs, private InventoryService $inventory,
        private QuantityNormalizer $quantities,
    ) {}

    // ------------------------------------------------------------------ create

    /**
     * @return array{result: array<string, mixed>, sale: Sale|null}
     */
    public function create(FarmContext $ctx, array $input): array
    {
        $data = Validator::make($input, (new StoreLivestockExitRequest)->rules())->validate();
        $type = $data['type'];
        if ($type !== 'sold') {
            $ctx->authorize(Permission::RecordCreate);
        }
        if ($type === 'slaughtered') {
            $ctx->authorize(Permission::InventoryUse);
        }

        return DB::transaction(function () use ($ctx, $data, $type) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $key = $this->key($data['idempotency_key']);
            $this->assertKeyFree($ctx, $key, $type === 'sold');

            return match ($type) {
                'sold' => $this->sold($ctx, $data, $key),
                'died' => $this->died($ctx, $data, $key),
                default => $this->exit($ctx, $data, $key),
            };
        }, 3);
    }

    /** sold: the quick-sell sale with one livestock line, unchanged. The sale owns the stock-out of the animals, the invoice and the payment. */
    private function sold(FarmContext $ctx, array $data, string $key): array
    {
        $sale = $this->sales->create($ctx, array_filter([
            'recorded_at' => $data['recorded_at'], 'idempotency_key' => $key, 'notes' => $data['notes'] ?? null,
            'buyer' => $data['buyer'] ?? null, 'payment' => $data['payment'] ?? null,
            'items' => [['kind' => 'livestock', 'production_cycle_id' => $data['production_cycle_id'], 'quantity' => $data['head_count'], 'unit_price' => $data['unit_price']]],
        ], fn ($value) => $value !== null));
        $record = $sale->items->first()?->record;

        return ['result' => $this->present($ctx, $record, $data['production_cycle_id']), 'sale' => $sale];
    }

    /** died: the existing mortality record, with the same rules (the batch must support mortality). */
    private function died(FarmContext $ctx, array $data, string $key): array
    {
        $record = $this->records->create($ctx, array_filter([
            'production_cycle_id' => $data['production_cycle_id'], 'type' => 'mortality', 'recorded_at' => $data['recorded_at'], 'idempotency_key' => $key,
            'details' => ['quantity' => $data['head_count'], 'cause' => $data['cause'] ?? 'Unknown'], 'notes' => $data['notes'] ?? null,
        ], fn ($value) => $value !== null));

        return ['result' => $this->present($ctx, $record, $data['production_cycle_id']), 'sale' => null];
    }

    /** slaughtered, culled, given away, moved. */
    private function exit(FarmContext $ctx, array $data, string $key): array
    {
        $type = $data['type'];
        $hash = RequestHash::of($data);
        if ($record = $this->replayed($ctx, $key, $hash)) {
            return ['result' => $this->present($ctx, $record, $data['production_cycle_id']), 'sale' => null];
        }
        $ids = collect([$data['production_cycle_id'], $data['destination_cycle_id'] ?? null])->filter()->unique()->sort()->values();
        if ($type === 'moved' && $ids->count() !== 2) {
            $this->invalid('destination_cycle_id', 'Choose a different batch to move the animals to.');
        }
        $cycles = [];
        foreach ($ids as $id) {
            $cycles[$id] = ProductionCycle::ofFarm($ctx->farm)->with('livestock.species')->lockForUpdate()->findOrFail($id);
        }
        $from = $cycles[$data['production_cycle_id']];
        $this->assertOpenLivestock($from, 'production_cycle_id');
        $when = $this->records->eventTime($ctx, $from, $data['recorded_at']);
        $head = (int) $data['head_count'];

        $details = ['exit_type' => $type === 'moved' ? 'moved_out' : $type, 'head_count' => $head];
        if ($type === 'culled') {
            $details['reason'] = $data['reason'];
        } elseif ($type === 'given_away') {
            $details['given_to'] = isset($data['given_to']) ? trim($data['given_to']) : null;
        } elseif ($type === 'slaughtered') {
            $details['meat_weight_kg'] = (string) $data['meat_weight_kg'];
            $details['live_weight_kg'] = isset($data['live_weight_kg']) ? (string) $data['live_weight_kg'] : null;
        } elseif ($type === 'moved') {
            $to = $cycles[$data['destination_cycle_id']];
            $this->assertOpenLivestock($to, 'destination_cycle_id');
            if ($to->livestock->species_id !== $from->livestock->species_id) {
                $this->invalid('destination_cycle_id', 'Animals can only be moved to a batch of the same species ('.$from->livestock->species->name.').');
            }
            $this->records->eventTime($ctx, $to, $data['recorded_at']);
            $details['destination_cycle_id'] = $to->id;
        }
        $details = array_filter($details, fn ($value) => $value !== null);

        if ($type === 'slaughtered') {
            // The meat item and its store are resolved before the record so a farm with several stores fails with a clear 422 first.
            $meatItem = $this->outputs->resolveMeat($ctx, $from->livestock->species);
            $location = $this->outputs->receivingLocation($ctx, $data['storage_location_id'] ?? null, 'storage_location_id');
            $details['inventory'] = ['item_id' => $meatItem->id, 'storage_location_id' => $location->id];
        }

        $record = $this->records->appendForExit($ctx, $from, 'livestock_exit', $details, -$head, $when, $key, $hash, $data['notes'] ?? null);

        if ($type === 'slaughtered') {
            $measurement = $this->quantities->normalize($ctx->farm, [['quantity' => $details['meat_weight_kg'], 'unit' => 'kg']], requiredDimensions: ['weight'])->toArray();
            $this->inventory->receiveForRecord($ctx, $record, $meatItem, ['storage_location_id' => $location->id], $measurement, $when, 'production');
        } elseif ($type === 'moved') {
            $this->records->appendForExit($ctx, $to, 'livestock_move_in', ['head_count' => $head, 'source_cycle_id' => $from->id], $head, $when,
                $this->key($data['idempotency_key'].'#in'), RequestHash::of(['move_in' => $data]), $data['notes'] ?? null, $record);
        }

        return ['result' => $this->present($ctx, $record, $from->id), 'sale' => null];
    }

    // ------------------------------------------------------------------ list

    public function list(FarmContext $ctx, array $input): LengthAwarePaginator
    {
        $ctx->authorize(Permission::RecordView);
        $f = Validator::make($input, (new ListLivestockExitsRequest)->rules())->validate();
        $q = OperationalRecord::where('farm_id', $ctx->farm->id)->whereDoesntHave('reversal')
            ->where(fn ($w) => $w->whereIn('type', self::EXIT_RECORD_TYPES));
        if (isset($f['production_cycle_id'])) {
            ProductionCycle::ofFarm($ctx->farm)->findOrFail($f['production_cycle_id']);
            $q->where('production_cycle_id', $f['production_cycle_id']);
        }
        if (isset($f['type'])) {
            match ($f['type']) {
                'sold' => $q->where('type', 'livestock_sale'),
                'died' => $q->where('type', 'mortality'),
                'moved' => $q->where('type', 'livestock_exit')->where('details->exit_type', 'moved_out'),
                default => $q->where('type', 'livestock_exit')->where('details->exit_type', $f['type']),
            };
        }
        if (isset($f['recorded_from'])) {
            $q->where('recorded_at', '>=', CarbonImmutable::parse($f['recorded_from'], $ctx->farm->timezone)->startOfDay()->utc());
        }
        if (isset($f['recorded_to'])) {
            $q->where('recorded_at', '<', CarbonImmutable::parse($f['recorded_to'], $ctx->farm->timezone)->addDay()->startOfDay()->utc());
        }
        $page = $q->orderByDesc('recorded_at')->orderByDesc('id')->paginate($f['per_page'] ?? 50);
        $rows = $this->rows($ctx, $page->getCollection());
        $page->setCollection(collect($rows));

        return $page;
    }

    // ------------------------------------------------------------------ reverse

    /**
     * Undo an exit. died, culled, given away: the ordinary record reversal. slaughtered: the reversal also takes the meat back out of
     * stock (409 insufficient_stock when it has since been used or sold). moved: both sides are reversed together. sold: 409, cancel the sale.
     *
     * @return array<string, mixed>
     */
    public function reverse(FarmContext $ctx, string $id, array $input): array
    {
        $ctx->authorize(Permission::RecordReverse);
        $data = Validator::make($input, (new ReverseRecordRequest)->rules())->validate();

        return DB::transaction(function () use ($ctx, $id, $data) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $original = OperationalRecord::where('farm_id', $ctx->farm->id)->whereIn('type', [...self::EXIT_RECORD_TYPES, 'livestock_move_in'])->findOrFail($id);
            $hash = RequestHash::of(['livestock_exit_reverse' => $id] + $data);
            $primaryKey = $this->key($data['idempotency_key'].'#'.$original->id, 'lr:');
            $existing = OperationalRecord::where('farm_id', $ctx->farm->id)->where('idempotency_key', $primaryKey)->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw new ApiHttpException(409, 'idempotency_conflict', 'This idempotency key was already used for a different request.');
                }

                return $this->reversalResult($ctx, $original, $existing);
            }
            if ($original->type === 'livestock_sale') {
                throw new ApiHttpException(409, 'reverse_via_sale', 'This population effect belongs to a sale; cancel the sale instead.', details: ['sale_id' => $original->details['sale_id'] ?? null]);
            }
            $pair = [$original];
            if ($original->type === 'livestock_move_in') {
                $pair[] = OperationalRecord::where('farm_id', $ctx->farm->id)->findOrFail($original->linked_record_id);
            } elseif (($original->details['exit_type'] ?? null) === 'moved_out') {
                $pair[] = OperationalRecord::where('farm_id', $ctx->farm->id)->where('linked_record_id', $original->id)->firstOrFail();
            }
            $cycles = [];
            foreach (collect($pair)->pluck('production_cycle_id')->unique()->sort()->values() as $cycleId) {
                $cycles[$cycleId] = ProductionCycle::ofFarm($ctx->farm)->with('livestock')->lockForUpdate()->findOrFail($cycleId);
            }
            $reversals = [];
            foreach ($pair as $record) {
                $reverseData = $data;
                $reverseData['idempotency_key'] = $this->key($data['idempotency_key'].'#'.$record->id, 'lr:');
                $reversals[$record->id] = $this->records->reverseLocked($ctx, $record, $cycles[$record->production_cycle_id], $reverseData, $record->id === $original->id ? $hash : RequestHash::of(['livestock_exit_reverse_pair' => $record->id] + $data));
            }

            return $this->reversalResult($ctx, $original, $reversals[$original->id]);
        }, 3);
    }

    private function reversalResult(FarmContext $ctx, OperationalRecord $original, OperationalRecord $reversal): array
    {
        $partner = $original->type === 'livestock_move_in'
            ? OperationalRecord::where('farm_id', $ctx->farm->id)->with('reversal')->find($original->linked_record_id)
            : OperationalRecord::where('farm_id', $ctx->farm->id)->where('linked_record_id', $original->id)->with('reversal')->first();

        return [
            'reversed_record_id' => $original->id, 'type' => $this->typeOf($original), 'reversal' => $reversal,
            'linked_reversal' => $partner?->reversal, 'head_count' => $this->headCount($original->production_cycle_id),
            'close_batch_suggested' => $this->headCount($original->production_cycle_id) === 0,
        ];
    }

    // ------------------------------------------------------------------ presentation

    /** @return array<string, mixed> */
    private function present(FarmContext $ctx, OperationalRecord $record, string $cycleId): array
    {
        $record->loadMissing('reversal');
        $event = $this->rows($ctx, collect([$record]))[0];
        $count = $this->headCount($cycleId);
        $out = ['type' => $event['type'], 'label' => $event['label'], 'production_cycle_id' => $cycleId, 'head_count' => $count, 'close_batch_suggested' => $count === 0, 'event' => $event, 'meat' => null, 'destination' => null];
        if (($record->details['exit_type'] ?? null) === 'slaughtered') {
            $item = InventoryItem::ofFarm($ctx->farm)->find($record->details['inventory']['item_id']);
            $out['meat'] = ['inventory_item_id' => $item?->id, 'name' => $item?->name, 'quantity_kg' => $record->details['meat_weight_kg'], 'storage_location_id' => $record->details['inventory']['storage_location_id']];
        }
        if (($record->details['exit_type'] ?? null) === 'moved_out') {
            $to = $record->details['destination_cycle_id'];
            $out['destination'] = ['production_cycle_id' => $to, 'reference' => ProductionCycle::whereKey($to)->value('reference'), 'head_count' => $this->headCount($to)];
        }

        return $out;
    }

    /**
     * One list row per exit record, with its source link. Looked up in batches, nothing is copied: sale references and amounts come from
     * the sale, destination names from the destination batch, the meat item name from inventory.
     *
     * @param  Collection<int, OperationalRecord>  $records
     * @return list<array<string, mixed>>
     */
    private function rows(FarmContext $ctx, Collection $records): array
    {
        $sales = Sale::where('farm_id', $ctx->farm->id)->whereIn('id', $records->pluck('details.sale_id')->filter()->all())->get()->keyBy('id');
        $items = SaleItem::whereIn('id', $records->pluck('details.sale_item_id')->filter()->all())->get()->keyBy('id');
        $cycles = ProductionCycle::ofFarm($ctx->farm)->whereIn('id', $records->pluck('details.destination_cycle_id')->filter()->all())->get()->keyBy('id');
        $meat = InventoryItem::ofFarm($ctx->farm)->whereIn('id', $records->pluck('details.inventory.item_id')->filter()->all())->get()->keyBy('id');

        return $records->map(function (OperationalRecord $r) use ($sales, $items, $cycles, $meat) {
            $type = $this->typeOf($r);
            $d = $r->details;
            [$details, $source] = match ($type) {
                'sold' => [
                    ['sale_id' => $d['sale_id'] ?? null, 'sale_reference' => $sales[$d['sale_id'] ?? '']->reference ?? null, 'buyer' => $sales[$d['sale_id'] ?? '']->customer_name ?? null, 'amount' => isset($items[$d['sale_item_id'] ?? '']) ? (string) $items[$d['sale_item_id']]->amount : null],
                    ['type' => 'sale', 'id' => $d['sale_id'] ?? null, 'reference' => $sales[$d['sale_id'] ?? '']->reference ?? null],
                ],
                'died' => [['cause' => $d['cause'] ?? null], ['type' => 'operational_record', 'id' => $r->id, 'reference' => null]],
                'slaughtered' => [
                    ['meat_weight_kg' => $d['meat_weight_kg'] ?? null, 'live_weight_kg' => $d['live_weight_kg'] ?? null, 'meat_item_id' => $d['inventory']['item_id'] ?? null, 'meat_item' => $meat[$d['inventory']['item_id'] ?? '']->name ?? null],
                    ['type' => 'operational_record', 'id' => $r->id, 'reference' => null],
                ],
                'culled' => [['reason' => $d['reason'] ?? null], ['type' => 'operational_record', 'id' => $r->id, 'reference' => null]],
                'given_away' => [['given_to' => $d['given_to'] ?? null], ['type' => 'operational_record', 'id' => $r->id, 'reference' => null]],
                'moved' => [
                    ['destination_cycle_id' => $d['destination_cycle_id'] ?? null, 'destination' => $cycles[$d['destination_cycle_id'] ?? '']->name ?? null],
                    ['type' => 'production_cycle', 'id' => $d['destination_cycle_id'] ?? null, 'reference' => $cycles[$d['destination_cycle_id'] ?? '']->reference ?? null],
                ],
            };

            return [
                'id' => $r->id, 'production_cycle_id' => $r->production_cycle_id, 'type' => $type, 'label' => self::LABELS[$type], 'head_count' => abs($r->population_delta),
                'recorded_at' => $r->recorded_at->toISOString(), 'notes' => $r->notes, 'details' => $details, 'source' => $source,
                'undo' => $type === 'sold' ? 'cancel_sale' : 'reverse', 'reversed' => $r->reversal !== null,
            ];
        })->values()->all();
    }

    private function typeOf(OperationalRecord $r): string
    {
        return match ($r->type) {
            'livestock_sale' => 'sold', 'mortality' => 'died',
            'livestock_move_in' => 'moved',
            default => ($r->details['exit_type'] ?? '') === 'moved_out' ? 'moved' : $r->details['exit_type'],
        };
    }

    // ------------------------------------------------------------------ helpers

    private function headCount(string $cycleId): int
    {
        return (int) PopulationMovement::where('production_cycle_id', $cycleId)->sum('quantity');
    }

    /** Internal key derived from the request key, short enough for the 80-character column. */
    private function key(string $requestKey, string $prefix = 'le:'): string
    {
        return $prefix.substr(hash('sha256', $requestKey), 0, 40);
    }

    private function replayed(FarmContext $ctx, string $key, string $hash): ?OperationalRecord
    {
        $record = OperationalRecord::where('farm_id', $ctx->farm->id)->where('idempotency_key', $key)->first();
        if ($record && ! hash_equals($record->request_hash, $hash)) {
            throw new ApiHttpException(409, 'idempotency_conflict', 'This idempotency key was already used for a different request.');
        }

        return $record;
    }

    /** A key already used by the other kind of writer (a sale vs a record) means the same key came with a different type: refuse instead of recording twice. */
    private function assertKeyFree(FarmContext $ctx, string $key, bool $sold): void
    {
        $taken = $sold
            ? OperationalRecord::where('farm_id', $ctx->farm->id)->where('idempotency_key', $key)->exists()
            : Sale::where('farm_id', $ctx->farm->id)->where('idempotency_key', $key)->exists();
        if ($taken) {
            throw new ApiHttpException(409, 'idempotency_conflict', 'This idempotency key was already used for a different request.');
        }
    }

    private function assertOpenLivestock(ProductionCycle $cycle, string $field): void
    {
        if ($cycle->kind !== CycleKind::Livestock) {
            $this->invalid($field, 'Animals can only leave or arrive in a livestock batch.');
        }
        if ($cycle->status !== CycleStatus::Active) {
            throw new ApiHttpException(409, 'cycle_closed', 'Reopen the batch before recording animals leaving or arriving.', details: ['production_cycle_id' => $cycle->id, 'field' => $field]);
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
