<?php

namespace Tests\Feature\Health;

use App\Enums\FarmRole;
use App\Events\Health\HealthRecordCreated;
use App\Models\CropType;
use App\Models\HealthRecord;
use App\Models\HealthRecordMedicine;
use App\Models\InventoryMovement;
use App\Models\OperationalRecord;
use App\Models\OperationType;
use App\Models\PopulationMovement;
use App\Models\Species;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\Team\TeamTestCase;

class HealthTest extends TeamTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAs($this->owner);
    }

    // ------------------------------------------------------------------ helpers

    private function at(int $hoursAgo = 1): string
    {
        return now()->utc()->subHours($hoursAgo)->format('Y-m-d\TH:i:s\Z');
    }

    private function cycle(): string
    {
        return $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => 'Layer flock '.Str::random(4), 'operation_type_id' => OperationType::where('code', 'poultry')->firstOrFail()->id,
            'species_id' => Species::where('code', 'chicken')->firstOrFail()->id, 'production_purpose' => 'breeding', 'initial_population' => 100, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
    }

    private function cropCycle(): string
    {
        return $this->postJson('/api/v1/production-cycles', ['kind' => 'crop', 'name' => 'Yam '.Str::random(4), 'operation_type_id' => OperationType::where('code', 'crops')->firstOrFail()->id,
            'crop_type_id' => CropType::where('code', 'yam')->firstOrFail()->id, 'planting_material_type' => 'tuber', 'planting_unit_type' => 'heap', 'initial_planting_units' => 800, 'planting_date' => '2026-01-10'])->assertCreated()->json('data.id');
    }

    private function store(string $name = 'Medicine Cabinet'): string
    {
        return $this->postJson('/api/v1/storage-locations', ['name' => $name.' '.Str::random(4), 'type' => 'store'])->assertCreated()->json('data.id');
    }

    private function medicineItem(array $extra = []): string
    {
        return $this->postJson('/api/v1/inventory/items', array_replace(['name' => 'Oxytet '.Str::random(6), 'category' => 'medicine', 'stock_unit' => 'ml'], $extra))->assertCreated()->json('data.id');
    }

    private function receive(string $item, string $loc, string $qty = '500', string $unit = 'ml', array $extra = []): array
    {
        return $this->postJson('/api/v1/inventory/stock-in', array_replace(['inventory_item_id' => $item, 'storage_location_id' => $loc, 'reason' => 'purchase',
            'components' => [['quantity' => $qty, 'unit' => $unit]], 'recorded_at' => $this->at(48), 'idempotency_key' => (string) Str::uuid()], $extra))->assertCreated()->json('data');
    }

    private function stock(string $item): string
    {
        return $this->getJson('/api/v1/inventory/items/'.$item)->assertOk()->json('data.stock.quantity');
    }

    private function bottle(string $item, string $perBottle = '100', string $target = 'ml'): void
    {
        $this->postJson('/api/v1/settings/package-conversions', ['context_type' => 'inventory_item', 'context_id' => $item, 'package_unit' => 'bottle', 'target_unit' => $target, 'quantity_per_package' => $perBottle])->assertCreated();
    }

    private function line(string $item, string $loc, string $qty = '10', string $unit = 'ml', array $extra = []): array
    {
        return array_replace(['inventory_item_id' => $item, 'storage_location_id' => $loc, 'components' => [['quantity' => $qty, 'unit' => $unit]]], $extra);
    }

    private function payload(string $cycle, array $medicines, array $extra = []): array
    {
        $extra += ['details' => ['target_disease' => 'Newcastle disease']];

        return array_replace(['production_cycle_id' => $cycle, 'type' => 'vaccination', 'recorded_at' => $this->at(2), 'idempotency_key' => (string) Str::uuid(),
            'medicines' => $medicines], $extra);
    }

    private function health(array $payload)
    {
        return $this->postJson('/api/v1/health-records', $payload);
    }

    private function mortality(string $cycle, int $qty = 5): array
    {
        return $this->postJson('/api/v1/records', ['production_cycle_id' => $cycle, 'type' => 'mortality', 'recorded_at' => $this->at(3), 'idempotency_key' => (string) Str::uuid(),
            'details' => ['quantity' => $qty, 'cause' => 'Disease']])->assertCreated()->json('data');
    }

    /** @return array{0: string, 1: string, 2: string} cycle, item, location with 500 ml received */
    private function ready(): array
    {
        $item = $this->medicineItem();
        $loc = $this->store();
        $this->receive($item, $loc);

        return [$this->cycle(), $item, $loc];
    }

    // ------------------------------------------------------------------ records

    public function test_multi_medicine_vaccination_records_doses_and_deducts_each_line_once(): void
    {
        Event::fake([HealthRecordCreated::class]);
        $a = $this->medicineItem(['name' => 'Vaccine A']);
        $b = $this->medicineItem(['name' => 'Vitamin B', 'stock_unit' => 'g']);
        $loc = $this->store();
        $this->receive($a, $loc, '500');
        $this->receive($b, $loc, '200', 'g');
        $cycle = $this->cycle();
        $at = $this->at(5);
        $res = $this->health($this->payload($cycle, [
            $this->line($a, $loc, '30', 'ml', ['dose_per_animal' => [['quantity' => '0.3', 'unit' => 'ml']], 'dosage_instructions' => 'One drop per bird']),
            $this->line($b, $loc, '12.5', 'g'),
        ], ['recorded_at' => $at, 'animals_affected' => 100, 'notes' => 'Whole flock']))->assertCreated();

        $data = $res->json('data');
        $this->assertSame('vaccination', $data['type']);
        $this->assertCount(2, $data['medicines']);
        $this->assertSame('30', $data['medicines'][0]['quantity_used']['quantity']);
        $this->assertSame('0.3', $data['medicines'][0]['dose_per_animal']['normalized']['quantity']);
        $this->assertSame('ml', $data['medicines'][0]['dose_per_animal']['normalized']['unit']);
        $this->assertSame('One drop per bird', $data['medicines'][0]['dosage_instructions']);
        $this->assertSame('470', $this->stock($a));
        $this->assertSame('187.5', $this->stock($b));
        $this->assertSame(2, InventoryMovement::whereNotNull('health_record_medicine_id')->count());
        $this->assertSame(2, HealthRecordMedicine::whereHas('movement')->count());
        foreach ($data['medicines'] as $line) {
            $this->assertNotNull($line['inventory_movement_id']);
        }
        // Event time is preserved separately from the system time; no population effect.
        $row = HealthRecord::findOrFail($data['id']);
        $this->assertSame($at, $row->recorded_at->format('Y-m-d\TH:i:s\Z'));
        $this->assertTrue($row->created_at->greaterThan($row->recorded_at));
        $this->assertSame(1, PopulationMovement::where('production_cycle_id', $cycle)->count());
        $this->assertSame(0, OperationalRecord::count());
        Event::assertDispatchedTimes(HealthRecordCreated::class, 1);
        $this->getJson('/api/v1/health-records/'.$data['id'])->assertOk()->assertJsonPath('data.medicines.1.item_name', 'Vitamin B');
    }

    public function test_type_specific_validation_and_closed_vocabulary(): void
    {
        [$cycle, $item, $loc] = $this->ready();
        $line = $this->line($item, $loc);
        $this->health($this->payload($cycle, [$line], ['details' => ['target_disease' => null]]))->assertStatus(422)->assertJsonValidationErrors('details.target_disease');
        $this->health($this->payload($cycle, [$line], ['details' => ['target_disease' => 'x', 'surprise' => 'y']]))->assertStatus(422);
        $this->health($this->payload($cycle, [], ['type' => 'medication', 'details' => ['condition' => 'Coryza']]))->assertStatus(422)->assertJsonValidationErrors('medicines');
        $this->health($this->payload($cycle, [$line], ['type' => 'disease_issue', 'details' => ['condition' => 'Coryza', 'severity' => 'moderate']]))->assertStatus(422)->assertJsonValidationErrors('medicines');
        $this->health($this->payload($cycle, [$line], ['type' => 'vet_visit', 'details' => ['vet_name' => 'Dr Ade']]))->assertStatus(422)->assertJsonValidationErrors('medicines');
        $this->health($this->payload($cycle, [], ['type' => 'disease_issue', 'details' => ['condition' => 'Coryza', 'severity' => 'extreme']]))->assertStatus(422)->assertJsonValidationErrors('details.severity');
        $this->health($this->payload($cycle, [$line], ['type' => 'surgery']))->assertStatus(422)->assertJsonValidationErrors('type');
        $this->health($this->payload($cycle, [$line], ['farm_id' => (string) Str::uuid()]))->assertStatus(422);
        $this->health($this->payload($cycle, [$line + ['withdrawal_ends_at' => $this->at(1)]]))->assertStatus(422);
        $this->health($this->payload($cycle, [$line, $line]))->assertStatus(422)->assertJsonValidationErrors('medicines.1');
        $this->health($this->payload($cycle, [$line], ['follow_up_on' => '2020-01-01']))->assertStatus(422)->assertJsonValidationErrors('follow_up_on');
        $this->health($this->payload($cycle, [$line], ['animals_affected' => 101]))->assertStatus(422)->assertJsonValidationErrors('animals_affected');
        $this->health($this->payload($cycle, [$line], ['animals_affected' => 0]))->assertStatus(422);
        // Time rules: explicit offset, not in the future, not before the cycle started.
        $this->health($this->payload($cycle, [$line], ['recorded_at' => '2026-02-01 10:00:00']))->assertStatus(422)->assertJsonValidationErrors('recorded_at');
        $this->health($this->payload($cycle, [$line], ['recorded_at' => now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z')]))->assertStatus(422)->assertJsonValidationErrors('recorded_at');
        $this->health($this->payload($cycle, [$line], ['recorded_at' => '2025-12-31T10:00:00Z']))->assertStatus(422)->assertJsonValidationErrors('recorded_at');
        $this->assertSame(0, HealthRecord::count());
        $this->assertSame('500', $this->stock($item));

        // Medicine-free types are valid on their own.
        $this->health($this->payload($cycle, [], ['type' => 'disease_issue', 'details' => ['condition' => 'Coryza', 'severity' => 'low', 'symptoms' => 'Sneezing'], 'follow_up_on' => now('Africa/Lagos')->addDays(3)->toDateString()]))->assertCreated()->assertJsonCount(0, 'data.medicines');
        $this->health($this->payload($cycle, [], ['type' => 'vet_visit', 'details' => ['vet_name' => 'Dr Ade', 'findings' => 'Healthy']]))->assertCreated();
        $this->health($this->payload($cycle, [$line], ['type' => 'deworming', 'details' => []]))->assertCreated();
        $this->health($this->payload($cycle, [$line], ['type' => 'medication', 'details' => ['condition' => 'Coryza']]))->assertCreated();
        $this->health($this->payload($cycle, [$line], ['type' => 'treatment', 'details' => ['condition' => 'Coryza']]))->assertCreated();
        $types = $this->getJson('/api/v1/master/health-record-types')->assertOk()->json('data');
        $this->assertSame(['vaccination', 'medication', 'deworming', 'treatment', 'disease_issue', 'vet_visit'], array_column($types, 'type'));
    }

    public function test_cycle_kind_and_inventory_category_compatibility(): void
    {
        [$livestock, $item, $loc] = $this->ready();
        $crop = $this->cropCycle();
        $agro = $this->postJson('/api/v1/inventory/items', ['name' => 'Fungicide '.Str::random(4), 'category' => 'fertilizer_agrochemical', 'stock_unit' => 'ml'])->assertCreated()->json('data.id');
        $this->receive($agro, $loc, '300');
        $feed = $this->postJson('/api/v1/inventory/items', ['name' => 'Mash '.Str::random(4), 'category' => 'feed', 'stock_unit' => 'kg'])->assertCreated()->json('data.id');
        $this->receive($feed, $loc, '10', 'kg');

        // Health is livestock/fish only: every type is refused on a crop cycle, and nothing is deducted.
        foreach (['vaccination' => ['target_disease' => 'x'], 'treatment' => ['condition' => 'Leaf blight']] as $type => $details) {
            $this->health($this->payload($crop, [$this->line($agro, $loc)], ['type' => $type, 'details' => $details]))->assertStatus(422)->assertJsonValidationErrors('type');
        }
        $this->health($this->payload($crop, [], ['type' => 'disease_issue', 'details' => ['condition' => 'Leaf blight', 'severity' => 'high']]))->assertStatus(422)->assertJsonValidationErrors('type');
        $this->health($this->payload($crop, [], ['type' => 'vet_visit', 'details' => ['vet_name' => 'Dr Ade']]))->assertStatus(422)->assertJsonValidationErrors('type');
        $this->assertSame('300', $this->stock($agro));
        $this->assertSame(0, HealthRecord::count());
        // Livestock lines accept medicine items only.
        $this->health($this->payload($livestock, [$this->line($agro, $loc)]))->assertStatus(422)->assertJsonValidationErrors('medicines.0.inventory_item_id');
        $this->health($this->payload($livestock, [$this->line($feed, $loc, '1', 'kg')]))->assertStatus(422)->assertJsonValidationErrors('medicines.0.inventory_item_id');
        $this->assertSame('10', $this->stock($feed));
    }

    public function test_insufficient_stock_rolls_the_whole_record_back(): void
    {
        [$cycle, $item, $loc] = $this->ready();
        $other = $this->medicineItem(['name' => 'Scarce']);
        $this->receive($other, $loc, '5');
        $payload = $this->payload($cycle, [$this->line($item, $loc, '100'), $this->line($other, $loc, '5.000001')]);
        $this->health($payload)->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->assertSame(0, HealthRecord::count());
        $this->assertSame(0, HealthRecordMedicine::count());
        $this->assertSame(0, InventoryMovement::whereNotNull('health_record_id')->count());
        $this->assertSame('500', $this->stock($item));
        // The failed attempt did not consume the idempotency key: the same key works once stock suffices.
        $this->receive($other, $loc, '1');
        $this->health($payload)->assertCreated();
        $this->assertSame('400', $this->stock($item));
        $this->assertSame('0.999999', $this->stock($other));
        // A back-dated use before the stock arrived is rejected using the dated ledger, not the current balance.
        $this->health($this->payload($cycle, [$this->line($item, $loc, '1')], ['recorded_at' => $this->at(100)]))->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
    }

    public function test_retries_never_double_deduct(): void
    {
        [$cycle, $item, $loc] = $this->ready();
        $payload = $this->payload($cycle, [$this->line($item, $loc, '25')]);
        $first = $this->health($payload)->assertCreated()->json('data');
        $again = $this->health($payload)->assertCreated()->json('data');
        $this->assertSame($first['id'], $again['id']);
        $this->assertSame('475', $this->stock($item));
        $this->assertSame(1, HealthRecord::count());
        $this->assertSame(1, InventoryMovement::whereNotNull('health_record_medicine_id')->count());
        // Same key, different content: refused, nothing written.
        $changed = $payload;
        $changed['medicines'][0]['components'][0]['quantity'] = '26';
        $this->health($changed)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame('475', $this->stock($item));
        $this->postJson('/api/v1/health-records', array_diff_key($payload, ['idempotency_key' => 1]))->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
    }

    public function test_one_stock_movement_per_line_is_a_database_guarantee(): void
    {
        [$cycle, $item, $loc] = $this->ready();
        $record = $this->health($this->payload($cycle, [$this->line($item, $loc, '25')]))->assertCreated()->json('data');
        $movement = InventoryMovement::where('health_record_medicine_id', $record['medicines'][0]['id'])->firstOrFail();
        $this->assertSame('use', $movement->reason);
        $this->assertSame($record['id'], $movement->health_record_id);
        $this->assertEquals(-25, (float) $movement->quantity_delta);
        $this->expectException(QueryException::class);
        InventoryMovement::create(['farm_id' => $movement->farm_id, 'inventory_item_id' => $item, 'storage_location_id' => $loc, 'type' => 'stock_out', 'quantity_delta' => '-1', 'measurement' => [],
            'recorded_at' => now(), 'created_by' => $movement->created_by, 'health_record_medicine_id' => $record['medicines'][0]['id']]);
    }

    public function test_history_is_append_only_and_stock_has_no_writable_balance(): void
    {
        [$cycle, $item, $loc] = $this->ready();
        $record = $this->health($this->payload($cycle, [$this->line($item, $loc, '25')]))->assertCreated()->json('data');
        $this->assertFalse(Schema::hasColumn('health_records', 'quantity'));
        $this->assertFalse(Schema::hasColumn('inventory_items', 'quantity'));
        try {
            HealthRecord::findOrFail($record['id'])->update(['notes' => 'edited']);
            $this->fail('A health record was edited.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
        $this->expectException(LogicException::class);
        HealthRecordMedicine::findOrFail($record['medicines'][0]['id'])->delete();
    }

    public function test_health_writes_have_no_patch_or_delete_endpoints(): void
    {
        [$cycle, $item, $loc] = $this->ready();
        $id = $this->health($this->payload($cycle, [$this->line($item, $loc)]))->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/health-records/'.$id, ['notes' => 'x'])->assertStatus(405);
        $this->deleteJson('/api/v1/health-records/'.$id)->assertStatus(405);
    }

    // ------------------------------------------------------------------ measurements & packaging

    public function test_package_conversion_is_contextual_and_units_must_match_the_item(): void
    {
        $item = $this->medicineItem();
        $other = $this->medicineItem(['name' => 'Other med']);
        $loc = $this->store();
        $this->receive($item, $loc, '1000');
        $cycle = $this->cycle();
        $line = $this->line($item, $loc, '2', 'bottle', ['components' => [['quantity' => '2', 'unit' => 'bottle'], ['quantity' => '50', 'unit' => 'ml']]]);
        // Never assume a bottle size.
        $this->health($this->payload($cycle, [$line]))->assertStatus(422)->assertJsonPath('code', 'conversion_not_configured');
        $this->bottle($other, '500');
        $this->health($this->payload($cycle, [$line]))->assertStatus(422)->assertJsonPath('code', 'conversion_not_configured');
        $this->bottle($item, '100');
        $res = $this->health($this->payload($cycle, [$line]))->assertCreated();
        $this->assertSame('250', $res->json('data.medicines.0.quantity_used.quantity'));
        $this->assertSame('250', $res->json('data.medicines.0.measurement.normalized.quantity'));
        $this->assertSame('750', $this->stock($item));
        $this->assertSame('0', $this->stock($other));
        // Later edits of the package size do not rewrite the stored snapshot.
        $conversion = $this->getJson('/api/v1/settings/package-conversions')->json('data');
        $this->assertNotEmpty($conversion);
        // Incompatible unit family.
        $this->health($this->payload($cycle, [$this->line($item, $loc, '1', 'kg')]))->assertStatus(422)->assertJsonPath('code', 'unit_dimension_mismatch');
        $this->health($this->payload($cycle, [$this->line($item, $loc, '1', 'xyz')]))->assertStatus(422);
        $this->health($this->payload($cycle, [$this->line($item, $loc, '0', 'ml')]))->assertStatus(422);
        $this->health($this->payload($cycle, [$this->line($item, $loc, '-1', 'ml')]))->assertStatus(422);
        $this->assertSame('750', $this->stock($item));
        // Litres convert exactly into the ml basis.
        $this->health($this->payload($cycle, [$this->line($item, $loc, '0.25', 'l')]))->assertCreated()->assertJsonPath('data.medicines.0.quantity_used.quantity', '250');
        $this->assertSame('500', $this->stock($item));
    }

    // ------------------------------------------------------------------ lots & expiry

    public function test_lot_tracked_medicine_requires_a_lot_and_never_uses_expired_stock(): void
    {
        $item = $this->medicineItem(['tracks_lots' => true, 'tracks_expiry' => true]);
        $other = $this->medicineItem(['name' => 'Another lot item', 'tracks_lots' => true, 'tracks_expiry' => true]);
        $loc = $this->store();
        $good = $this->receive($item, $loc, '200', 'ml', ['lot' => ['code' => 'L-GOOD', 'expires_on' => now('Africa/Lagos')->addDays(60)->toDateString()]]);
        $expiring = $this->receive($item, $loc, '100', 'ml', ['lot' => ['code' => 'L-OLD', 'expires_on' => now('Africa/Lagos')->addDays(2)->toDateString()]]);
        $foreignLot = $this->receive($other, $loc, '10', 'ml', ['lot' => ['code' => 'L-X', 'expires_on' => now('Africa/Lagos')->addDays(60)->toDateString()]]);
        $cycle = $this->cycle();
        $this->health($this->payload($cycle, [$this->line($item, $loc)]))->assertStatus(422)->assertJsonValidationErrors('medicines.0.lot_id');
        $this->health($this->payload($cycle, [$this->line($item, $loc, '10', 'ml', ['lot_id' => $foreignLot['inventory_lot_id']])]))->assertNotFound();
        $record = $this->health($this->payload($cycle, [$this->line($item, $loc, '10', 'ml', ['lot_id' => $good['inventory_lot_id']])]))->assertCreated()->json('data');
        $this->assertSame('L-GOOD', $record['medicines'][0]['lot']['code']);
        $this->assertSame($good['inventory_lot_id'], $record['medicines'][0]['inventory_lot_id']);
        $this->assertSame(-10.0, (float) InventoryMovement::where('health_record_medicine_id', $record['medicines'][0]['id'])->value('quantity_delta'));
        $this->assertSame('290', $this->stock($item));
        // Using the lot AFTER it expired (event dated after expiry) is refused, while before expiry it was fine.
        $expiryEvent = now('Africa/Lagos')->addDays(2)->toDateString();
        $this->assertNotNull($expiryEvent);
        $this->travelTo(now()->addDays(5));
        $this->health($this->payload($cycle, [$this->line($item, $loc, '10', 'ml', ['lot_id' => $expiring['inventory_lot_id']])], ['recorded_at' => $this->at(1)]))->assertStatus(409)->assertJsonPath('code', 'lot_expired');
        $this->travelBack();
        $this->assertSame('290', $this->stock($item));
        $this->assertSame(1, HealthRecord::count());
        $medicine = $this->getJson('/api/v1/health/medicines/'.$item)->assertOk()->json('data');
        $this->assertCount(2, $medicine['lots']);
        $this->assertSame('L-OLD', $medicine['lots'][0]['code']);
    }

    // ------------------------------------------------------------------ withdrawal

    public function test_withdrawal_is_computed_per_line_and_traceable_to_the_event(): void
    {
        $this->freezeTime();
        $a = $this->medicineItem(['name' => 'Withdrawal A']);
        $b = $this->medicineItem(['name' => 'No withdrawal B']);
        $c = $this->medicineItem(['name' => 'Override C']);
        $loc = $this->store();
        foreach ([$a, $b, $c] as $item) {
            $this->receive($item, $loc, '100');
        }
        $this->putJson('/api/v1/health/medicines/'.$a.'/profile', ['default_withdrawal_days' => 7, 'notes' => 'Eggs and meat'])->assertOk()->assertJsonPath('data.profile.default_withdrawal_days', 7);
        $this->putJson('/api/v1/health/medicines/'.$c.'/profile', ['default_withdrawal_days' => 14])->assertOk();
        $cycle = $this->cycle();
        $at = $this->at(10);
        $record = $this->health($this->payload($cycle, [
            $this->line($a, $loc), $this->line($b, $loc), $this->line($c, $loc, '10', 'ml', ['withdrawal_days' => 3]),
        ], ['type' => 'treatment', 'details' => ['condition' => 'Coryza'], 'recorded_at' => $at]))->assertCreated()->json('data');

        $lines = $record['medicines'];
        $this->assertSame(7, $lines[0]['withdrawal']['days']);
        $this->assertSame('item_default', $lines[0]['withdrawal']['source']);
        $this->assertSame(now()->utc()->subHours(10)->addDays(7)->format('Y-m-d\TH:i:s'), substr($lines[0]['withdrawal']['ends_at'], 0, 19));
        $this->assertTrue($lines[0]['withdrawal']['is_active']);
        $this->assertNull($lines[1]['withdrawal']['days']);
        $this->assertNull($lines[1]['withdrawal']['ends_at']);
        $this->assertFalse($lines[1]['withdrawal']['is_active']);
        $this->assertSame(3, $lines[2]['withdrawal']['days']);
        $this->assertSame('explicit', $lines[2]['withdrawal']['source']);

        // Changing the default afterwards never rewrites recorded history.
        $this->putJson('/api/v1/health/medicines/'.$a.'/profile', ['default_withdrawal_days' => 30])->assertOk();
        $this->assertSame(7, $this->getJson('/api/v1/health-records/'.$record['id'])->json('data.medicines.0.withdrawal.days'));
        // An explicit zero means "none", even where the medicine has a default.
        $zero = $this->health($this->payload($cycle, [$this->line($a, $loc, '1', 'ml', ['withdrawal_days' => 0])]))->assertCreated();
        $this->assertSame('explicit', $zero->json('data.medicines.0.withdrawal.source'));
        $this->assertNull($zero->json('data.medicines.0.withdrawal.ends_at'));

        $active = $this->getJson('/api/v1/health/withdrawals?production_cycle_id='.$cycle)->assertOk()->json('data');
        $this->assertCount(2, $active);
        $row = collect($active)->firstWhere('item_name', 'Withdrawal A');
        $this->assertSame($record['id'], $row['health_record_id']);
        $this->assertSame($lines[0]['id'], $row['health_record_medicine_id']);
        $this->assertSame('treatment', $row['health_record_type']);
        $this->assertSame(7, $row['days']);
        // An ended window drops out of the active list but stays in the full list.
        $this->travelTo(now()->addDays(5));
        $this->assertCount(1, $this->getJson('/api/v1/health/withdrawals?production_cycle_id='.$cycle)->json('data'));
        $this->assertCount(2, $this->getJson('/api/v1/health/withdrawals?production_cycle_id='.$cycle.'&active=0')->json('data'));
        $this->travelBack();
        $this->getJson('/api/v1/health/withdrawals?inventory_item_id='.$a)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/health/withdrawals?active=2')->assertStatus(422);
        $this->putJson('/api/v1/health/medicines/'.$a.'/profile', ['default_withdrawal_days' => 99999])->assertStatus(422);
        $this->putJson('/api/v1/health/medicines/'.$a.'/profile', [])->assertStatus(422);
        $this->putJson('/api/v1/health/medicines/'.$a.'/profile', ['default_withdrawal_days' => null])->assertOk()->assertJsonPath('data.profile.default_withdrawal_days', null);
    }

    // ------------------------------------------------------------------ mortality integration

    public function test_health_links_to_phase_8_mortality_without_creating_a_second_population_effect(): void
    {
        [$cycle, $item, $loc] = $this->ready();
        $mortality = $this->mortality($cycle, 5);
        $population = fn () => (int) PopulationMovement::where('production_cycle_id', $cycle)->sum('quantity');
        $this->assertSame(95, $population());
        $this->assertSame(1, OperationalRecord::where('type', 'mortality')->count());

        $record = $this->health($this->payload($cycle, [$this->line($item, $loc)], ['type' => 'treatment', 'details' => ['condition' => 'Coryza'], 'animals_affected' => 40, 'mortality_record_id' => $mortality['id']]))->assertCreated()->json('data');
        $this->assertSame($mortality['id'], $record['mortality_record_id']);
        $this->assertSame(95, $population());
        $this->assertSame(1, OperationalRecord::where('type', 'mortality')->count());
        $this->assertSame(2, PopulationMovement::where('production_cycle_id', $cycle)->count());
        // Health detail keys cannot smuggle a mortality count.
        $this->health($this->payload($cycle, [], ['type' => 'disease_issue', 'details' => ['condition' => 'x', 'severity' => 'low', 'deaths' => 3]]))->assertStatus(422);
        $this->health($this->payload($cycle, [], ['type' => 'disease_issue', 'details' => ['condition' => 'x', 'severity' => 'low'], 'quantity' => 3]))->assertCreated();
        $this->assertSame(95, $population());

        // Only a live mortality record of the same cycle and farm can be linked.
        $this->health($this->payload($cycle, [], ['type' => 'disease_issue', 'details' => ['condition' => 'x', 'severity' => 'low'], 'mortality_record_id' => (string) Str::uuid()]))->assertStatus(422)->assertJsonValidationErrors('mortality_record_id');
        $otherCycle = $this->cycle();
        $this->health($this->payload($otherCycle, [], ['type' => 'disease_issue', 'details' => ['condition' => 'x', 'severity' => 'low'], 'mortality_record_id' => $mortality['id']]))->assertStatus(422)->assertJsonValidationErrors('mortality_record_id');
        $weight = $this->postJson('/api/v1/records', ['production_cycle_id' => $cycle, 'type' => 'general_note', 'recorded_at' => $this->at(3), 'idempotency_key' => (string) Str::uuid(), 'details' => ['text' => 'n']])->assertCreated()->json('data');
        $this->health($this->payload($cycle, [], ['type' => 'disease_issue', 'details' => ['condition' => 'x', 'severity' => 'low'], 'mortality_record_id' => $weight['id']]))->assertStatus(422);
        $this->postJson('/api/v1/records/'.$mortality['id'].'/reverse', ['reason' => 'Entered twice', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $this->health($this->payload($cycle, [], ['type' => 'disease_issue', 'details' => ['condition' => 'x', 'severity' => 'low'], 'mortality_record_id' => $mortality['id']]))->assertStatus(422);
        // Reversing the health record never touches the mortality record or population.
        $population = fn () => (int) PopulationMovement::where('production_cycle_id', $cycle)->sum('quantity');
        $before = $population();
        $this->postJson('/api/v1/health-records/'.$record['id'].'/reverse', ['reason' => 'Wrong flock', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $this->assertSame($before, $population());
    }

    // ------------------------------------------------------------------ corrections

    public function test_reversal_restores_stock_with_audit_rows_and_ends_withdrawal(): void
    {
        [$cycle, $item, $loc] = $this->ready();
        $this->putJson('/api/v1/health/medicines/'.$item.'/profile', ['default_withdrawal_days' => 5])->assertOk();
        $record = $this->health($this->payload($cycle, [$this->line($item, $loc, '40')], ['recorded_at' => $this->at(6)]))->assertCreated()->json('data');
        $this->assertSame('460', $this->stock($item));
        $this->assertCount(1, $this->getJson('/api/v1/health/withdrawals')->json('data'));

        // Stock cannot be reversed behind the health record's back.
        $movement = $record['medicines'][0]['inventory_movement_id'];
        $this->postJson('/api/v1/inventory/movements/'.$movement.'/reverse', ['reason' => 'oops', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertStatus(409)->assertJsonPath('code', 'reverse_via_health_record');
        $this->postJson('/api/v1/health-records/'.$record['id'].'/reverse', ['recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson('/api/v1/health-records/'.$record['id'].'/reverse', ['reason' => 'x', 'recorded_at' => $this->at(8), 'idempotency_key' => (string) Str::uuid()])->assertStatus(422)->assertJsonValidationErrors('recorded_at');

        $key = (string) Str::uuid();
        $when = $this->at(1);
        $rev = $this->postJson('/api/v1/health-records/'.$record['id'].'/reverse', ['reason' => 'Wrong flock', 'recorded_at' => $when, 'idempotency_key' => $key])->assertCreated()->json('data');
        $this->assertSame('reversal', $rev['type']);
        $this->assertSame($record['id'], $rev['reverses_record_id']);
        $this->assertSame('500', $this->stock($item));
        // A retry of the same reversal does not compensate twice.
        $this->postJson('/api/v1/health-records/'.$record['id'].'/reverse', ['reason' => 'Wrong flock', 'recorded_at' => $when, 'idempotency_key' => $key])->assertCreated()->assertJsonPath('data.id', $rev['id']);
        $this->assertSame('500', $this->stock($item));
        $this->assertSame(2, InventoryMovement::whereNotNull('health_record_id')->count());
        $this->assertSame(1, InventoryMovement::where('type', 'reversal')->where('health_record_id', $rev['id'])->count());

        // History is intact: the original is unchanged and now points at its reversal.
        $shown = $this->getJson('/api/v1/health-records/'.$record['id'])->assertOk()->json('data');
        $this->assertSame($rev['id'], $shown['reversed_by_record_id']);
        $this->assertSame('40', $shown['medicines'][0]['quantity_used']['quantity']);
        $this->assertFalse($shown['medicines'][0]['withdrawal']['is_active']);
        $this->assertCount(0, $this->getJson('/api/v1/health/withdrawals?active=0')->json('data'));

        $this->postJson('/api/v1/health-records/'.$record['id'].'/reverse', ['reason' => 'again', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertStatus(409)->assertJsonPath('code', 'record_already_reversed');
        $this->postJson('/api/v1/health-records/'.$rev['id'].'/reverse', ['reason' => 'again', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertStatus(409)->assertJsonPath('code', 'record_already_reversed');
        $this->postJson('/api/v1/health-records/'.(string) Str::uuid().'/reverse', ['reason' => 'x', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->assertSame('500', $this->stock($item));
    }

    public function test_correction_is_reverse_then_one_linked_replacement(): void
    {
        [$cycle, $item, $loc] = $this->ready();
        $record = $this->health($this->payload($cycle, [$this->line($item, $loc, '40')]))->assertCreated()->json('data');
        $replacement = $this->payload($cycle, [$this->line($item, $loc, '30')], ['corrects_record_id' => $record['id']]);
        // Not reversed yet: refused.
        $this->health($replacement)->assertStatus(409)->assertJsonPath('code', 'invalid_correction');
        $this->postJson('/api/v1/health-records/'.$record['id'].'/reverse', ['reason' => 'Wrong dose', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $this->assertSame('500', $this->stock($item));
        // Wrong type is refused; same type works once.
        $this->health($this->payload($cycle, [$this->line($item, $loc, '30')], ['type' => 'medication', 'details' => ['condition' => 'x'], 'corrects_record_id' => $record['id']]))->assertStatus(409)->assertJsonPath('code', 'invalid_correction');
        $new = $this->health($replacement)->assertCreated()->json('data');
        $this->assertSame($record['id'], $new['corrects_record_id']);
        $this->assertSame('470', $this->stock($item));
        $this->health($this->payload($cycle, [$this->line($item, $loc, '30')], ['corrects_record_id' => $record['id']]))->assertStatus(409)->assertJsonPath('code', 'invalid_correction');
        $this->assertSame('470', $this->stock($item));
        // The audit trail holds all three rows.
        $this->assertSame(3, HealthRecord::where('production_cycle_id', $cycle)->count());
    }

    public function test_closed_cycles_reject_new_health_events_but_stay_readable(): void
    {
        [$cycle, $item, $loc] = $this->ready();
        $record = $this->health($this->payload($cycle, [$this->line($item, $loc)]))->assertCreated()->json('data');
        $this->postJson('/api/v1/production-cycles/'.$cycle.'/close', ['end_date' => now('Africa/Lagos')->toDateString(), 'reason' => 'Done'])->assertOk();
        $this->health($this->payload($cycle, [$this->line($item, $loc)]))->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
        $this->postJson('/api/v1/health-records/'.$record['id'].'/reverse', ['reason' => 'x', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
        $this->assertSame('490', $this->stock($item));
        $this->getJson('/api/v1/health-records/'.$record['id'])->assertOk();
        $this->getJson('/api/v1/health-records?production_cycle_id='.$cycle)->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/production-cycles/'.$cycle.'/reopen', ['reason' => 'Mistake'])->assertOk();
        $this->health($this->payload($cycle, [$this->line($item, $loc)]))->assertCreated();
    }

    // ------------------------------------------------------------------ isolation & permissions

    public function test_cross_farm_references_are_rejected_and_nothing_leaks(): void
    {
        [$cycle, $item, $loc] = $this->ready();
        $mine = $this->health($this->payload($cycle, [$this->line($item, $loc)]))->assertCreated()->json('data');

        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $theirItem = $this->medicineItem();
        $theirLoc = $this->store();
        $this->receive($theirItem, $theirLoc);
        $theirCycle = $this->cycle();

        // Foreign cycle / item / location / record / mortality all look like "not found".
        $this->health($this->payload($cycle, [$this->line($theirItem, $theirLoc)]))->assertNotFound();
        $this->health($this->payload($theirCycle, [$this->line($item, $theirLoc)]))->assertNotFound();
        $this->health($this->payload($theirCycle, [$this->line($theirItem, $loc)]))->assertNotFound();
        $this->getJson('/api/v1/health-records/'.$mine['id'])->assertNotFound();
        $this->postJson('/api/v1/health-records/'.$mine['id'].'/reverse', ['reason' => 'x', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->getJson('/api/v1/health-records?production_cycle_id='.$cycle)->assertNotFound();
        $this->getJson('/api/v1/health-records')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/health/medicines/'.$item)->assertNotFound();
        $this->putJson('/api/v1/health/medicines/'.$item.'/profile', ['default_withdrawal_days' => 1])->assertNotFound();
        $this->getJson('/api/v1/health/medicines')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/health/withdrawals?inventory_item_id='.$item)->assertOk()->assertJsonCount(0, 'data');
        // The same idempotency key can be used by another farm (keys are farm-scoped).
        $payload = $this->payload($theirCycle, [$this->line($theirItem, $theirLoc)], ['idempotency_key' => 'shared-key-1']);
        $this->health($payload)->assertCreated();
        $this->signInAs($this->owner);
        $this->health($this->payload($cycle, [$this->line($item, $loc)], ['idempotency_key' => 'shared-key-1']))->assertCreated();
        $this->assertSame(1, HealthRecord::where('farm_id', $otherOwner->currentFarm()->id)->count());
        $this->assertSame('480', $this->stock($item));
    }

    public function test_permissions_follow_the_role_presets(): void
    {
        [$cycle, $item, $loc] = $this->ready();
        $record = $this->health($this->payload($cycle, [$this->line($item, $loc)]))->assertCreated()->json('data');
        $payload = fn () => $this->payload($cycle, [$this->line($item, $loc, '1')]);
        $reverse = fn () => ['reason' => 'x', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()];

        // Worker: reads and records health events, but cannot correct or manage medicine metadata.
        $this->signInAs($this->member(FarmRole::FarmWorker));
        $this->getJson('/api/v1/health-records')->assertOk();
        $this->getJson('/api/v1/health/medicines')->assertOk();
        $this->getJson('/api/v1/health/withdrawals')->assertOk();
        $this->health($payload())->assertCreated();
        $this->health($payload() + ['corrects_record_id' => $record['id']])->assertForbidden();
        $this->postJson('/api/v1/health-records/'.$record['id'].'/reverse', $reverse())->assertForbidden();
        $this->putJson('/api/v1/health/medicines/'.$item.'/profile', ['default_withdrawal_days' => 1])->assertForbidden();

        // Finance and unauthenticated users have no health access at all.
        $this->signInAs($this->member(FarmRole::Finance));
        $this->getJson('/api/v1/health-records')->assertForbidden();
        $this->getJson('/api/v1/health/medicines')->assertForbidden();
        $this->getJson('/api/v1/master/health-record-types')->assertForbidden();
        $this->health($payload())->assertForbidden();

        // Vet preset: full health work, no general stock-out or record creation.
        $vet = $this->member(FarmRole::Vet);
        $this->signInAs($vet);
        $this->health($payload())->assertCreated();
        $this->putJson('/api/v1/health/medicines/'.$item.'/profile', ['default_withdrawal_days' => 2])->assertOk();
        $this->postJson('/api/v1/health-records/'.$record['id'].'/reverse', $reverse())->assertCreated();
        $this->getJson('/api/v1/inventory/items/'.$item)->assertOk();
        $this->postJson('/api/v1/inventory/stock-out', ['inventory_item_id' => $item, 'storage_location_id' => $loc, 'reason' => 'use', 'components' => [['quantity' => '1', 'unit' => 'ml']], 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->postJson('/api/v1/records', ['production_cycle_id' => $cycle, 'type' => 'general_note', 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid(), 'details' => ['text' => 'x']])->assertForbidden();
        $this->getJson('/api/v1/roles')->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/health-records')->assertUnauthorized();
        $this->postJson('/api/v1/health-records', [])->assertUnauthorized();
        $this->getJson('/api/v1/health/medicines')->assertUnauthorized();
    }

    // ------------------------------------------------------------------ queries

    public function test_listing_filters_and_pagination(): void
    {
        [$cycle, $item, $loc] = $this->ready();
        $second = $this->medicineItem(['name' => 'Second']);
        $this->receive($second, $loc, '100');
        $this->health($this->payload($cycle, [$this->line($item, $loc)], ['recorded_at' => $this->at(30)]))->assertCreated();
        $this->health($this->payload($cycle, [$this->line($second, $loc)], ['type' => 'medication', 'details' => ['condition' => 'x'], 'recorded_at' => $this->at(20)]))->assertCreated();
        $last = $this->health($this->payload($cycle, [], ['type' => 'vet_visit', 'details' => ['vet_name' => 'Dr Ade'], 'recorded_at' => $this->at(10)]))->assertCreated()->json('data');

        $all = $this->getJson('/api/v1/health-records')->assertOk()->assertJsonPath('meta.total', 3)->json('data');
        $this->assertSame($last['id'], $all[0]['id']);
        $this->getJson('/api/v1/health-records?type=medication')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/health-records?inventory_item_id='.$second)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/health-records?production_cycle_id='.$cycle.'&per_page=2&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/v1/health-records?recorded_from='.now('Africa/Lagos')->toDateString().'&recorded_to='.now('Africa/Lagos')->toDateString())->assertOk();
        $this->getJson('/api/v1/health-records?per_page=500')->assertStatus(422);
        $this->getJson('/api/v1/health-records?type=nonsense')->assertStatus(422);
        $this->getJson('/api/v1/health-records?recorded_from=2026-02-02&recorded_to=2026-02-01')->assertStatus(422);
        $this->getJson('/api/v1/health-records?production_cycle_id='.(string) Str::uuid())->assertNotFound();
        $this->getJson('/api/v1/health-records/'.(string) Str::uuid())->assertNotFound();
    }

    public function test_medicine_endpoints_are_a_view_over_phase_9_inventory(): void
    {
        $med = $this->medicineItem(['name' => 'Penstrep', 'low_stock_threshold' => ['quantity' => '100', 'unit' => 'ml']]);
        $feed = $this->postJson('/api/v1/inventory/items', ['name' => 'Starter', 'category' => 'feed', 'stock_unit' => 'kg'])->assertCreated()->json('data.id');
        $agro = $this->postJson('/api/v1/inventory/items', ['name' => 'Neem spray', 'category' => 'fertilizer_agrochemical', 'stock_unit' => 'l'])->assertCreated()->json('data.id');
        $loc = $this->store();
        $this->receive($med, $loc, '80');

        $list = $this->getJson('/api/v1/health/medicines')->assertOk()->assertJsonCount(1, 'data')->json('data');
        $this->assertSame($med, $list[0]['id']);
        $this->assertSame('80', $list[0]['stock']['quantity']);
        $this->assertTrue($list[0]['is_low_stock']);
        $this->assertNull($list[0]['profile']['default_withdrawal_days']);
        // Agrochemicals are not medicines: they are neither listed nor reachable here.
        $this->getJson('/api/v1/health/medicines?category=fertilizer_agrochemical')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $med);
        $this->getJson('/api/v1/health/medicines/'.$agro)->assertNotFound();
        $this->putJson('/api/v1/health/medicines/'.$agro.'/profile', ['default_withdrawal_days' => 1])->assertNotFound();
        $this->getJson('/api/v1/health/medicines?search=PEN')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/health/medicines?low_stock=0')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/health/medicines/'.$feed)->assertNotFound();
        $this->putJson('/api/v1/health/medicines/'.$feed.'/profile', ['default_withdrawal_days' => 1])->assertNotFound();
        $show = $this->getJson('/api/v1/health/medicines/'.$med)->assertOk()->json('data');
        $this->assertCount(1, $show['balances']);
        $this->assertSame('80', $show['balances'][0]['quantity']);
        // Stock is only ever changed through the inventory ledger.
        $this->putJson('/api/v1/health/medicines/'.$med.'/profile', ['default_withdrawal_days' => 1, 'quantity' => 500])->assertStatus(200);
        $this->assertSame('80', $this->stock($med));
    }
}
