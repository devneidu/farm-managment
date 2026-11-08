<?php

namespace Tests\Feature\Reports;

use App\Models\OperationType;
use App\Models\Species;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Team\TeamTestCase;

/** The custom_records report: records of farm-made types per type and batch, with exact sums of the number fields. */
class CustomRecordsReportTest extends TeamTestCase
{
    private const FILTER = 'from=2026-01-01&to=2026-12-31';

    private string $a;

    private string $b;

    private array $eggCheck;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->onPlan('farm-business');
        $this->signInAs($this->owner);
        $this->a = $this->flock('Flock A');
        $this->b = $this->flock('Flock B');
        $this->eggCheck = $this->postJson('/api/v1/custom-record-types', ['name' => 'Egg check', 'applies_to' => 'livestock', 'fields' => [
            ['label' => 'Crates', 'kind' => 'number', 'unit' => 'crates'], ['label' => 'Cracked', 'kind' => 'whole_number'], ['label' => 'Remark', 'kind' => 'short_text'],
        ]])->assertCreated()->json('data');
    }

    private function flock(string $name): string
    {
        return $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => $name, 'operation_type_id' => OperationType::where('code', 'poultry')->firstOrFail()->id,
            'species_id' => Species::where('code', 'chicken')->firstOrFail()->id, 'production_purpose' => 'breeding', 'initial_population' => 50, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
    }

    private function record(string $typeId, string $cycle, array $details, string $at = '2026-02-01T10:00:00Z'): array
    {
        return $this->postJson('/api/v1/records', ['production_cycle_id' => $cycle, 'type' => 'custom', 'custom_record_type_id' => $typeId, 'details' => $details,
            'recorded_at' => $at, 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data');
    }

    private function report(string $query = self::FILTER)
    {
        return $this->getJson('/api/v1/reports/custom_records?'.$query);
    }

    private function seedRecords(): void
    {
        $this->record($this->eggCheck['id'], $this->a, ['crates' => '3', 'cracked' => 1]);
        $this->record($this->eggCheck['id'], $this->a, ['crates' => '4.5', 'cracked' => 2], '2026-02-10T10:00:00Z');
        $this->record($this->eggCheck['id'], $this->b, ['crates' => '10'], '2026-03-01T10:00:00Z');
        $wrong = $this->record($this->eggCheck['id'], $this->a, ['crates' => '100', 'cracked' => 50], '2026-02-20T10:00:00Z');
        $this->postJson('/api/v1/records/'.$wrong['id'].'/reverse', ['reason' => 'Typo', 'recorded_at' => '2026-02-21T10:00:00Z', 'idempotency_key' => (string) Str::uuid()])->assertCreated();
    }

    public function test_it_counts_records_and_sums_each_number_field_per_type_and_batch_without_reversed_ones(): void
    {
        $this->seedRecords();
        $data = $this->report()->assertOk()->json('data');

        $this->assertSame('custom_records', $data['report']['code']);
        $rows = collect($data['rows']);
        $cell = fn (string $ref, string $field) => $rows->first(fn ($r) => $r['name'] === $ref && $r['field'] === $field);

        $this->assertEquals(['records' => 2, 'sum' => '7.5', 'unit' => 'crates', 'type' => 'Egg check'], array_intersect_key($cell('Flock A', 'Crates'), array_flip(['records', 'sum', 'unit', 'type'])));
        $this->assertSame('3', $cell('Flock A', 'Cracked')['sum']);
        $this->assertSame(1, $cell('Flock B', 'Crates')['records']);
        $this->assertSame('10', $cell('Flock B', 'Crates')['sum']);
        $this->assertSame('0', $cell('Flock B', 'Cracked')['sum'], 'no value recorded sums to zero');
        $this->assertCount(4, $rows, 'one row per batch and number field; the text field has none');

        $this->assertSame(3, $data['summary']['records'], 'each record counted once; the reversed one and its reversal are not');
        $totals = collect($data['summary']['sums'])->keyBy('field');
        $this->assertSame('17.5', $totals['Crates']['sum']);
        $this->assertSame('3', $totals['Cracked']['sum']);
    }

    public function test_it_follows_the_date_range_batch_and_type_filters(): void
    {
        $this->seedRecords();
        $notes = $this->postJson('/api/v1/custom-record-types', ['name' => 'Notes', 'applies_to' => 'livestock', 'fields' => [['label' => 'Note', 'kind' => 'short_text']]])->assertCreated()->json('data');
        $this->record($notes['id'], $this->b, ['note' => 'Fine'], '2026-03-02T10:00:00Z');

        $march = $this->report('from=2026-03-01&to=2026-03-31')->assertOk()->json('data');
        $this->assertSame(2, $march['summary']['records']);
        $this->assertEqualsCanonicalizing(['Egg check', 'Notes'], array_values(array_unique(array_column($march['rows'], 'type'))));
        $noteRow = collect($march['rows'])->firstWhere('type', 'Notes');
        $this->assertNull($noteRow['field'], 'a type with no number field still shows its record count');
        $this->assertSame(1, $noteRow['records']);

        $this->assertSame(2, $this->report(self::FILTER.'&production_cycle_id='.$this->a)->json('data.summary.records'));
        $onlyNotes = $this->report(self::FILTER.'&custom_record_type_id='.$notes['id'])->json('data');
        $this->assertSame(['Notes'], array_values(array_unique(array_column($onlyNotes['rows'], 'type'))));
        $this->assertSame([], $this->report('from=2026-06-01&to=2026-06-30')->json('data.rows'));
    }

    public function test_it_is_in_the_catalogue_and_exports_to_csv(): void
    {
        $this->seedRecords();
        $entry = collect($this->getJson('/api/v1/reports')->assertOk()->json('data'))->firstWhere('code', 'custom_records');
        $this->assertContains('custom_record_type_id', $entry['filters']);
        $this->assertTrue($entry['exportable']);

        $created = $this->postJson('/api/v1/reports/exports', ['report' => 'custom_records', 'format' => 'csv', 'filters' => ['from' => '2026-01-01', 'to' => '2026-12-31'], 'idempotency_key' => (string) Str::uuid()])->assertStatus(202)->json('data');
        $done = $this->getJson('/api/v1/reports/exports/'.$created['id'])->assertOk()->json('data');
        $this->assertSame('completed', $done['status']);
        $this->assertSame(4, $done['row_count']);
        $csv = $this->get('/api/v1/reports/exports/'.$created['id'].'/download')->assertOk()->streamedContent();
        $this->assertStringContainsString('Record type', $csv);
        $this->assertStringContainsString('Egg check', $csv);
        $this->assertStringContainsString('Flock A', $csv);
    }

    public function test_another_farms_records_and_types_never_appear(): void
    {
        $this->seedRecords();
        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $theirs = $this->postJson('/api/v1/custom-record-types', ['name' => 'Their check', 'applies_to' => 'livestock', 'fields' => [['label' => 'Amount', 'kind' => 'number']]])->assertCreated()->json('data');
        $cycle = $this->flock('Their flock');
        $this->record($theirs['id'], $cycle, ['amount' => '99']);
        $this->assertSame(1, $this->report()->json('data.summary.records'));

        $this->signInAs($this->owner);
        $this->assertSame(3, $this->report()->json('data.summary.records'));
        $this->assertNotContains('Their check', array_column($this->report()->json('data.rows'), 'type'));
        $this->report(self::FILTER.'&custom_record_type_id='.$theirs['id'])->assertUnprocessable()->assertJsonValidationErrors('custom_record_type_id');
        $this->report(self::FILTER.'&production_cycle_id='.$cycle)->assertUnprocessable();
    }
}
