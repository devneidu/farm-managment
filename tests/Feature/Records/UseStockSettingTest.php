<?php

namespace Tests\Feature\Records;

use App\Models\CropType;
use App\Models\InventoryMovement;
use App\Models\OperationalRecord;
use App\Models\OperationType;
use App\Models\Species;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Team\TeamTestCase;

/** "Take everything used from my store": feed / fertilizer / pesticide / planting records follow farm.settings.use_stock_for_usage. */
class UseStockSettingTest extends TeamTestCase
{
    private const NEED_ITEM = 'Your farm takes items from the store. Pick the item you used.';

    private string $livestock;

    private string $crop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAs($this->owner);
        $this->livestock = $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => 'Layer flock', 'operation_type_id' => OperationType::where('code', 'poultry')->firstOrFail()->id,
            'species_id' => Species::where('code', 'chicken')->firstOrFail()->id, 'production_purpose' => 'breeding', 'initial_population' => 100, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
        $this->crop = $this->postJson('/api/v1/production-cycles', ['kind' => 'crop', 'name' => 'Yam', 'operation_type_id' => OperationType::where('code', 'crops')->firstOrFail()->id,
            'crop_type_id' => CropType::where('code', 'yam')->firstOrFail()->id, 'initial_planting_units' => 800, 'planting_unit_type' => 'heap', 'planting_material_type' => 'tuber', 'planting_date' => '2026-01-01'])->assertCreated()->json('data.id');
    }

    private function setting(bool $on): void
    {
        $this->patchJson('/api/v1/farm', ['settings' => ['use_stock_for_usage' => $on]])->assertOk()->assertJsonPath('data.settings.use_stock_for_usage', $on);
    }

    /** Adds stock through the "+ Add to store" path (new_item, no store named) and returns the item id the form would auto-select. */
    private function addToStore(string $name, string $category, string $unit = 'kg', string $qty = '100'): string
    {
        return $this->postJson('/api/v1/inventory/stock-in', ['new_item' => ['name' => $name, 'category' => $category, 'unit' => $unit], 'reason' => 'purchase',
            'components' => [['quantity' => $qty, 'unit' => $unit]], 'recorded_at' => '2026-01-15T08:00:00Z', 'idempotency_key' => (string) Str::uuid()])
            ->assertCreated()->json('data.inventory_item_id');
    }

    private function stock(string $item): string
    {
        return $this->getJson('/api/v1/inventory/items/'.$item)->assertOk()->json('data.stock.quantity');
    }

    private function record(string $cycle, string $type, array $details)
    {
        return $this->postJson('/api/v1/records', ['production_cycle_id' => $cycle, 'type' => $type, 'details' => $details, 'recorded_at' => '2026-02-01T10:00:00Z', 'idempotency_key' => (string) Str::uuid()]);
    }

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> type, item category, extra details */
    public static function usageTypes(): array
    {
        return [
            'feed_use' => ['feed_use', 'feed', ['feed_name' => 'x']],
            'fertilizer_application' => ['fertilizer_application', 'fertilizer_agrochemical', ['method' => 'broadcast']],
            'pesticide_application' => ['pesticide_application', 'fertilizer_agrochemical', ['method' => 'spray', 'product_type' => 'insecticide']],
            'planting' => ['planting', 'seed_planting_material', ['units_planted' => 100]],
        ];
    }

    private function cycleFor(string $type): string
    {
        return $type === 'feed_use' ? $this->livestock : $this->crop;
    }

    /** details for a store-linked usage record: only the item and the quantity (no unit; it comes from the item). */
    private function linked(string $type, string $item, array $extra): array
    {
        $details = ['inventory' => ['item_id' => $item], 'components' => [['quantity' => '4']]];
        if ($type === 'feed_use') {
            return $details; // the feed's name comes from the item too
        }

        return $details + array_diff_key($extra, ['feed_name' => 1]);
    }

    public function test_the_setting_defaults_off_for_existing_and_new_farms(): void
    {
        $this->getJson('/api/v1/farm')->assertOk()->assertJsonPath('data.settings.use_stock_for_usage', false);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.farm.settings.use_stock_for_usage', false);
    }

    #[DataProvider('usageTypes')]
    public function test_on_requires_an_item_and_takes_stock_out(string $type, string $category, array $extra): void
    {
        $item = $this->addToStore('Item '.$type, $category);
        $this->setting(true);
        $cycle = $this->cycleFor($type);

        // A typed name is not enough, whatever else is sent.
        $typed = $this->record($cycle, $type, ['item_name' => 'New Thing', 'components' => [['quantity' => '4', 'unit' => 'kg']]] + $extra)
            ->assertStatus(422)->assertJsonValidationErrors('details.inventory');
        $this->assertSame(self::NEED_ITEM, $typed->json('errors')['details.inventory'][0]);
        $bare = $this->record($cycle, $type, $extra)->assertStatus(422);
        $this->assertSame(self::NEED_ITEM, $bare->json('errors')['details.inventory'][0]);
        $this->assertSame('100', $this->stock($item));

        // Item + quantity only: the unit and the store are filled in (item's unit; main store).
        $id = $this->record($cycle, $type, $this->linked($type, $item, $extra))->assertCreated()
            ->assertJsonPath('data.details.item_name', 'Item '.$type)->json('data.id');
        $this->assertSame('96', $this->stock($item));
        $this->assertSame(1, InventoryMovement::where('operational_record_id', $id)->count());

        // Reversing gives the stock back.
        $this->postJson('/api/v1/records/'.$id.'/reverse', ['reason' => 'Wrong', 'recorded_at' => '2026-03-01T10:00:00Z', 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $this->assertSame('100', $this->stock($item));
    }

    #[DataProvider('usageTypes')]
    public function test_off_accepts_a_typed_item_name_and_moves_no_stock(string $type, string $category, array $extra): void
    {
        $item = $this->addToStore('Item '.$type, $category);
        $cycle = $this->cycleFor($type);
        $details = ['item_name' => 'New Feed', 'components' => [['quantity' => '4', 'unit' => 'kg']]] + array_diff_key($extra, ['feed_name' => 1]);

        $record = $this->record($cycle, $type, $details)->assertCreated()->assertJsonPath('data.details.item_name', 'New Feed')->json('data');
        $this->assertSame('100', $this->stock($item));
        $this->assertSame(0, InventoryMovement::where('operational_record_id', $record['id'])->count());
        $this->assertArrayNotHasKey('inventory', $record['details']);
        $this->assertNotNull($record['measurement']);

        // The item_name is capped at 150.
        $this->record($cycle, $type, ['item_name' => str_repeat('a', 151)] + $details)->assertStatus(422)->assertJsonValidationErrors('details.item_name');
    }

    public function test_off_still_links_a_store_item_and_nothing_changes_for_it(): void
    {
        $item = $this->addToStore('Layer Mash', 'feed');
        $this->record($this->livestock, 'feed_use', ['feed_name' => 'Layer Mash', 'inventory' => ['item_id' => $item], 'components' => [['quantity' => '5', 'unit' => 'kg']]])->assertCreated();

        $this->assertSame('95', $this->stock($item));
    }

    public function test_a_typed_name_alone_satisfies_the_old_name_requirement_only_when_off(): void
    {
        $this->record($this->livestock, 'feed_use', ['components' => [['quantity' => '4', 'unit' => 'kg']]])->assertStatus(422)->assertJsonValidationErrors('details.feed_name');
        $this->record($this->livestock, 'feed_use', ['item_name' => 'New Feed', 'components' => [['quantity' => '4', 'unit' => 'kg']]])
            ->assertCreated()->assertJsonPath('data.details.feed_name', 'New Feed');
    }

    public function test_the_setting_never_touches_other_record_types_or_crop_harvest(): void
    {
        $this->setting(true);
        $this->record($this->livestock, 'mortality', ['quantity' => 1, 'cause' => 'Unknown'])->assertCreated();
        $this->record($this->livestock, 'egg_collection', ['components' => [['quantity' => '30', 'unit' => 'piece']]])->assertCreated();
        $this->record($this->crop, 'crop_harvest', ['components' => [['quantity' => '10', 'unit' => 'kg']]])->assertCreated();
        $this->record($this->crop, 'weeding', ['method' => 'hand'])->assertCreated();
    }

    public function test_schema_endpoints_report_inventory_required_for_the_current_farm(): void
    {
        $required = function (string $type): bool {
            return $this->getJson('/api/v1/record-types/'.$type.'/schema')->assertOk()->json('data.inventory_required');
        };
        foreach (['feed_use', 'fertilizer_application', 'pesticide_application', 'planting'] as $type) {
            $this->assertFalse($required($type), $type.' off');
            $this->getJson('/api/v1/record-types/'.$type.'/schema')->assertJsonPath('data.fields.item_name.0', 'sometimes');
        }

        $this->setting(true);
        foreach (['feed_use', 'fertilizer_application', 'pesticide_application', 'planting'] as $type) {
            $this->assertTrue($required($type), $type.' on');
        }
        $this->assertFalse($required('crop_harvest'));
        $this->assertFalse($required('egg_collection'));
        $this->assertFalse($required('weeding'));

        $list = collect($this->getJson('/api/v1/master/record-types')->assertOk()->json('data'))->keyBy('type');
        $this->assertTrue($list['feed_use']['inventory_required']);
        $this->assertFalse($list['crop_harvest']['inventory_required']);

        $action = $this->getJson('/api/v1/master/record-operations/feed_use')->assertOk()->json('data.actions.out.0');
        $this->assertContains('details.inventory', $action['fields']['required']);
        $this->assertNotContains('details.inventory', $action['fields']['optional']);
        $this->assertContains('details.item_name', $action['fields']['optional']);

        $this->setting(false);
        $action = $this->getJson('/api/v1/master/record-operations/feed_use')->assertOk()->json('data.actions.out.0');
        $this->assertNotContains('details.inventory', $action['fields']['required']);
        $this->assertContains('details.inventory', $action['fields']['optional']);
    }

    public function test_the_schema_follows_each_farm_not_the_platform(): void
    {
        $this->setting(true);
        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner)->getJson('/api/v1/record-types/feed_use/schema')->assertOk()->assertJsonPath('data.inventory_required', false);
    }

    public function test_old_records_are_untouched_when_the_setting_is_switched(): void
    {
        $typed = $this->record($this->livestock, 'feed_use', ['item_name' => 'Old Feed', 'components' => [['quantity' => '4', 'unit' => 'kg']]])->assertCreated()->json('data');
        $before = OperationalRecord::findOrFail($typed['id'])->only(['details', 'measurement', 'recorded_at']);

        $this->setting(true);
        $this->setting(false);
        $this->setting(true);

        $this->assertEquals($before, OperationalRecord::findOrFail($typed['id'])->only(['details', 'measurement', 'recorded_at']));
        $this->getJson('/api/v1/records/'.$typed['id'])->assertOk()->assertJsonPath('data.details.item_name', 'Old Feed');
        // Even while ON, an old typed record can still be reversed (it has no stock to give back).
        $this->postJson('/api/v1/records/'.$typed['id'].'/reverse', ['reason' => 'Wrong', 'recorded_at' => '2026-03-01T10:00:00Z', 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $this->assertSame(0, InventoryMovement::where('operational_record_id', $typed['id'])->count());
    }

    public function test_add_to_store_popup_returns_the_item_id_for_auto_select(): void
    {
        $response = $this->postJson('/api/v1/inventory/stock-in', ['new_item' => ['name' => 'Starter Feed', 'category' => 'feed', 'unit' => 'kg'], 'reason' => 'purchase',
            'components' => [['quantity' => '25', 'unit' => 'kg']], 'recorded_at' => '2026-01-15T08:00:00Z', 'idempotency_key' => (string) Str::uuid()])->assertCreated()
            ->assertJsonPath('data.item_reused', false);
        $item = $response->json('data.inventory_item_id');
        $this->getJson('/api/v1/inventory/items/'.$item)->assertOk()->assertJsonPath('data.name', 'Starter Feed')->assertJsonPath('data.stock.quantity', '25');
    }
}
