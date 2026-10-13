<?php

namespace Tests\Feature\Health;

use App\Enums\FarmRole;
use App\Models\FinanceTransaction;
use App\Models\InventoryMovement;
use App\Models\MedicineProfile;
use App\Models\OperationType;
use App\Models\Purchase;
use App\Models\Species;
use App\Models\Unit;
use Database\Seeders\MeasurementSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Team\TeamTestCase;

class MedicineCatalogueTest extends TeamTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAs($this->owner);
    }

    private function at(int $hoursAgo = 1): string
    {
        return now()->utc()->subHours($hoursAgo)->format('Y-m-d\TH:i:s\Z');
    }

    private function store(): string
    {
        return $this->postJson('/api/v1/storage-locations', ['name' => 'Cabinet '.Str::random(4), 'type' => 'store'])->assertCreated()->json('data.id');
    }

    private function cycle(): string
    {
        return $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => 'Flock '.Str::random(4), 'operation_type_id' => OperationType::where('code', 'poultry')->firstOrFail()->id,
            'species_id' => Species::where('code', 'chicken')->firstOrFail()->id, 'production_purpose' => 'breeding', 'initial_population' => 100, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
    }

    private function body(array $extra = []): array
    {
        return array_replace(['name' => 'Oxytet '.Str::random(6), 'medicine_type' => 'antibiotic', 'stock_unit' => 'ml'], $extra);
    }

    private function opening(string $loc, array $extra = []): array
    {
        return array_replace(['storage_location_id' => $loc, 'components' => [['quantity' => '5', 'unit' => 'bottle']], 'recorded_at' => $this->at(48), 'idempotency_key' => (string) Str::uuid()], $extra);
    }

    private function create(array $body)
    {
        return $this->postJson('/api/v1/health/medicines', $body);
    }

    // ----------------------------------------------------------- provisioning / master data

    public function test_packaging_units_are_provisioned_once_and_existing_units_survive(): void
    {
        foreach (['box', 'card', 'sachet', 'vial', 'bottle', 'bag'] as $code) {
            $unit = Unit::where('code', $code)->first();
            $this->assertNotNull($unit, $code);
            $this->assertSame('package', $unit->dimension->code);
            $this->assertNull($unit->family);
        }
        $before = Unit::count();
        $bottleId = Unit::where('code', 'bottle')->value('id');
        (new MeasurementSeeder)->run(); // repeatable provisioning
        $this->assertSame($before, Unit::count());
        $this->assertSame($bottleId, Unit::where('code', 'bottle')->value('id'));
    }

    public function test_medicine_options_expose_types_units_and_permitted_reasons(): void
    {
        $data = $this->getJson('/api/v1/master/medicine-options')->assertOk()->json('data');
        $this->assertSame(['antibiotic', 'vaccine', 'supplement', 'dewormer', 'other'], array_column($data['medicine_types'], 'code'));
        $this->assertSame(['mg', 'g', 'kg', 'ml', 'l', 'piece'], array_column($data['primary_units'], 'code'));
        $this->assertSame(['bottle', 'box', 'card', 'sachet', 'bag', 'vial'], array_column($data['package_units'], 'code'));
        $out = collect($data['reasons']['out'])->keyBy('code');
        $this->assertFalse($out['use']['manual']);
        $this->assertSame('/health-records', $out['use']['route']['path']);
        foreach (['donation', 'damaged', 'expired', 'spoiled', 'lost', 'disposal', 'other'] as $reason) {
            $this->assertTrue($out[$reason]['manual'], $reason);
        }
        $this->assertContains('opening_balance', array_column($data['reasons']['in'], 'code'));
        $this->assertSame(['medicine'], collect($this->getJson('/api/v1/master/inventory-options')->json('data.reasons.by_item_kind.general.out'))->firstWhere('code', 'use')['not_for_categories']);
    }

    // ----------------------------------------------------------- creation

    public function test_creates_medicine_with_packaging_profile_and_opening_stock_in_one_call(): void
    {
        $loc = $this->store();
        $res = $this->create($this->body([
            'packaging' => ['unit' => 'bottle', 'quantity_per_package' => '100'], 'default_withdrawal_days' => 7, 'notes' => 'Keep cool',
            'opening_stock' => $this->opening($loc, ['lot' => ['code' => 'LOT-1', 'expires_on' => now('Africa/Lagos')->addYear()->toDateString()], 'notes' => 'Shelf count']),
        ]))->assertCreated();
        $data = $res->json('data');

        $this->assertSame('medicine', $data['category']);
        $this->assertSame('antibiotic', $data['medicine_type']);
        $this->assertSame('Antibiotic', $data['medicine_type_label']);
        $this->assertSame(['default_withdrawal_days' => 7, 'notes' => 'Keep cool'], $data['profile']);
        $this->assertSame('ml', $data['stock_unit']);
        $this->assertTrue($data['tracks_lots']);
        $this->assertTrue($data['tracks_expiry']);
        $this->assertSame('500', $data['stock']['quantity']); // 5 bottles x 100 ml
        $this->assertSame([['package_unit' => 'bottle', 'package_unit_label' => 'Bottle', 'quantity_per_package' => '100', 'unit' => 'ml']],
            array_map(fn ($p) => array_diff_key($p, ['conversion_id' => 1]), $data['packaging']));
        $this->assertSame('LOT-1', $data['lots'][0]['code']);
        $this->assertSame('500', $data['lots'][0]['stock']['quantity']);

        $movement = InventoryMovement::where('inventory_item_id', $data['id'])->sole();
        $this->assertSame('opening_balance', $movement->reason);
        $this->assertSame('500', rtrim(rtrim((string) $movement->quantity_delta, '0'), '.'));
        $this->assertSame('bottle', $movement->measurement['components'][0]['unit'] ?? 'bottle');

        // Readable through the unchanged endpoints.
        $this->getJson('/api/v1/health/medicines/'.$data['id'])->assertOk()->assertJsonPath('data.medicine_type', 'antibiotic')->assertJsonPath('data.stock.quantity', '500');
        $this->getJson('/api/v1/inventory/items/'.$data['id'])->assertOk()->assertJsonPath('data.stock.quantity', '500');
    }

    public function test_minimal_medicine_has_no_packaging_stock_expense_or_purchase(): void
    {
        $data = $this->create($this->body(['medicine_type' => 'vaccine']))->assertCreated()->json('data');
        $this->assertSame([], $data['packaging']);
        $this->assertSame('0', $data['stock']['quantity']);
        $this->assertNull($data['profile']['default_withdrawal_days']);
        $this->assertSame(0, InventoryMovement::count());

        $loc = $this->store();
        $this->create($this->body(['opening_stock' => $this->opening($loc, ['components' => [['quantity' => '40', 'unit' => 'ml']]])]))->assertCreated();
        $this->assertSame(0, FinanceTransaction::count());
        $this->assertSame(0, Purchase::count());
    }

    public function test_validation_and_rollback(): void
    {
        $loc = $this->store();
        $this->create($this->body(['medicine_type' => 'tonic']))->assertUnprocessable()->assertJsonValidationErrors('medicine_type');
        $this->create(array_diff_key($this->body(), ['medicine_type' => 1]))->assertUnprocessable()->assertJsonValidationErrors('medicine_type');
        $this->create($this->body(['stock_unit' => 'tonne']))->assertUnprocessable()->assertJsonValidationErrors('stock_unit');
        $this->create($this->body(['packaging' => ['unit' => 'crate', 'quantity_per_package' => '10']]))->assertUnprocessable()->assertJsonValidationErrors('packaging.unit');
        $this->create($this->body(['packaging' => ['unit' => 'vial', 'quantity_per_package' => '0']]))->assertUnprocessable();
        $this->create($this->body(['packaging' => ['unit' => 'vial', 'quantity_per_package' => '-5']]))->assertUnprocessable();
        $this->create($this->body(['stock_unit' => 'piece', 'packaging' => ['unit' => 'card', 'quantity_per_package' => '10.5']]))->assertUnprocessable();
        $this->create($this->body(['packaging' => ['unit' => 'vial']]))->assertUnprocessable()->assertJsonValidationErrors('packaging.quantity_per_package');
        $this->create($this->body(['default_withdrawal_days' => 4000]))->assertUnprocessable()->assertJsonValidationErrors('default_withdrawal_days');
        $this->create($this->body(['opening_stock' => ['components' => [['quantity' => '1', 'unit' => 'ml']]]]))->assertUnprocessable()->assertJsonValidationErrors(['opening_stock.storage_location_id', 'opening_stock.idempotency_key']);
        $this->create($this->body(['farm_id' => (string) Str::uuid()]))->assertUnprocessable();
        // Packages without packaging defined: nothing is guessed.
        $this->create($this->body(['opening_stock' => $this->opening($loc)]))->assertUnprocessable()->assertJsonPath('code', 'conversion_not_configured');
        // Future-dated opening stock, and expiry-tracked without an expiry, fail the whole call.
        $this->create($this->body(['opening_stock' => $this->opening($loc, ['components' => [['quantity' => '5', 'unit' => 'ml']], 'recorded_at' => now()->utc()->addDay()->format('Y-m-d\TH:i:s\Z')])]))->assertUnprocessable();
        $this->create($this->body(['tracks_expiry' => true, 'opening_stock' => $this->opening($loc, ['components' => [['quantity' => '5', 'unit' => 'ml']]])]))->assertUnprocessable();
        // Unknown / foreign location.
        $this->create($this->body(['opening_stock' => $this->opening((string) Str::uuid(), ['components' => [['quantity' => '5', 'unit' => 'ml']]])]))->assertNotFound();

        // Every failure rolled back completely: no item, profile, conversion or movement was left behind.
        $this->assertSame(0, DB::table('inventory_items')->where('category', 'medicine')->count());
        $this->assertSame(0, MedicineProfile::count());
        $this->assertSame(0, DB::table('package_conversions')->count());
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_duplicate_name_is_rejected_and_not_a_second_item(): void
    {
        $body = $this->body(['name' => 'Penstrep']);
        $this->create($body)->assertCreated();
        $this->create(array_replace($body, ['name' => '  penstrep ']))->assertConflict()->assertJsonPath('code', 'inventory_item_exists');
        $this->assertSame(1, DB::table('inventory_items')->where('normalized_name', 'penstrep')->count());
    }

    public function test_opening_stock_is_idempotent(): void
    {
        $loc = $this->store();
        $body = $this->body(['packaging' => ['unit' => 'bottle', 'quantity_per_package' => '100'], 'opening_stock' => $this->opening($loc, ['idempotency_key' => 'open-1'])]);
        $first = $this->create($body)->assertCreated()->json('data');
        $replay = $this->create($body)->assertOk()->assertJsonPath('message', 'Medicine already created.')->json('data');

        $this->assertSame($first['id'], $replay['id']);
        $this->assertSame('500', $replay['stock']['quantity']);
        $this->assertSame(1, InventoryMovement::where('inventory_item_id', $first['id'])->count());
        $this->assertSame(1, DB::table('package_conversions')->where('context_id', $first['id'])->count());

        // The same key reused for a different medicine is a conflict, and creates nothing.
        $this->create(array_replace($body, ['name' => 'Something else']))->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame(1, DB::table('inventory_items')->where('category', 'medicine')->count());
    }

    public function test_idempotency_keys_do_not_cross_farms(): void
    {
        $loc = $this->store();
        $body = $this->body(['name' => 'Shared name', 'opening_stock' => $this->opening($loc, ['idempotency_key' => 'open-x', 'components' => [['quantity' => '10', 'unit' => 'ml']]])]);
        $this->create($body)->assertCreated();

        [$other] = $this->otherFarm();
        $this->signInAs($other);
        $theirLoc = $this->store();
        $body['opening_stock']['storage_location_id'] = $theirLoc;
        $this->create($body)->assertCreated(); // same name and key in another farm: independent
        // Another farm's location is never usable.
        $this->signInAs($this->owner);
        $body['name'] = 'Third';
        $body['opening_stock']['idempotency_key'] = 'open-y';
        $this->create($body)->assertNotFound();
    }

    public function test_permissions(): void
    {
        $this->signInAs($this->member(FarmRole::FarmWorker));
        $this->create($this->body())->assertForbidden();
        $this->getJson('/api/v1/master/medicine-options')->assertOk(); // health.view

        $this->signInAs($this->member(FarmRole::Finance));
        $this->create($this->body())->assertForbidden();
        $this->getJson('/api/v1/master/medicine-options')->assertForbidden();

        // A vet manages health but not inventory: the workflow needs both, so nothing is created.
        $this->signInAs($this->member(FarmRole::Vet));
        $this->create($this->body())->assertForbidden();

        $this->signInAs($this->member(FarmRole::Manager));
        $this->create($this->body(['packaging' => ['unit' => 'box', 'quantity_per_package' => '10']]))->assertCreated();
        $this->assertSame(1, DB::table('inventory_items')->where('category', 'medicine')->count());
    }

    // ----------------------------------------------------------- profile endpoint compatibility

    public function test_existing_medicines_are_unspecified_and_profile_endpoint_sets_type(): void
    {
        $id = $this->postJson('/api/v1/inventory/items', ['name' => 'Legacy med', 'category' => 'medicine', 'stock_unit' => 'ml'])->assertCreated()->json('data.id');
        $this->getJson('/api/v1/health/medicines/'.$id)->assertOk()->assertJsonPath('medicine_type', null)->assertJsonPath('data.medicine_type', null)->assertJsonPath('data.packaging', []);

        // Old clients: the withdrawal-only body still works and does not touch the type.
        $this->putJson('/api/v1/health/medicines/'.$id.'/profile', ['default_withdrawal_days' => 3])->assertOk()->assertJsonPath('data.medicine_type', null);
        $this->putJson('/api/v1/health/medicines/'.$id.'/profile', ['default_withdrawal_days' => 3, 'medicine_type' => 'dewormer'])->assertOk()->assertJsonPath('data.medicine_type', 'dewormer');
        $this->putJson('/api/v1/health/medicines/'.$id.'/profile', ['default_withdrawal_days' => 5])->assertOk()->assertJsonPath('data.medicine_type', 'dewormer');
        $this->putJson('/api/v1/health/medicines/'.$id.'/profile', ['default_withdrawal_days' => 5, 'medicine_type' => 'tonic'])->assertUnprocessable();
        $this->putJson('/api/v1/health/medicines/'.$id.'/profile', ['default_withdrawal_days' => 5, 'medicine_type' => null])->assertOk()->assertJsonPath('data.medicine_type', null);
        $this->getJson('/api/v1/health/medicines')->assertOk()->assertJsonPath('data.0.medicine_type', null);
    }

    // ----------------------------------------------------------- consumption: health vs manual

    public function test_treatment_use_is_recorded_by_health_records_and_manual_use_is_refused(): void
    {
        $loc = $this->store();
        $med = $this->create($this->body(['default_withdrawal_days' => 4, 'packaging' => ['unit' => 'bottle', 'quantity_per_package' => '100'], 'opening_stock' => $this->opening($loc)]))->assertCreated()->json('data');
        $cycle = $this->cycle();

        // Health record: 2 bottles + 50 ml = 250 ml, deducted exactly once, withdrawal inherited from the profile.
        $payload = ['production_cycle_id' => $cycle, 'type' => 'vaccination', 'recorded_at' => $this->at(2), 'idempotency_key' => 'hr-1', 'details' => ['target_disease' => 'Newcastle'],
            'medicines' => [['inventory_item_id' => $med['id'], 'storage_location_id' => $loc, 'components' => [['quantity' => '2', 'unit' => 'bottle'], ['quantity' => '50', 'unit' => 'ml']]]]];
        $record = $this->postJson('/api/v1/health-records', $payload)->assertCreated()->json('data');
        $this->postJson('/api/v1/health-records', $payload)->assertSuccessful(); // idempotent replay (same record, no second deduction)
        $this->assertSame('250', $this->getJson('/api/v1/health/medicines/'.$med['id'])->json('data.stock.quantity'));
        $this->assertSame('item_default', $record['medicines'][0]['withdrawal']['source']);
        $this->assertSame(4, $record['medicines'][0]['withdrawal']['days']);
        $this->assertSame(1, InventoryMovement::where('inventory_item_id', $med['id'])->where('health_record_id', $record['id'])->count());

        // Manual routine consumption is refused server-side, for use and internal_use, and changes nothing.
        foreach (['use', 'internal_use'] as $reason) {
            $res = $this->postJson('/api/v1/inventory/stock-out', ['inventory_item_id' => $med['id'], 'storage_location_id' => $loc, 'reason' => $reason,
                'components' => [['quantity' => '10', 'unit' => 'ml']], 'recorded_at' => $this->at(1), 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
            $this->assertStringContainsString('/health-records', json_encode($res->json()));
        }
        $this->assertSame('250', $this->getJson('/api/v1/inventory/items/'.$med['id'])->json('data.stock.quantity'));

        // Legitimate manual reductions still work; non-medicine items keep "use".
        foreach (['expired', 'damaged', 'lost', 'disposal'] as $i => $reason) {
            $this->postJson('/api/v1/inventory/stock-out', ['inventory_item_id' => $med['id'], 'storage_location_id' => $loc, 'reason' => $reason,
                'components' => [['quantity' => '10', 'unit' => 'ml']], 'recorded_at' => $this->at(1), 'idempotency_key' => 'out-'.$i])->assertCreated();
        }
        $this->assertSame('210', $this->getJson('/api/v1/inventory/items/'.$med['id'])->json('data.stock.quantity'));
        $general = $this->postJson('/api/v1/inventory/items', ['name' => 'Gloves', 'category' => 'general_supply', 'stock_unit' => 'piece'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/inventory/stock-in', ['inventory_item_id' => $general, 'storage_location_id' => $loc, 'reason' => 'purchase', 'components' => [['quantity' => '20', 'unit' => 'piece']], 'recorded_at' => $this->at(5), 'idempotency_key' => 'g-in'])->assertCreated();
        $this->postJson('/api/v1/inventory/stock-out', ['inventory_item_id' => $general, 'storage_location_id' => $loc, 'reason' => 'use', 'components' => [['quantity' => '2', 'unit' => 'piece']], 'recorded_at' => $this->at(1), 'idempotency_key' => 'g-out'])->assertCreated();

        // Reversal still compensates once; history stays readable.
        $this->postJson('/api/v1/health-records/'.$record['id'].'/reverse', ['reason' => 'Entered twice', 'recorded_at' => $this->at(1), 'idempotency_key' => 'rev-1'])->assertCreated();
        $this->assertSame('460', $this->getJson('/api/v1/inventory/items/'.$med['id'])->json('data.stock.quantity'));
        $this->getJson('/api/v1/inventory/items/'.$med['id'].'/movements')->assertOk();
    }

    public function test_historical_manual_use_movements_remain_readable_and_balances_unchanged(): void
    {
        $loc = $this->store();
        $med = $this->create($this->body(['opening_stock' => $this->opening($loc, ['components' => [['quantity' => '100', 'unit' => 'ml']]])]))->assertCreated()->json('data');
        // A pre-existing manual "use" row (written before the rule existed) is simulated directly.
        $first = InventoryMovement::where('inventory_item_id', $med['id'])->firstOrFail();
        $legacy = $first->replicate();
        $legacy->forceFill(['id' => (string) Str::uuid7(), 'type' => 'stock_out', 'reason' => 'use', 'quantity_delta' => '-30', 'idempotency_key' => 'legacy-use', 'request_hash' => null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('inventory_movements')->insert(array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, $legacy->getAttributes()));

        $this->assertSame('70', $this->getJson('/api/v1/health/medicines/'.$med['id'])->json('data.stock.quantity'));
        $reasons = collect($this->getJson('/api/v1/inventory/movements?inventory_item_id='.$med['id'])->assertOk()->json('data'))->pluck('reason');
        $this->assertTrue($reasons->contains('use'));
    }

    // ----------------------------------------------------------- medicine_type filter

    public function test_medicine_type_filter_validates_and_is_farm_scoped(): void
    {
        $vaccine = $this->create($this->body(['name' => 'Lasota', 'medicine_type' => 'vaccine']))->assertCreated()->json('data.id');
        $this->create($this->body(['name' => 'Penstrep', 'medicine_type' => 'antibiotic']))->assertCreated();
        $legacy = $this->postJson('/api/v1/inventory/items', ['name' => 'Legacy med', 'category' => 'medicine', 'stock_unit' => 'ml'])->assertCreated()->json('data.id');

        $this->assertCount(3, $this->getJson('/api/v1/health/medicines')->assertOk()->json('data')); // unfiltered unchanged
        $this->assertSame([$vaccine], array_column($this->getJson('/api/v1/health/medicines?medicine_type=vaccine')->assertOk()->json('data'), 'id'));
        $this->assertSame([], $this->getJson('/api/v1/health/medicines?medicine_type=dewormer')->assertOk()->json('data'));
        $this->assertNotContains($legacy, array_column($this->getJson('/api/v1/health/medicines?medicine_type=other')->json('data'), 'id'));
        $this->getJson('/api/v1/health/medicines?medicine_type=vaccine&search=penstrep')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/health/medicines?medicine_type=tonic')->assertUnprocessable()->assertJsonValidationErrors('medicine_type');

        // Same type in another farm: never visible here, and ours never there.
        [$other] = $this->otherFarm();
        $this->signInAs($other);
        $mine = $this->create($this->body(['name' => 'Their vaccine', 'medicine_type' => 'vaccine']))->assertCreated()->json('data.id');
        $this->assertSame([$mine], array_column($this->getJson('/api/v1/health/medicines?medicine_type=vaccine')->json('data'), 'id'));
    }

    // ----------------------------------------------------------- request-level idempotency

    public function test_request_key_without_opening_stock_replays_and_conflicts(): void
    {
        $body = $this->body(['name' => 'Keyed', 'idempotency_key' => 'create-keyed-1', 'packaging' => ['unit' => 'vial', 'quantity_per_package' => '10']]);
        $first = $this->create($body)->assertCreated()->json('data');
        $replay = $this->create($body)->assertOk()->assertJsonPath('message', 'Medicine already created.')->json('data');
        $this->assertSame($first['id'], $replay['id']);
        $this->assertSame(1, DB::table('inventory_items')->where('category', 'medicine')->count());
        $this->assertSame(1, DB::table('package_conversions')->count());

        foreach ([['name' => 'Renamed'], ['medicine_type' => 'vaccine'], ['packaging' => ['unit' => 'vial', 'quantity_per_package' => '20']], ['default_withdrawal_days' => 3]] as $change) {
            $this->create(array_replace($body, $change))->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        }
        $this->assertSame(1, DB::table('inventory_items')->where('category', 'medicine')->count());
        $this->assertSame(1, DB::table('package_conversions')->count());
        $this->create(array_replace($body, ['idempotency_key' => 'bad key!']))->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        // A different key with the same name is the existing duplicate-name conflict, not a replay.
        $this->create(array_replace($body, ['idempotency_key' => 'other-key']))->assertConflict()->assertJsonPath('code', 'inventory_item_exists');
    }

    public function test_request_key_governs_opening_stock_without_a_second_key(): void
    {
        $loc = $this->store();
        $stock = ['storage_location_id' => $loc, 'components' => [['quantity' => '5', 'unit' => 'bottle']], 'recorded_at' => $this->at(48)];
        $body = $this->body(['name' => 'Keyed stock', 'idempotency_key' => 'create-keyed-2', 'packaging' => ['unit' => 'bottle', 'quantity_per_package' => '100'], 'opening_stock' => $stock]);

        $first = $this->create($body)->assertCreated()->json('data');
        $this->assertSame('500', $first['stock']['quantity']);
        $replay = $this->create($body)->assertOk()->json('data');
        $this->assertSame($first['id'], $replay['id']);
        $this->assertSame('500', $replay['stock']['quantity']);
        $this->assertSame(1, InventoryMovement::where('inventory_item_id', $first['id'])->count());
        $this->assertSame('medicine-open:create-keyed-2', InventoryMovement::where('inventory_item_id', $first['id'])->value('idempotency_key'));

        // Changed stock under the same key: conflict, nothing added.
        $changed = $body;
        $changed['opening_stock']['components'] = [['quantity' => '6', 'unit' => 'bottle']];
        $this->create($changed)->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame(1, InventoryMovement::where('inventory_item_id', $first['id'])->count());
        $this->assertSame('500', $this->getJson('/api/v1/health/medicines/'.$first['id'])->json('data.stock.quantity'));

        // Without any key the opening-stock key is still required (existing contract).
        $this->create($this->body(['opening_stock' => $stock]))->assertUnprocessable()->assertJsonValidationErrors('opening_stock.idempotency_key');
    }

    public function test_request_keys_are_farm_scoped_and_failed_attempts_do_not_burn_the_key(): void
    {
        $body = $this->body(['name' => 'Scoped', 'idempotency_key' => 'create-keyed-3']);
        $mine = $this->create($body)->assertCreated()->json('data.id');
        [$other] = $this->otherFarm();
        $this->signInAs($other);
        $theirs = $this->create($body)->assertCreated()->json('data.id');
        $this->assertNotSame($mine, $theirs);

        // A rolled-back attempt leaves no key behind: the corrected retry with the same key succeeds.
        $bad = $this->body(['name' => 'Retry me', 'idempotency_key' => 'create-keyed-4', 'opening_stock' => ['storage_location_id' => $this->store(), 'components' => [['quantity' => '1', 'unit' => 'bottle']], 'recorded_at' => $this->at(2)]]);
        $this->create($bad)->assertUnprocessable()->assertJsonPath('code', 'conversion_not_configured');
        $good = $bad;
        $good['packaging'] = ['unit' => 'bottle', 'quantity_per_package' => '50'];
        $this->create($good)->assertCreated()->assertJsonPath('data.stock.quantity', '50');
    }
}
