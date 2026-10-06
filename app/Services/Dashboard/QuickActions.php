<?php

namespace App\Services\Dashboard;

use App\Enums\Capability;
use App\Enums\CycleKind;
use App\Enums\Permission;
use App\Services\Records\RecordTypeRegistry;
use App\Support\Api\ApiRoute;
use Illuminate\Support\Str;

/**
 * "Quick Record" (events that happened, on the viewer's active cycles) and "Quick Add" (create something). Both are filtered by what the
 * viewer may do AND by what the farm actually runs, so a crop-only farm is never offered eggs, mortality, health or breeding.
 */
class QuickActions
{
    /** The mobile Quick Record grid is 2 x 3. */
    public const QUICK_RECORD_LIMIT = 6;

    /** Record types that are corrections or belong to another module's flow, never a quick record. */
    private const EXCLUDED_RECORD_TYPES = ['reversal', 'livestock_sale', 'breeding_outcome', 'population_adjustment'];

    public function __construct(private RecordTypeRegistry $types) {}

    /** @return list<array{type: string, label: string, kind: string|null, applies_to_cycles: int}> */
    public function quickRecord(DashboardFacts $f): array
    {
        if (! $f->ctx->can(Permission::RecordCreate)) {
            return [];
        }
        $cycles = $f->activeCycles();
        $out = [];
        foreach ($this->types->definitions() as $type => $definition) {
            if (in_array($type, self::EXCLUDED_RECORD_TYPES, true)) {
                continue;
            }
            $kind = $definition['kind'] ?? null;
            $applicable = $cycles->filter(fn ($c) => $kind === null || $c->kind->value === $kind);
            if (($definition['capability'] ?? null) !== null) {
                $capability = $definition['capability'];
                $applicable = $applicable->filter(fn ($c) => $c->kind === CycleKind::Livestock && in_array($capability, $f->speciesCapabilities()[$c->livestock?->species_id] ?? [], true));
            }
            if ($applicable->isEmpty()) {
                continue;
            }
            $out[] = ['type' => $type, 'label' => Str::headline($type), 'kind' => $kind, 'applies_to_cycles' => $applicable->count()];
        }

        return array_slice($out, 0, self::QUICK_RECORD_LIMIT);
    }

    /** @return list<array{code: string, label: string, permission: string, method: string, endpoint: string, url: string, path: string}> */
    public function quickAdd(DashboardFacts $f): array
    {
        $livestock = $f->livestockCycles() !== [];
        $actions = [
            ['production_cycle', 'Start a batch or crop project', Permission::ProductionCycleCreate, '/api/v1/production-cycles', true],
            ['task', 'Add a task', Permission::TaskManage, '/api/v1/tasks', true],
            ['sale', 'Record a sale', Permission::SaleCreate, '/api/v1/sales', true],
            ['purchase', 'Record a purchase', Permission::PurchaseCreate, '/api/v1/purchases', true],
            ['expense', 'Record an expense', Permission::FinanceCreate, '/api/v1/expenses', true],
            ['income', 'Record income', Permission::FinanceCreate, '/api/v1/income', true],
            ['stock_in', 'Receive stock', Permission::InventoryManage, '/api/v1/inventory/stock-in', true],
            ['health_record', 'Record a health event', Permission::HealthCreate, '/api/v1/health-records', $livestock],
            ['breeding_project', 'Start a breeding project', Permission::BreedingCreate, '/api/v1/breeding-projects', $f->anyCycleHas(Capability::SupportsBreeding->value)],
            ['contact', 'Add a customer or supplier', Permission::ContactManage, '/api/v1/contacts', true],
        ];
        $out = [];
        foreach ($actions as [$code, $label, $permission, $endpoint, $relevant]) {
            if ($relevant && $f->ctx->can($permission)) {
                $out[] = ['code' => $code, 'label' => $label, 'permission' => $permission->value, 'method' => 'POST', 'endpoint' => $endpoint, 'url' => ApiRoute::url($endpoint), 'path' => ApiRoute::path($endpoint)];
            }
        }

        return $out;
    }
}
