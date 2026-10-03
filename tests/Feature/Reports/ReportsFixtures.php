<?php

namespace Tests\Feature\Reports;

use App\Models\CropType;
use App\Models\OperationType;
use App\Models\Species;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Data builders for the Phase 17 tests. The fixed clock is 2026-10-14 11:00 UTC = 12:00 in Africa/Lagos, so "today" is unambiguous; the
 * boundary tests move it on purpose. Everything is created through the public API so the reports are tested against real ledgers.
 */
trait ReportsFixtures
{
    protected function bootClock(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 11:00:00', 'UTC'));
    }

    protected function at(int $hoursAgo = 1): string
    {
        return now()->utc()->subHours($hoursAgo)->format('Y-m-d\TH:i:s\Z');
    }

    protected function key(): string
    {
        return (string) Str::uuid();
    }

    protected function today(): string
    {
        return now('Africa/Lagos')->toDateString();
    }

    protected function day(int $offset): string
    {
        return CarbonImmutable::parse($this->today(), 'UTC')->addDays($offset)->toDateString();
    }

    protected function layers(int $population = 100, array $extra = []): string
    {
        return $this->postJson('/api/v1/production-cycles', array_replace(['kind' => 'livestock', 'name' => 'Layers '.Str::random(4), 'operation_type_id' => OperationType::where('code', 'poultry')->firstOrFail()->id,
            'species_id' => Species::where('code', 'chicken')->firstOrFail()->id, 'initial_population' => $population, 'start_date' => '2026-01-01'], $extra))->assertCreated()->json('data.id');
    }

    protected function yam(array $extra = []): string
    {
        return $this->postJson('/api/v1/production-cycles', array_replace(['kind' => 'crop', 'name' => 'Yam '.Str::random(4), 'operation_type_id' => OperationType::where('code', 'crops')->firstOrFail()->id,
            'crop_type_id' => CropType::where('code', 'yam')->firstOrFail()->id, 'planting_material_type' => 'tuber', 'planting_unit_type' => 'heap', 'initial_planting_units' => 500, 'planting_date' => '2026-01-10'], $extra))->assertCreated()->json('data.id');
    }

    protected function record(string $cycle, string $type, array $details, int $hoursAgo = 2)
    {
        return $this->postJson('/api/v1/records', ['production_cycle_id' => $cycle, 'type' => $type, 'recorded_at' => $this->at($hoursAgo), 'idempotency_key' => $this->key(), 'details' => $details]);
    }

    protected function mortality(string $cycle, int $quantity, int $hoursAgo = 2, string $cause = 'Disease'): array
    {
        return $this->record($cycle, 'mortality', ['quantity' => $quantity, 'cause' => $cause], $hoursAgo)->assertCreated()->json('data');
    }

    protected function reverseRecord(string $id): void
    {
        $this->postJson('/api/v1/records/'.$id.'/reverse', ['reason' => 'Entered twice', 'recorded_at' => $this->at(1), 'idempotency_key' => $this->key()])->assertCreated();
    }

    protected function eggs(string $cycle, int $count, int $hoursAgo = 2): array
    {
        return $this->record($cycle, 'egg_collection', ['components' => [['quantity' => $count, 'unit' => 'piece']]], $hoursAgo)->assertCreated()->json('data');
    }

    protected function harvest(string $cycle, string $quantity, string $unit = 'kg', int $hoursAgo = 2): array
    {
        return $this->record($cycle, 'crop_harvest', ['components' => [['quantity' => $quantity, 'unit' => $unit]]], $hoursAgo)->assertCreated()->json('data');
    }

    protected function task(array $extra = [])
    {
        return $this->postJson('/api/v1/tasks', array_replace(['title' => 'Check drinkers', 'category' => 'feeding_watering', 'due_date' => $this->today(), 'idempotency_key' => $this->key()], $extra));
    }

    protected function store(): string
    {
        return $this->postJson('/api/v1/storage-locations', ['name' => 'Store '.Str::random(5), 'type' => 'store'])->assertCreated()->json('data.id');
    }

    protected function item(string $category = 'produce', string $unit = 'piece', array $extra = []): string
    {
        return $this->postJson('/api/v1/inventory/items', array_replace(['name' => ucfirst($category).' '.Str::random(6), 'category' => $category, 'stock_unit' => $unit], $extra))->assertCreated()->json('data.id');
    }

    protected function receive(string $item, string $loc, string $qty, string $unit = 'piece', array $extra = []): array
    {
        return $this->postJson('/api/v1/inventory/stock-in', array_replace(['inventory_item_id' => $item, 'storage_location_id' => $loc, 'reason' => 'opening_balance', 'components' => [['quantity' => $qty, 'unit' => $unit]],
            'recorded_at' => $this->at(30), 'idempotency_key' => $this->key()], $extra))->assertCreated()->json('data');
    }

    protected function customer(?string $name = null): string
    {
        return $this->postJson('/api/v1/contacts', ['name' => $name ?? 'Buyer '.Str::random(4), 'kind' => 'business', 'roles' => ['customer']])->assertCreated()->json('data.id');
    }

    /** A sale of $amount (eggs out of stock) with an invoice: [sale, invoice]. */
    protected function invoicedSale(string $amount, array $invoice = [], int $saleHoursAgo = 3, ?string $contact = null, int $stockHoursAgo = 30): array
    {
        $eggs = $this->item();
        $loc = $this->store();
        $this->receive($eggs, $loc, '1000', 'piece', ['recorded_at' => $this->at($stockHoursAgo)]);
        $sale = $this->postJson('/api/v1/sales', ['recorded_at' => $this->at($saleHoursAgo), 'idempotency_key' => $this->key(), 'contact_id' => $contact ?? $this->customer(),
            'items' => [['kind' => 'stock', 'inventory_item_id' => $eggs, 'storage_location_id' => $loc, 'components' => [['quantity' => '100', 'unit' => 'piece']], 'amount' => $amount]],
            'invoice' => $invoice ?: (object) []])->assertCreated()->json('data');

        return [$sale, $sale['invoice']];
    }

    protected function pay(string $invoice, string $amount)
    {
        return $this->postJson('/api/v1/invoices/'.$invoice.'/payments', ['amount' => $amount, 'method' => 'cash', 'received_on' => $this->today(), 'idempotency_key' => $this->key()]);
    }

    protected function category(string $code, string $direction): string
    {
        foreach ($this->getJson('/api/v1/finance/categories?direction='.$direction)->assertOk()->json('data') as $row) {
            if ($row['code'] === $code) {
                return $row['id'];
            }
        }
        $this->fail("Category $code missing");
    }

    protected function income(string $amount, ?string $on = null, ?string $cycle = null): array
    {
        return $this->postJson('/api/v1/income', array_filter(['finance_category_id' => $this->category('egg_sales', 'income'), 'amount' => $amount, 'occurred_on' => $on ?? $this->today(), 'production_cycle_id' => $cycle, 'idempotency_key' => $this->key()]))->assertCreated()->json('data');
    }

    protected function expense(string $amount, ?string $on = null, ?string $cycle = null): array
    {
        return $this->postJson('/api/v1/expenses', array_filter(['finance_category_id' => $this->category('transport', 'expense'), 'amount' => $amount, 'occurred_on' => $on ?? $this->today(), 'production_cycle_id' => $cycle, 'idempotency_key' => $this->key()]))->assertCreated()->json('data');
    }

    protected function report(string $code, array $query = [], int $expect = 200)
    {
        $response = $this->getJson('/api/v1/reports/'.$code.($query ? '?'.http_build_query($query) : ''));
        $expect === 200 ? $response->assertOk() : $response->assertStatus($expect);

        return $response;
    }

    /** @return array<int, array<string, mixed>> */
    protected function rows(string $code, array $query = []): array
    {
        return $this->report($code, $query)->json('data.rows');
    }
}
