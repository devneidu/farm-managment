<?php

namespace Tests\Feature\Health;

use App\Enums\HealthProblemType;
use App\Models\HealthRecord;
use App\Models\OperationType;
use App\Models\Species;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Team\TeamTestCase;

class HealthProblemTypeTest extends TeamTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAs($this->owner);
    }

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: string|null}> type, text field, other required details, default problem type */
    public static function types(): array
    {
        return [
            'vaccination' => ['vaccination', 'target_disease', [], 'other'],
            'medication' => ['medication', 'condition', [], 'other'],
            'deworming' => ['deworming', 'parasite_target', [], 'worms_internal_parasites'],
            'treatment' => ['treatment', 'condition', [], 'other'],
            'disease_issue' => ['disease_issue', 'condition', ['severity' => 'low'], 'other'],
            'vet_visit' => ['vet_visit', 'condition', ['vet_name' => 'Dr Ade'], 'other'],
        ];
    }

    private function at(): string
    {
        return now()->utc()->subHours(2)->format('Y-m-d\TH:i:s\Z');
    }

    /** @return array{0: string, 1: array<string, mixed>} cycle id and one valid medicine line */
    private function ready(): array
    {
        $cycle = $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => 'Flock '.Str::random(4), 'operation_type_id' => OperationType::where('code', 'poultry')->firstOrFail()->id,
            'species_id' => Species::where('code', 'chicken')->firstOrFail()->id, 'production_purpose' => 'breeding', 'initial_population' => 100, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
        $item = $this->postJson('/api/v1/inventory/items', ['name' => 'Med '.Str::random(6), 'category' => 'medicine', 'stock_unit' => 'ml'])->assertCreated()->json('data.id');
        $loc = $this->postJson('/api/v1/storage-locations', ['name' => 'Cabinet '.Str::random(4), 'type' => 'store'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/inventory/stock-in', ['inventory_item_id' => $item, 'storage_location_id' => $loc, 'reason' => 'purchase', 'components' => [['quantity' => '500', 'unit' => 'ml']],
            'recorded_at' => now()->utc()->subHours(48)->format('Y-m-d\TH:i:s\Z'), 'idempotency_key' => (string) Str::uuid()])->assertCreated();

        return [$cycle, ['inventory_item_id' => $item, 'storage_location_id' => $loc, 'components' => [['quantity' => '10', 'unit' => 'ml']]]];
    }

    private function body(string $cycle, array $line, string $type, array $details): array
    {
        $uses = in_array($type, ['disease_issue', 'vet_visit'], true) ? [] : [$line];

        return ['production_cycle_id' => $cycle, 'type' => $type, 'recorded_at' => $this->at(), 'idempotency_key' => (string) Str::uuid(), 'details' => $details, 'medicines' => $uses];
    }

    #[DataProvider('types')]
    public function test_problem_type_is_validated_saved_defaulted_and_other_needs_text(string $type, string $text, array $extra, string $default): void
    {
        [$cycle, $line] = $this->ready();

        // Valid choice is saved and returned with its label; the text box is optional.
        $ok = $this->postJson('/api/v1/health-records', $this->body($cycle, $line, $type, $extra + ['problem_type' => 'breathing']))->assertCreated()
            ->assertJsonPath('data.problem_type', 'breathing')->assertJsonPath('data.problem_type_label', HealthProblemType::Breathing->label())->assertJsonPath('data.details.problem_type', 'breathing');
        $this->assertSame('breathing', HealthRecord::findOrFail($ok->json('data.id'))->details['problem_type']);

        // Unknown choice is refused.
        $this->postJson('/api/v1/health-records', $this->body($cycle, $line, $type, $extra + ['problem_type' => 'fever']))->assertStatus(422)->assertJsonValidationErrors('details.problem_type');

        // "other" needs the text, with a clear message; with text it passes.
        $res = $this->postJson('/api/v1/health-records', $this->body($cycle, $line, $type, $extra + ['problem_type' => 'other']))->assertStatus(422)->assertJsonValidationErrors('details.'.$text);
        $this->assertStringContainsString('Disease name (if known)', json_encode($res->json()));
        $this->postJson('/api/v1/health-records', $this->body($cycle, $line, $type, $extra + ['problem_type' => 'other', $text => '   ']))->assertStatus(422)->assertJsonValidationErrors('details.'.$text);
        $this->postJson('/api/v1/health-records', $this->body($cycle, $line, $type, $extra + ['problem_type' => 'other', $text => 'Fowl pox']))->assertCreated()
            ->assertJsonPath('data.problem_type', 'other')->assertJsonPath('data.details.'.$text, 'Fowl pox');

        // Omitted: accepted without text and stored as the type's default.
        $this->postJson('/api/v1/health-records', $this->body($cycle, $line, $type, $extra))->assertCreated()
            ->assertJsonPath('data.problem_type', $default)->assertJsonPath('data.problem_type_label', HealthProblemType::from($default)->label());

        // Old-style request: text only, no problem_type.
        $this->postJson('/api/v1/health-records', $this->body($cycle, $line, $type, $extra + [$text => 'Coryza']))->assertCreated()
            ->assertJsonPath('data.problem_type', $default)->assertJsonPath('data.details.'.$text, 'Coryza');
    }

    public function test_vaccination_without_a_disease_name_passes_when_a_problem_type_is_given(): void
    {
        [$cycle, $line] = $this->ready();
        $this->postJson('/api/v1/health-records', $this->body($cycle, $line, 'vaccination', ['problem_type' => 'routine_prevention']))->assertCreated()
            ->assertJsonPath('data.problem_type', 'routine_prevention')->assertJsonMissingPath('data.details.target_disease');
    }

    public function test_index_filters_by_problem_type_and_validates_it(): void
    {
        [$cycle, $line] = $this->ready();
        $this->postJson('/api/v1/health-records', $this->body($cycle, $line, 'vaccination', ['problem_type' => 'breathing']))->assertCreated();
        $this->postJson('/api/v1/health-records', $this->body($cycle, $line, 'treatment', ['problem_type' => 'eye']))->assertCreated();
        $this->postJson('/api/v1/health-records', $this->body($cycle, $line, 'treatment', ['problem_type' => 'eye']))->assertCreated();

        $this->getJson('/api/v1/health-records?problem_type=eye')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.problem_type', 'eye');
        $this->getJson('/api/v1/health-records?problem_type=breathing')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/health-records?problem_type=injury_wound')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/health-records?problem_type=fever')->assertStatus(422)->assertJsonValidationErrors('problem_type');
    }

    public function test_reversal_and_correction_keep_the_problem_type(): void
    {
        [$cycle, $line] = $this->ready();
        $original = $this->postJson('/api/v1/health-records', $this->body($cycle, $line, 'treatment', ['problem_type' => 'digestive']))->assertCreated()->json('data');
        $this->postJson('/api/v1/health-records/'.$original['id'].'/reverse', ['reason' => 'Wrong dose', 'recorded_at' => $this->at(), 'idempotency_key' => (string) Str::uuid()])->assertCreated()
            ->assertJsonPath('data.problem_type', 'digestive')->assertJsonPath('data.problem_type_label', HealthProblemType::Digestive->label());

        // A correction is a normal write: same validation, same default rules.
        $correction = $this->body($cycle, $line, 'treatment', ['problem_type' => 'other']) + ['corrects_record_id' => $original['id']];
        $this->postJson('/api/v1/health-records', $correction)->assertStatus(422)->assertJsonValidationErrors('details.condition');
        $correction['details']['condition'] = 'Fowl pox';
        $this->postJson('/api/v1/health-records', $correction)->assertCreated()->assertJsonPath('data.problem_type', 'other')->assertJsonPath('data.corrects_record_id', $original['id']);
    }

    public function test_records_written_before_problem_type_return_null(): void
    {
        [$cycle, $line] = $this->ready();
        $id = $this->postJson('/api/v1/health-records', $this->body($cycle, $line, 'treatment', ['condition' => 'Coryza']))->assertCreated()->json('data.id');
        // Simulate a row from before the feature: details without problem_type. Nothing is backfilled or inferred.
        HealthRecord::whereKey($id)->update(['details' => json_encode(['condition' => 'Coryza'])]);

        $this->getJson('/api/v1/health-records/'.$id)->assertOk()->assertJsonPath('data.problem_type', null)->assertJsonPath('data.problem_type_label', null)->assertJsonPath('data.details.condition', 'Coryza');
        $this->getJson('/api/v1/health-records')->assertOk()->assertJsonPath('data.0.problem_type', null);
    }

    public function test_schema_exposes_problem_types_question_default_and_text_field(): void
    {
        $types = collect($this->getJson('/api/v1/master/health-record-types')->assertOk()->json('data'))->keyBy('type');
        $expected = ['vaccination' => ['What is it protecting against?', null, 'target_disease'], 'medication' => ['What is it for?', null, 'condition'], 'deworming' => ['What is it for?', 'worms_internal_parasites', 'parasite_target'],
            'treatment' => ['What are you treating?', null, 'condition'], 'disease_issue' => ["What's the problem?", null, 'condition'], 'vet_visit' => ['Reason for the visit?', null, 'condition']];
        foreach ($expected as $type => [$question, $default, $text]) {
            $this->assertSame($question, $types[$type]['question']);
            $this->assertSame($default, $types[$type]['default_problem_type']);
            $this->assertSame($text, $types[$type]['text_field']['key']);
            $this->assertSame('other', $types[$type]['text_field']['required_when_problem_type']);
            $this->assertSame(array_column(HealthProblemType::cases(), 'value'), array_column($types[$type]['problem_types'], 'code'));
            $this->assertArrayHasKey('problem_type', $types[$type]['fields']);
        }
        $this->assertCount(13, $types['vaccination']['problem_types']);
        $this->assertSame('Other', collect($types['vet_visit']['problem_types'])->firstWhere('code', 'other')['label']);
    }
}
