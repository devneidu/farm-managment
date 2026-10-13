<?php

namespace App\Services\Records;

use App\Enums\Permission;
use App\Services\Inventory\StockReasonCatalogue;
use App\Support\Api\ApiRoute;

/**
 * The farm operations catalogue: what a "Records" form for each operation needs, so the frontend renders it without hardcoding whether an
 * operation is IN, OUT, both or a plain record. A static composition - there is no table and no rule of its own:
 *
 *  - RecordTypeRegistry is authoritative for record types (cycle kind, capability, fields, measurement, permissions, stock link);
 *  - StockReasonCatalogue is authoritative for stock reasons (which are manual, which belong to another workflow).
 *
 * This class only adds the grouping (egg_collection + eggs stock = "eggs"), labels and the display mode, which is DERIVED from the
 * directions an operation's actions have. Stock-changing actions reuse the existing endpoints; the catalogue never writes anything.
 */
class OperationCatalogue
{
    public const NORMAL = 'normal';

    public const IN_ONLY = 'in_only';

    public const OUT_ONLY = 'out_only';

    public const IN_OUT = 'in_out';

    /** Operations that are one operation over a record type AND the farm's pooled output stock. */
    private const OUTPUTS = ['eggs' => ['record' => 'egg_collection', 'label' => 'Eggs', 'unit_label' => 'eggs'], 'milk' => ['record' => 'milk', 'label' => 'Milk', 'unit_label' => 'milk']];

    /** Record types whose record IS the stock event (their details.inventory link stays optional, as in the registry). */
    private const DIRECTIONAL_RECORDS = ['feed_use' => ['direction' => 'out', 'code' => 'used_for_livestock', 'label' => 'Used for livestock', 'reason' => 'production_use'], 'crop_harvest' => ['direction' => 'in', 'code' => 'harvested', 'label' => 'Harvested', 'reason' => 'harvest']];

    private const PRODUCE_OUT = 'produce_out';

    private const LABELS = [
        'feed_use' => 'Feed usage', 'mortality' => 'Mortality', 'weight' => 'Live weight', 'temperature' => 'Temperature', 'water' => 'Water consumption',
        'irrigation' => 'Irrigation', 'weeding' => 'Weeding', 'fertilizer_application' => 'Fertilizer application', 'pesticide_application' => 'Pesticide application',
        'land_preparation' => 'Land preparation', 'planting' => 'Planting', 'establishment_check' => 'Establishment check', 'growth_stage' => 'Growth stage',
        'crop_loss' => 'Crop loss', 'crop_harvest' => 'Crop harvest', 'pest_observation' => 'Pest observation', 'general_note' => 'General note',
        'population_adjustment' => 'Population adjustment', self::PRODUCE_OUT => 'Crop produce out',
    ];

    public function __construct(private readonly RecordTypeRegistry $types, private readonly StockReasonCatalogue $reasons) {}

    /** @return list<array<string, mixed>> one summary per operation */
    public function all(): array
    {
        return array_map(fn (array $op) => $this->summary($op), $this->operations());
    }

    /** @return array<string, mixed>|null */
    public function find(string $code): ?array
    {
        foreach ($this->operations() as $op) {
            if ($op['code'] === $code) {
                return $this->detail($op);
            }
        }

        return null;
    }

    // --------------------------------------------------------------- composition

    /** @return list<array<string, mixed>> */
    private function operations(): array
    {
        $ops = [];
        $absorbed = array_column(self::OUTPUTS, 'record');
        foreach (self::OUTPUTS as $kind => $meta) {
            $ops[] = ['code' => $kind, 'label' => $meta['label'], 'record_type' => $meta['record'], 'inventory_kind' => $kind, 'actions' => $this->outputActions($kind, $meta['record'])];
        }
        foreach (array_keys($this->types->definitions()) as $type) {
            if (in_array($type, $absorbed, true)) {
                continue;
            }
            $ops[] = ['code' => $type, 'label' => $this->label($type), 'record_type' => $type, 'inventory_kind' => match ($type) {
                'feed_use' => StockReasonCatalogue::KIND_FEED, 'crop_harvest' => 'produce', default => null,
            }, 'actions' => [$this->recordAction($type, self::DIRECTIONAL_RECORDS[$type] ?? null)]];
            if ($type === 'crop_harvest') {
                $ops[] = ['code' => self::PRODUCE_OUT, 'label' => self::LABELS[self::PRODUCE_OUT], 'record_type' => null, 'inventory_kind' => 'produce', 'actions' => $this->produceOutActions()];
            }
        }

        return $ops;
    }

    private function label(string $type): string
    {
        return self::LABELS[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }

    // ------------------------------------------------------------------ views

    /** @param array<string, mixed> $op */
    private function summary(array $op): array
    {
        $schema = $op['record_type'] !== null ? $this->types->schema($op['record_type']) : null;

        return ['code' => $op['code'], 'label' => $op['label'], 'display_mode' => $this->displayMode($op['actions']),
            'cycle_kind' => $schema['cycle_kind'] ?? null, 'capability' => $schema['capability'] ?? null, 'inventory_kind' => $op['inventory_kind']];
    }

    /** @param array<string, mixed> $op */
    private function detail(array $op): array
    {
        $schema = $op['record_type'] !== null ? $this->types->schema($op['record_type']) : null;
        $actions = ['in' => [], 'out' => [], 'record' => []];
        $redirects = [];
        foreach ($op['actions'] as $action) {
            if (($action['submission']['kind'] ?? null) === 'workflow') {
                $redirects[] = $action;

                continue;
            }
            $actions[$action['direction'] ?? 'record'][] = $action;
        }

        return $this->summary($op) + [
            'record_type' => $op['record_type'],
            'measurement' => $schema['measurement'] ?? ($op['code'] === self::PRODUCE_OUT ? ['dimension' => 'weight|volume|count', 'display_unit' => null, 'normalized_unit' => null, 'required' => true, 'components_max' => 10] : null),
            'units_endpoint' => ($dimension = $schema['measurement']['dimension'] ?? null) ? ApiRoute::url('/master/units?dimension='.$dimension) : ApiRoute::url('/master/units'),
            'directions' => array_values(array_filter(['in', 'out'], fn ($d) => $actions[$d] !== [])),
            'actions' => ['in' => $actions['in'], 'out' => $actions['out'], 'record' => $actions['record']],
            /** Events another workflow owns (sales, incubation): offer them as links, never as a stock-out form. */
            'workflow_redirects' => $redirects,
            'population_effect' => $schema['population_effect'] ?? 'none',
            'balance' => $this->balance($op),
        ];
    }

    /** @param list<array<string, mixed>> $actions */
    private function displayMode(array $actions): string
    {
        $in = $out = false;
        foreach ($actions as $action) {
            if (($action['submission']['kind'] ?? null) === 'workflow') {
                continue;
            }
            $in = $in || $action['direction'] === 'in';
            $out = $out || $action['direction'] === 'out';
        }

        return match (true) {
            $in && $out => self::IN_OUT, $in => self::IN_ONLY, $out => self::OUT_ONLY, default => self::NORMAL,
        };
    }

    /** @param array<string, mixed> $op */
    private function balance(array $op): ?array
    {
        return match (true) {
            isset(self::OUTPUTS[$op['code']]) => ['method' => 'GET', 'url' => ApiRoute::url('/inventory/output-balances'), 'permission' => Permission::InventoryView->value],
            $op['inventory_kind'] === 'produce' && $op['code'] === self::PRODUCE_OUT => ['method' => 'GET', 'url' => ApiRoute::url('/inventory/items?category=produce'), 'permission' => Permission::InventoryView->value],
            default => null,
        };
    }

    // ---------------------------------------------------------------- actions

    /** @return list<array<string, mixed>> */
    private function outputActions(string $kind, string $recordType): array
    {
        $options = $this->reasons->options()['by_item_kind'][$kind];
        $actions = [$this->recordAction($recordType, ['direction' => 'in', 'code' => 'collected', 'label' => 'Collected', 'reason' => 'production'], op: $kind)];
        foreach ($options['in'] as $entry) {
            if ($entry['manual'] === true && ! str_starts_with($entry['code'], 'transfer') && $entry['code'] !== 'adjustment') {
                $actions[] = $this->stockAction('in', $entry, ['output' => $kind], cycle: 'not_applicable', output: $kind);
            }
        }
        foreach ($options['out'] as $entry) {
            if ($entry['code'] === 'transfer_out' || $entry['code'] === 'adjustment') {
                continue;
            }
            $actions[] = $entry['manual'] === true
                ? $this->stockAction('out', $entry, ['output' => $kind], cycle: 'optional', output: $kind)
                : $this->redirect($entry);
        }

        return $actions;
    }

    /** @return list<array<string, mixed>> */
    private function produceOutActions(): array
    {
        $actions = [];
        foreach ($this->reasons->options()['by_item_kind'][StockReasonCatalogue::KIND_GENERAL]['out'] as $entry) {
            if (in_array($entry['code'], ['transfer_out', 'adjustment', 'use', 'expired'], true)) {
                continue; // routine "use" and shelf-life expiry are not produce events; the manual reasons below are
            }
            if (isset($entry['only_for_categories']) && ! in_array('produce', $entry['only_for_categories'], true)) {
                continue;
            }
            $actions[] = $entry['manual'] === true ? $this->stockAction('out', $entry, [], cycle: 'optional', category: 'produce') : $this->redirect($entry);
        }

        return $actions;
    }

    /** A record-type submission (POST /records). `$directional` marks the record as the stock event itself. */
    private function recordAction(string $type, ?array $directional, ?string $op = null): array
    {
        $schema = $this->types->schema($type);
        $required = ['production_cycle_id', 'recorded_at', 'idempotency_key'];
        $optional = ['notes', 'corrects_record_id'];
        foreach ($schema['fields'] as $key => $rules) {
            if (in_array('required', $rules, true)) {
                $required[] = 'details.'.$key;
            } else {
                $optional[] = 'details.'.$key;
            }
        }
        if ($schema['measurement'] !== null) {
            $schema['measurement']['required'] ? $required[] = 'details.components' : $optional[] = 'details.components';
            $optional[] = 'details.context';
        }
        $inventory = $schema['inventory'];

        return [
            'code' => $directional['code'] ?? 'record', 'label' => $directional['label'] ?? 'Record '.strtolower($this->label($op ?? $type)),
            'direction' => $directional['direction'] ?? null, 'reason' => $directional['reason'] ?? null,
            'creates_operational_record' => true,
            'submission' => ['kind' => 'record', 'method' => 'POST', 'path' => '/records', 'url' => ApiRoute::url('/records'), 'record_type' => $type, 'body_defaults' => ['type' => $type]],
            'production_cycle' => 'required',
            'fields' => ['required' => $required, 'optional' => $optional],
            'permissions_required' => $schema['permissions_required'],
            'inventory_effect' => $inventory,
            'commercial_effects' => $inventory === null ? null : ['sale' => false, 'invoice' => false, 'payment' => false],
            'reversal' => ['method' => 'POST', 'url' => ApiRoute::url('/records/{id}/reverse'), 'permission' => Permission::RecordReverse->value, 'note' => 'Reversing the record compensates its stock in the same transaction.'],
        ];
    }

    /**
     * A manual stock movement through the existing inventory endpoints.
     *
     * @param  array<string, mixed>  $entry  an entry of StockReasonCatalogue::options()['by_item_kind']
     * @param  array<string, string>  $defaults
     */
    private function stockAction(string $direction, array $entry, array $defaults, string $cycle, ?string $output = null, ?string $category = null): array
    {
        $in = $direction === 'in';
        $required = array_merge($output === null ? ['inventory_item_id', 'storage_location_id'] : [], ['components', 'recorded_at', 'idempotency_key']);
        $optional = array_merge($output !== null ? ['storage_location_id'] : ['lot_id'], $in ? ['lot'] : [], $cycle === 'optional' ? ['production_cycle_id'] : [], ['notes']);

        return [
            'code' => $entry['code'], 'label' => $entry['label'], 'direction' => $direction, 'reason' => $entry['code'], 'creates_operational_record' => false,
            'submission' => ['kind' => 'inventory', 'method' => 'POST', 'path' => '/inventory/stock-'.$direction, 'url' => ApiRoute::url('/inventory/stock-'.$direction), 'body_defaults' => $defaults + ['reason' => $entry['code']]],
            'production_cycle' => $cycle,
            'fields' => ['required' => $required, 'optional' => $optional],
            'permissions_required' => ['always' => [($in ? Permission::InventoryManage : Permission::InventoryUse)->value], 'when_inventory_linked' => [], 'when_correcting' => []],
            'inventory_effect' => ['mode' => 'automatic', 'direction' => $direction, 'output' => $output, 'item_category' => $output !== null ? 'produce' : $category, 'optional' => false, 'creates_item' => false],
            /** A Records / Inventory movement never creates a sale, invoice, customer transaction or payment. */
            'commercial_effects' => ['sale' => false, 'invoice' => false, 'payment' => false],
            'reversal' => ['method' => 'POST', 'url' => ApiRoute::url('/inventory/movements/{id}/reverse'), 'permission' => Permission::InventoryAdjust->value, 'note' => 'Appends a compensating movement; refused (409 insufficient_stock) when it would take stock below zero.'],
        ];
    }

    /** A reason owned by another workflow (sale, incubation): shown as a pointer, never as a form. */
    private function redirect(array $entry): array
    {
        $route = $entry['route'];

        return ['code' => $entry['code'], 'label' => $entry['label'], 'direction' => 'out', 'reason' => $entry['code'], 'creates_operational_record' => false,
            'submission' => ['kind' => 'workflow', 'method' => $route['method'], 'path' => $route['path'], 'url' => $route['url'], 'note' => $route['note'] ?? null]];
    }
}
