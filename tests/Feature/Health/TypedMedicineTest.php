<?php

namespace Tests\Feature\Health;

use App\Models\HealthRecordMedicine;
use App\Models\InventoryMovement;
use App\Models\OperationType;
use App\Models\Species;
use Illuminate\Support\Str;
use Tests\Feature\Team\TeamTestCase;

/** Health medicine lines follow farm.settings.use_stock_for_usage: OFF allows a typed medicine (no stock); ON takes everything from the store. */
class TypedMedicineTest extends TeamTestCase
{
    private const NEED_MEDICINE = 'Your farm takes medicine from the store. Pick the medicine you used.';

    private string $cycle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAs($this->owner);
        $this->cycle = $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => 'Layer flock', 'operation_type_id' => OperationType::where('code', 'poultry')->firstOrFail()->id,
            'species_id' => Species::where('code', 'chicken')->firstOrFail()->id, 'production_purpose' => 'breeding', 'initial_population' => 100, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
    }

    private function setting(bool $on): void
    {
        $this->patchJson('/api/v1/farm', ['settings' => ['use_stock_for_usage' => $on]])->assertOk();
    }

    private function at(int $hoursAgo = 1): string
    {
        return now()->utc()->subHours($hoursAgo)->format('Y-m-d\TH:i:s\Z');
    }

    /** A medicine in the store (via the "+ Add to store" path) with 500 ml. */
    private function storeMedicine(): string
    {
        return $this->postJson('/api/v1/inventory/stock-in', ['new_item' => ['name' => 'Oxytet '.Str::random(5), 'category' => 'medicine', 'unit' => 'ml'], 'reason' => 'purchase',
            'components' => [['quantity' => '500', 'unit' => 'ml']], 'recorded_at' => $this->at(48), 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data.inventory_item_id');
    }

    private function stock(string $item): string
    {
        return $this->getJson('/api/v1/inventory/items/'.$item)->assertOk()->json('data.stock.quantity');
    }

    private function health(array $medicines, string $type = 'vaccination')
    {
        return $this->postJson('/api/v1/health-records', ['production_cycle_id' => $this->cycle, 'type' => $type, 'recorded_at' => $this->at(2), 'idempotency_key' => (string) Str::uuid(),
            'details' => $type === 'vaccination' ? ['target_disease' => 'Newcastle disease'] : ['condition' => 'Coryza'], 'medicines' => $medicines]);
    }

    private function typed(array $extra = []): array
    {
        return array_replace(['medicine_name' => 'Local herbal mix'], $extra);
    }

    private function stockLine(string $item, string $qty = '10'): array
    {
        return ['inventory_item_id' => $item, 'components' => [['quantity' => $qty, 'unit' => 'ml']]];
    }

    public function test_off_a_typed_medicine_takes_no_stock_and_creates_no_movement(): void
    {
        $item = $this->storeMedicine();

        $record = $this->health([$this->typed(['dose_per_animal' => [['quantity' => '0.5', 'unit' => 'ml']], 'dosage_instructions' => 'In drinking water', 'withdrawal_days' => 7])])->assertCreated()->json('data');

        $line = $record['medicines'][0];
        $this->assertTrue($line['is_typed']);
        $this->assertSame('Local herbal mix', $line['item_name']);
        $this->assertNull($line['inventory_item_id']);
        $this->assertNull($line['storage_location_id']);
        $this->assertNull($line['quantity_used']);
        $this->assertNull($line['inventory_movement_id']);
        $this->assertSame('In drinking water', $line['dosage_instructions']);
        $this->assertSame('0.5', $line['dose_per_animal']['components'][0]['quantity']);
        $this->assertSame(7, $line['withdrawal']['days']);
        $this->assertSame('explicit', $line['withdrawal']['source']);
        $this->assertTrue($line['withdrawal']['is_active']);

        $this->assertSame('500', $this->stock($item));
        $this->assertSame(0, InventoryMovement::whereNotNull('health_record_id')->count());
        $this->assertNull(HealthRecordMedicine::findOrFail($line['id'])->inventory_item_id);
    }

    public function test_typed_withdrawal_is_tracked_in_the_withdrawals_list_and_the_dashboard(): void
    {
        $this->health([$this->typed(['withdrawal_days' => 10])])->assertCreated();
        $this->health([$this->typed(['medicine_name' => 'No days'])])->assertCreated(); // no withdrawal_days: nothing to track

        $rows = $this->getJson('/api/v1/health/withdrawals')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('Local herbal mix', $rows[0]['item_name']);
        $this->assertNull($rows[0]['inventory_item_id']);
        $this->assertSame(10, $rows[0]['days']);
        $this->assertTrue($rows[0]['is_active']);

        $this->getJson('/api/v1/insights')->assertOk()->assertJsonFragment(['code' => 'medicine_withdrawal']);
    }

    public function test_typed_lines_are_validated(): void
    {
        $this->health([['medicine_name' => '']])->assertStatus(422)->assertJsonValidationErrors('medicines.0.medicine_name');
        $this->health([['medicine_name' => str_repeat('m', 151)]])->assertStatus(422)->assertJsonValidationErrors('medicines.0.medicine_name');
        $this->health([$this->typed(['withdrawal_days' => 3651])])->assertStatus(422)->assertJsonValidationErrors('medicines.0.withdrawal_days');
        $this->health([$this->typed(['withdrawal_days' => -1])])->assertStatus(422)->assertJsonValidationErrors('medicines.0.withdrawal_days');
        $this->health([['dosage_instructions' => 'No name and no item']])->assertStatus(422)->assertJsonValidationErrors('medicines.0.inventory_item_id');
        $this->health([$this->typed(['components' => [['quantity' => '5', 'unit' => 'ml']]])])->assertStatus(422)->assertJsonValidationErrors('medicines.0.components');
        $this->health([$this->typed(['inventory_item_id' => (string) Str::uuid(), 'components' => [['quantity' => '5', 'unit' => 'ml']]])])->assertStatus(422)->assertJsonValidationErrors('medicines.0.inventory_item_id');
        $this->health([], 'vaccination')->assertStatus(422)->assertJsonValidationErrors('medicines');
        $this->assertSame(0, HealthRecordMedicine::count());
    }

    public function test_a_stock_line_and_a_typed_line_can_share_one_record(): void
    {
        $item = $this->storeMedicine();

        $record = $this->health([$this->stockLine($item, '40'), $this->typed()], 'treatment')->assertCreated()->json('data');

        $this->assertFalse($record['medicines'][0]['is_typed']);
        $this->assertTrue($record['medicines'][1]['is_typed']);
        $this->assertSame('460', $this->stock($item));
        $this->assertSame(1, InventoryMovement::whereNotNull('health_record_id')->count());
    }

    public function test_on_rejects_a_typed_medicine_and_still_takes_stock_for_items(): void
    {
        $item = $this->storeMedicine();
        $this->setting(true);

        $r = $this->health([$this->typed()])->assertStatus(422)->assertJsonValidationErrors('medicines.0.medicine_name');
        $this->assertSame(self::NEED_MEDICINE, $r->json('errors')['medicines.0.medicine_name'][0]);
        $this->assertSame(0, HealthRecordMedicine::count());

        $this->health([$this->stockLine($item, '25')])->assertCreated();
        $this->assertSame('475', $this->stock($item));
    }

    public function test_a_stock_item_line_is_unchanged_in_both_modes(): void
    {
        $item = $this->storeMedicine();
        $this->health([$this->stockLine($item, '10') + ['withdrawal_days' => 3]])->assertCreated()->assertJsonPath('data.medicines.0.is_typed', false)->assertJsonPath('data.medicines.0.withdrawal.days', 3);
        $this->setting(true);
        $this->health([$this->stockLine($item, '10')])->assertCreated()->assertJsonPath('data.medicines.0.quantity_used.quantity', '10');

        $this->assertSame('480', $this->stock($item));
        $this->assertSame(2, InventoryMovement::whereNotNull('health_record_id')->count());
    }

    public function test_reversing_a_record_with_typed_lines_reverses_nothing_in_stock(): void
    {
        $item = $this->storeMedicine();
        $record = $this->health([$this->stockLine($item, '40'), $this->typed(['withdrawal_days' => 5])], 'treatment')->assertCreated()->json('data');
        $this->assertSame('460', $this->stock($item));

        $reversal = $this->postJson('/api/v1/health-records/'.$record['id'].'/reverse', ['reason' => 'Wrong animal', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data');

        $this->assertSame('500', $this->stock($item)); // only the stock line came back
        $this->assertSame(1, InventoryMovement::where('health_record_id', $reversal['id'])->count());
        $this->assertSame([], $this->getJson('/api/v1/health/withdrawals')->json('data')); // a reversed event no longer counts

        $typedOnly = $this->health([$this->typed()])->assertCreated()->json('data');
        $typedReversal = $this->postJson('/api/v1/health-records/'.$typedOnly['id'].'/reverse', ['reason' => 'Oops', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->assertSame(0, InventoryMovement::where('health_record_id', $typedReversal['id'])->count());
        $this->assertSame('500', $this->stock($item));
    }

    public function test_old_typed_records_survive_switching_the_setting_on(): void
    {
        $record = $this->health([$this->typed(['withdrawal_days' => 4])])->assertCreated()->json('data');
        $this->setting(true);

        $this->getJson('/api/v1/health-records/'.$record['id'])->assertOk()->assertJsonPath('data.medicines.0.item_name', 'Local herbal mix')->assertJsonPath('data.medicines.0.is_typed', true);
        $this->assertCount(1, $this->getJson('/api/v1/health/withdrawals')->json('data'));
        $this->postJson('/api/v1/health-records/'.$record['id'].'/reverse', ['reason' => 'Oops', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertCreated();
    }

    public function test_the_ledger_check_does_not_flag_typed_lines(): void
    {
        $this->health([$this->typed()])->assertCreated();

        $mismatch = \DB::select('SELECT h.id FROM health_record_medicines h LEFT JOIN inventory_movements m ON m.health_record_medicine_id = h.id WHERE h.farm_id = ? AND h.inventory_item_id IS NOT NULL AND m.id IS NULL', [$this->farm->id]);
        $this->assertSame([], $mismatch);
        $this->assertStringContainsString('h.inventory_item_id IS NOT NULL', (string) file_get_contents(app_path('Services/Operations/LedgerReconciler.php')));
    }

    public function test_health_type_schema_follows_the_setting(): void
    {
        $find = fn (string $type) => collect($this->getJson('/api/v1/master/health-record-types')->assertOk()->json('data'))->firstWhere('type', $type);

        $this->assertFalse($find('vaccination')['medicine_from_store_required']);
        $this->assertSame(['store', 'typed'], $find('vaccination')['medicine_line_modes']);
        $this->assertSame([], $find('vet_visit')['medicine_line_modes']);

        $this->setting(true);
        $this->assertTrue($find('vaccination')['medicine_from_store_required']);
        $this->assertSame(['store'], $find('vaccination')['medicine_line_modes']);
        $this->assertNull($find('vaccination')['typed_medicine_fields']);
    }
}
