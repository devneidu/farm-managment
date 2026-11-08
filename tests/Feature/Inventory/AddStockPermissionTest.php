<?php

namespace Tests\Feature\Inventory;

use App\Enums\FarmRole;
use App\Models\InventoryMovement;
use App\Models\OperationType;
use App\Models\Species;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Team\TeamTestCase;

/** inventory.add (stock-in incl. new_item, opening balance) is held by Owner/Manager/Farm Worker; adjust, transfer and item edits stay with inventory.manage/adjust; health medicine use needs only health.create. */
class AddStockPermissionTest extends TeamTestCase
{
    private function at(int $hoursAgo = 1): string
    {
        return now()->utc()->subHours($hoursAgo)->format('Y-m-d\TH:i:s\Z');
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    private function newMedicine(string $reason = 'purchase'): TestResponse
    {
        return $this->postJson('/api/v1/inventory/stock-in', ['new_item' => ['name' => 'Oxytet '.Str::random(5), 'category' => 'medicine', 'unit' => 'ml'], 'reason' => $reason,
            'components' => [['quantity' => '500', 'unit' => 'ml']], 'recorded_at' => $this->at(48), 'idempotency_key' => $this->key()]);
    }

    private function stock(string $item): string
    {
        return $this->getJson('/api/v1/inventory/items/'.$item)->assertOk()->json('data.stock.quantity');
    }

    private function cycle(): string
    {
        return $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => 'Layer flock', 'operation_type_id' => OperationType::where('code', 'poultry')->firstOrFail()->id,
            'species_id' => Species::where('code', 'chicken')->firstOrFail()->id, 'production_purpose' => 'breeding', 'initial_population' => 100, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
    }

    private function vaccinate(string $cycle, string $item, string $qty): TestResponse
    {
        return $this->postJson('/api/v1/health-records', ['production_cycle_id' => $cycle, 'type' => 'vaccination', 'recorded_at' => $this->at(2), 'idempotency_key' => $this->key(), 'details' => ['target_disease' => 'Newcastle disease'],
            'medicines' => [['inventory_item_id' => $item, 'components' => [['quantity' => $qty, 'unit' => 'ml']]]]]);
    }

    public function test_a_farm_worker_can_stock_in_an_existing_item_and_create_one_through_new_item(): void
    {
        $this->signInAs($this->owner);
        $item = $this->postJson('/api/v1/inventory/items', ['name' => 'Layer mash', 'category' => 'feed', 'stock_unit' => 'kg'])->assertCreated()->json('data.id');

        $this->signInAs($this->member(FarmRole::FarmWorker));
        $this->postJson('/api/v1/inventory/stock-in', ['inventory_item_id' => $item, 'reason' => 'purchase', 'components' => [['quantity' => '25', 'unit' => 'kg']], 'recorded_at' => $this->at(), 'idempotency_key' => $this->key()])->assertCreated();
        $this->assertSame('25', $this->stock($item));

        $created = $this->newMedicine()->assertCreated()->assertJsonPath('data.item_reused', false);
        $this->assertSame('500', $this->stock($created->json('data.inventory_item_id')));
        $this->newMedicine('opening_balance')->assertCreated(); // opening balance is covered too
    }

    public function test_a_farm_worker_is_refused_adjustments_transfers_item_edits_and_direct_item_creation(): void
    {
        $this->signInAs($this->owner);
        $item = $this->newMedicine()->assertCreated()->json('data.inventory_item_id');

        $this->signInAs($this->member(FarmRole::FarmWorker));
        $this->postJson('/api/v1/inventory/adjustments', ['inventory_item_id' => $item, 'expected' => [['quantity' => '500', 'unit' => 'ml']], 'counted' => [['quantity' => '490', 'unit' => 'ml']], 'reason' => 'count', 'recorded_at' => $this->at(), 'idempotency_key' => $this->key()])->assertForbidden();
        $this->postJson('/api/v1/inventory/transfers', ['inventory_item_id' => $item, 'components' => [['quantity' => '1', 'unit' => 'ml']], 'recorded_at' => $this->at(), 'idempotency_key' => $this->key(), 'from_storage_location_id' => (string) Str::uuid(), 'to_storage_location_id' => (string) Str::uuid()])->assertForbidden();
        $this->patchJson('/api/v1/inventory/items/'.$item, ['name' => 'Renamed'])->assertForbidden();
        $this->patchJson('/api/v1/inventory/items/'.$item, ['is_active' => false])->assertForbidden();
        $this->postJson('/api/v1/inventory/items', ['name' => 'Direct item', 'category' => 'feed', 'stock_unit' => 'kg'])->assertForbidden(); // only the popup path creates items for a worker
    }

    public function test_can_add_stock_is_true_for_owner_manager_worker_and_false_for_vet_and_finance(): void
    {
        $expected = ['owner' => true, 'manager' => true, 'farm_worker' => true, 'vet' => false, 'finance' => false];
        foreach (FarmRole::cases() as $role) {
            $this->signInAs($role === FarmRole::Owner ? $this->owner : $this->member($role));
            $this->getJson('/api/v1/farm')->assertOk()->assertJsonPath('data.membership.can_add_stock', $expected[$role->value]);
            $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.farm.can_add_stock', $expected[$role->value]);
            if (! $expected[$role->value]) {
                $this->newMedicine()->assertForbidden();
            }
        }
    }

    /** @return array<string, array{0: bool}> */
    public static function switchModes(): array
    {
        return ['switch OFF' => [false], 'switch ON' => [true]];
    }

    #[DataProvider('switchModes')]
    public function test_vet_and_worker_record_a_vaccination_with_a_stock_medicine_and_stock_goes_down(bool $on): void
    {
        $this->signInAs($this->owner);
        $cycle = $this->cycle();
        $item = $this->newMedicine()->assertCreated()->json('data.inventory_item_id');
        $this->patchJson('/api/v1/farm', ['settings' => ['use_stock_for_usage' => $on]])->assertOk();

        $expected = 500;
        foreach ([FarmRole::Vet, FarmRole::FarmWorker] as $role) {
            $this->signInAs($this->member($role, null, $role->value));
            $this->vaccinate($cycle, $item, '20')->assertCreated();
            $expected -= 20;
            $this->assertSame((string) $expected, $this->stock($item), $role->value);
        }
        $this->assertSame(2, InventoryMovement::whereNotNull('health_record_id')->count());
    }

    public function test_a_vet_uses_store_medicine_and_reverses_it_but_cannot_do_a_plain_stock_out(): void
    {
        $this->signInAs($this->owner);
        $cycle = $this->cycle();
        $item = $this->newMedicine()->assertCreated()->json('data.inventory_item_id');

        $this->signInAs($this->member(FarmRole::Vet));
        $record = $this->vaccinate($cycle, $item, '30')->assertCreated()->json('data.id');
        $this->assertSame('470', $this->stock($item));

        $this->postJson('/api/v1/inventory/stock-out', ['inventory_item_id' => $item, 'reason' => 'internal_use', 'components' => [['quantity' => '1', 'unit' => 'ml']], 'recorded_at' => $this->at(), 'idempotency_key' => $this->key()])->assertForbidden();
        $this->assertSame('470', $this->stock($item));

        $this->postJson('/api/v1/health-records/'.$record.'/reverse', ['reason' => 'Wrong flock', 'recorded_at' => $this->at(1), 'idempotency_key' => $this->key()])->assertCreated();
        $this->assertSame('500', $this->stock($item));
    }
}
