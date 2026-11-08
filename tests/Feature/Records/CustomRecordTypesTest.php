<?php

namespace Tests\Feature\Records;

use App\Enums\FarmRole;
use App\Models\CropType;
use App\Models\CustomRecordType;
use App\Models\InventoryMovement;
use App\Models\OperationalRecord;
use App\Models\OperationType;
use App\Models\PopulationMovement;
use App\Models\Species;
use Illuminate\Support\Str;
use Tests\Feature\Team\TeamTestCase;

/** Farm-made record types: building them, the edit rules once records exist, recording against a batch, visibility and the catalogues. */
class CustomRecordTypesTest extends TeamTestCase
{
    private const URL = '/api/v1/custom-record-types';

    private string $layers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAs($this->owner);
        $this->layers = $this->livestockCycle();
    }

    private function livestockCycle(): string
    {
        return $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => 'Layer flock', 'operation_type_id' => OperationType::where('code', 'poultry')->firstOrFail()->id,
            'species_id' => Species::where('code', 'chicken')->firstOrFail()->id, 'production_purpose' => 'breeding', 'initial_population' => 100, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
    }

    private function cropCycle(): string
    {
        return $this->postJson('/api/v1/production-cycles', ['kind' => 'crop', 'name' => 'Yam', 'operation_type_id' => OperationType::where('code', 'crops')->firstOrFail()->id,
            'crop_type_id' => CropType::where('code', 'yam')->firstOrFail()->id, 'initial_planting_units' => 800, 'planting_unit_type' => 'heap', 'planting_material_type' => 'tuber', 'planting_date' => '2026-01-01'])->assertCreated()->json('data.id');
    }

    /** @return array<string, mixed> */
    private function typeBody(array $over = []): array
    {
        return array_replace([
            'name' => 'Egg check', 'description' => 'Daily egg check', 'applies_to' => 'livestock',
            'fields' => [
                ['label' => 'Crates', 'kind' => 'number', 'unit' => 'crates', 'min' => '0', 'max' => '500', 'required' => true],
                ['label' => 'Cracked', 'kind' => 'whole_number'],
                ['label' => 'Shell colour', 'kind' => 'choose_one', 'options' => ['White', 'Brown']],
                ['label' => 'Checked on', 'kind' => 'date'],
                ['label' => 'Clean house', 'kind' => 'yes_no'],
                ['label' => 'Remark', 'kind' => 'short_text'],
                ['label' => 'Long note', 'kind' => 'long_text'],
            ],
        ], $over);
    }

    private function makeType(array $over = []): array
    {
        return $this->postJson(self::URL, $this->typeBody($over))->assertCreated()->json('data');
    }

    private function record(string $typeId, array $details, ?string $cycle = null, array $extra = [])
    {
        return $this->postJson('/api/v1/records', array_replace(['production_cycle_id' => $cycle ?? $this->layers, 'type' => 'custom', 'custom_record_type_id' => $typeId, 'details' => $details,
            'recorded_at' => '2026-02-01T10:00:00+01:00', 'idempotency_key' => (string) Str::uuid()], $extra));
    }

    /** Fields of an existing type, ready to send back (existing fields keep their key). */
    private function sendBack(array $type, callable $change): array
    {
        $fields = array_map(fn ($f) => array_intersect_key($f, array_flip(['key', 'label', 'kind', 'required', 'hidden', 'unit', 'min', 'max', 'options'])), $type['fields']);

        return ['fields' => $change($fields)];
    }

    // ------------------------------------------------------------------ create / list / show / archive

    public function test_a_type_is_made_with_keys_from_the_labels_and_listed_as_the_farms_own(): void
    {
        $type = $this->makeType();

        $this->assertSame('farm', $type['source']);
        $this->assertSame('active', $type['status']);
        $this->assertSame(1, $type['version']);
        $this->assertSame(['crates', 'cracked', 'shell_colour', 'checked_on', 'clean_house', 'remark', 'long_note'], array_column($type['fields'], 'key'));
        $this->assertSame('crates', $type['fields'][0]['unit']);
        $this->assertSame('0', $type['fields'][0]['min']);
        $this->assertSame(['White', 'Brown'], $type['fields'][2]['options']);
        $this->assertFalse($type['in_use']);
        $this->assertTrue($type['can_edit']);

        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.0.id', $type['id'])->assertJsonPath('data.0.source', 'farm');
        $this->getJson(self::URL.'/'.$type['id'])->assertOk()->assertJsonPath('data.name', 'Egg check');
        $this->assertDatabaseHas('audit_logs', ['farm_id' => $this->farm->id, 'action' => 'custom_record_type.created', 'resource_id' => $type['id']]);
    }

    public function test_two_labels_that_make_the_same_key_get_unique_keys(): void
    {
        $type = $this->makeType(['fields' => [['label' => 'Count!', 'kind' => 'whole_number'], ['label' => 'Count?', 'kind' => 'whole_number'], ['label' => '2nd check', 'kind' => 'yes_no']]]);

        $this->assertSame(['count', 'count_2', 'f_2nd_check'], array_column($type['fields'], 'key'));
    }

    public function test_names_are_unique_per_farm_and_never_a_built_in_record_type(): void
    {
        $this->makeType();
        $this->postJson(self::URL, $this->typeBody(['name' => '  egg   CHECK ']))->assertStatus(409)->assertJsonPath('code', 'duplicate_name');

        foreach (['Mortality', 'feed_use', 'Feed usage', 'pest observation', 'Eggs', 'custom'] as $reserved) {
            $this->postJson(self::URL, $this->typeBody(['name' => $reserved]))->assertUnprocessable()->assertJsonValidationErrors('name');
        }
        // Another farm may use the same name.
        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $this->postJson(self::URL, $this->typeBody())->assertCreated();
    }

    public function test_the_field_list_is_bounded_and_checked(): void
    {
        $many = array_map(fn ($i) => ['label' => 'Field '.$i, 'kind' => 'short_text'], range(1, 16));
        $this->postJson(self::URL, $this->typeBody(['fields' => $many]))->assertUnprocessable()->assertJsonValidationErrors('fields');
        $this->postJson(self::URL, $this->typeBody(['fields' => array_slice($many, 0, 15)]))->assertCreated();

        $bad = [
            'duplicate label' => [['label' => 'A', 'kind' => 'short_text'], ['label' => 'a', 'kind' => 'date']],
            'unknown kind' => [['label' => 'A', 'kind' => 'formula']],
            'min above max' => [['label' => 'A', 'kind' => 'number', 'min' => '10', 'max' => '1']],
            'choose one without choices' => [['label' => 'A', 'kind' => 'choose_one']],
            'repeated choice' => [['label' => 'A', 'kind' => 'choose_one', 'options' => ['x', 'X']]],
            'choices on a number' => [['label' => 'A', 'kind' => 'number', 'options' => ['x']]],
            'unit on text' => [['label' => 'A', 'kind' => 'short_text', 'unit' => 'kg']],
            'client rules are not a thing' => [['label' => 'A', 'kind' => 'short_text', 'rules' => 'required|exists:users,id']],
            'unknown key' => [['label' => 'A', 'kind' => 'short_text', 'key' => 'ghost']],
        ];
        foreach ($bad as $why => $fields) {
            $this->postJson(self::URL, $this->typeBody(['name' => 'Bad '.$why, 'fields' => $fields]))->assertUnprocessable();
        }
        // The farm id, source and version can never be set by the client.
        $this->postJson(self::URL, $this->typeBody(['name' => 'Sneaky', 'farm_id' => (string) Str::uuid(), 'source' => 'public', 'version' => 9]))->assertUnprocessable();
        // A species only goes with livestock, a crop only with crop.
        $this->postJson(self::URL, $this->typeBody(['name' => 'Wrong', 'applies_to' => 'crop', 'species_id' => Species::where('code', 'chicken')->value('id')]))->assertUnprocessable()->assertJsonValidationErrors('species_id');
    }

    public function test_only_owner_and_manager_make_types_everyone_with_master_data_view_reads(): void
    {
        $type = $this->makeType();
        $manager = $this->member(FarmRole::Manager);
        $this->signInAs($manager);
        $this->postJson(self::URL, $this->typeBody(['name' => 'Manager type']))->assertCreated();
        $this->patchJson(self::URL.'/'.$type['id'], ['description' => 'Edited'])->assertOk();

        foreach ([FarmRole::FarmWorker, FarmRole::Vet, FarmRole::Finance] as $role) {
            $this->signInAs($this->member($role));
            $this->getJson(self::URL)->assertOk();
            $this->postJson(self::URL, $this->typeBody(['name' => 'Nope '.$role->value]))->assertForbidden();
            $this->patchJson(self::URL.'/'.$type['id'], ['description' => 'x'])->assertForbidden();
            $this->postJson(self::URL.'/'.$type['id'].'/archive')->assertForbidden();
        }
    }

    public function test_archive_and_unarchive_hide_the_type_from_default_lists_but_keep_it(): void
    {
        $type = $this->makeType();
        $this->postJson(self::URL.'/'.$type['id'].'/archive')->assertOk()->assertJsonPath('data.status', 'archived');
        $this->postJson(self::URL.'/'.$type['id'].'/archive')->assertStatus(409)->assertJsonPath('code', 'type_already_archived');
        $this->assertSame([], $this->getJson(self::URL)->json('data'));
        $this->assertCount(1, $this->getJson(self::URL.'?include_archived=true')->json('data'));
        $this->postJson(self::URL.'/'.$type['id'].'/unarchive')->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson(self::URL.'/'.$type['id'].'/unarchive')->assertStatus(409)->assertJsonPath('code', 'type_not_archived');
    }

    // ------------------------------------------------------------------ editing

    public function test_before_any_record_a_type_can_be_edited_freely_and_the_version_goes_up(): void
    {
        $type = $this->makeType();
        $edited = $this->patchJson(self::URL.'/'.$type['id'], $this->sendBack($type, fn ($f) => [$f[0], ['label' => 'Cracked eggs', 'kind' => 'short_text', 'key' => 'cracked']]))->assertOk()->json('data');

        $this->assertSame(2, $edited['version']);
        $this->assertSame(['crates', 'cracked'], array_column($edited['fields'], 'key'));
        $this->assertSame('short_text', $edited['fields'][1]['kind']);

        // A name or description change is not a schema change.
        $same = $this->patchJson(self::URL.'/'.$type['id'], ['name' => 'Egg check v2'])->assertOk()->json('data');
        $this->assertSame(2, $same['version']);
        $this->assertSame('Egg check v2', $same['name']);
    }

    public function test_once_records_exist_the_safe_edits_are_allowed_and_each_bumps_the_version(): void
    {
        $type = $this->makeType();
        $this->record($type['id'], ['crates' => 3])->assertCreated();
        $in = $this->getJson(self::URL.'/'.$type['id'])->assertOk()->json('data');
        $this->assertTrue($in['in_use']);
        $this->assertSame(1, $in['records_count']);
        $this->assertTrue($in['fields'][0]['locked']);

        $change = function (array $current, callable $edit) use ($type) {
            return $this->patchJson(self::URL.'/'.$type['id'], $this->sendBack($current, $edit));
        };
        // add a field, rename a label, add a choice, required -> optional, hide, widen the limits, reorder - all at once
        $edited = $change($in, function ($f) {
            $f[0]['label'] = 'Egg crates';
            $f[0]['required'] = false;
            $f[0]['max'] = '900';
            $f[2]['options'][] = 'Cream';
            $f[3]['hidden'] = true;
            $f[] = ['label' => 'Collector', 'kind' => 'short_text', 'required' => true];

            return array_reverse($f);
        })->assertOk()->json('data');

        $this->assertSame(2, $edited['version']);
        $this->assertSame('collector', $edited['fields'][0]['key']);
        $byKey = array_column($edited['fields'], null, 'key');
        $this->assertSame('Egg crates', $byKey['crates']['label']);
        $this->assertFalse($byKey['crates']['required']);
        $this->assertSame('900', $byKey['crates']['max']);
        $this->assertSame(['White', 'Brown', 'Cream'], $byKey['shell_colour']['options']);
        $this->assertTrue($byKey['checked_on']['hidden']);
    }

    public function test_once_records_exist_edits_that_change_what_they_mean_are_refused_with_a_clear_message(): void
    {
        $type = $this->makeType();
        $this->record($type['id'], ['crates' => 3, 'shell_colour' => 'Brown'])->assertCreated();
        $current = $this->getJson(self::URL.'/'.$type['id'])->json('data');

        $refused = [
            'delete a field' => [fn ($f) => array_slice($f, 1), 'delete', 'cannot be deleted'],
            'change a kind' => [function ($f) {
                $f[1]['kind'] = 'short_text';

                return $f;
            }, 'kind', 'type cannot change'],
            'remove a choice' => [function ($f) {
                $f[2]['options'] = ['White'];

                return $f;
            }, 'option', 'cannot be removed'],
            'optional to required' => [function ($f) {
                $f[1]['required'] = true;

                return $f;
            }, 'required', 'cannot become required'],
            'change a unit' => [function ($f) {
                $f[0]['unit'] = 'trays';

                return $f;
            }, 'unit', 'unit'],
            'narrow the limits' => [function ($f) {
                $f[0]['max'] = '100';

                return $f;
            }, 'limits', 'widened'],
        ];
        foreach ($refused as $why => [$edit, $rule, $text]) {
            $res = $this->patchJson(self::URL.'/'.$type['id'], $this->sendBack($current, $edit));
            $res->assertStatus(409)->assertJsonPath('code', 'custom_type_in_use')->assertJsonPath('details.rule', $rule);
            $this->assertStringContainsString($text, $res->json('message'), $why);
        }
        $this->patchJson(self::URL.'/'.$type['id'], ['applies_to' => 'both'])->assertStatus(409)->assertJsonPath('details.rule', 'applies_to');

        $this->assertSame(1, $this->getJson(self::URL.'/'.$type['id'])->json('data.version'), 'a refused edit changes nothing');
    }

    public function test_records_keep_the_schema_version_they_were_captured_with(): void
    {
        $type = $this->makeType();
        $first = $this->record($type['id'], ['crates' => 3])->assertCreated()->json('data');
        $this->patchJson(self::URL.'/'.$type['id'], $this->sendBack($type, fn ($f) => [...$f, ['label' => 'Collector', 'kind' => 'short_text']]))->assertOk();
        $second = $this->record($type['id'], ['crates' => 4, 'collector' => 'Ada'])->assertCreated()->json('data');

        $this->assertSame(1, $first['custom_schema_version']);
        $this->assertSame(2, $second['custom_schema_version']);
        $this->assertSame(2, $this->getJson('/api/v1/records/'.$first['id'])->json('data.custom.type.version'), 'the type shows its current version, the record its own');
    }

    // ------------------------------------------------------------------ schema validation per kind

    public function test_each_field_kind_is_validated_by_its_own_safe_rules_and_stored_exactly(): void
    {
        $type = $this->makeType();
        $ok = ['crates' => '12.50', 'cracked' => 3, 'shell_colour' => 'Brown', 'checked_on' => '2026-02-01', 'clean_house' => true, 'remark' => '  all good ', 'long_note' => str_repeat('x', 2000)];
        $record = $this->record($type['id'], $ok)->assertCreated()->json('data');

        $this->assertSame(['crates' => '12.5', 'cracked' => 3, 'shell_colour' => 'Brown', 'checked_on' => '2026-02-01', 'clean_house' => true, 'remark' => 'all good', 'long_note' => str_repeat('x', 2000)], $record['details']);

        $invalid = [
            'crates' => [['missing', null], ['not a number', 'abc'], ['a boolean', true], ['an array', [1]], ['below min', '-1'], ['above max', '501'], ['too precise', '1.1234567']],
            'cracked' => [['decimal', 2.5], ['negative', -1], ['a boolean', true], ['text', 'x']],
            'shell_colour' => [['not an option', 'Green'], ['a number', 1]],
            'checked_on' => [['wrong format', '01/02/2026'], ['nonsense date', '2026-13-45']],
            'clean_house' => [['a string', 'yes'], ['a number', 1]],
            'remark' => [['too long', str_repeat('x', 201)], ['an array', ['x']]],
            'long_note' => [['too long', str_repeat('x', 2001)]],
        ];
        foreach ($invalid as $key => $cases) {
            foreach ($cases as [$why, $value]) {
                $details = ['crates' => '1'];
                $key === 'crates' && $why === 'missing' ? $details = [] : $details[$key] = $value;
                $this->record($type['id'], $details)->assertUnprocessable()->assertJsonValidationErrors('details.'.$key);
            }
        }
        // Only the type's fields are accepted.
        $this->record($type['id'], ['crates' => '1', 'surprise' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('details');
        // Optional fields may be left out or null.
        $this->record($type['id'], ['crates' => '1', 'remark' => null])->assertCreated()->assertJsonPath('data.details', ['crates' => '1']);
    }

    public function test_a_hidden_field_is_not_accepted_for_new_records_but_old_values_stay_readable(): void
    {
        $type = $this->makeType();
        $old = $this->record($type['id'], ['crates' => 2, 'remark' => 'before'])->assertCreated()->json('data');
        $this->patchJson(self::URL.'/'.$type['id'], $this->sendBack($type, function ($f) {
            $f[5]['hidden'] = true;
            $f[0]['hidden'] = false;

            return $f;
        }))->assertOk();

        $this->record($type['id'], ['crates' => 2, 'remark' => 'after'])->assertUnprocessable()->assertJsonValidationErrors('details');
        $this->record($type['id'], ['crates' => 2])->assertCreated();
        $labels = array_column($this->getJson('/api/v1/records/'.$old['id'])->json('data.custom.fields'), 'label');
        $this->assertSame(['Crates', 'Remark'], $labels, 'the hidden field still shows on the old record');
        $this->assertTrue($this->getJson('/api/v1/records/'.$old['id'])->json('data.custom.fields.1.hidden'));
    }

    // ------------------------------------------------------------------ recording against a batch

    public function test_a_type_only_records_against_batches_it_applies_to(): void
    {
        $crop = $this->cropCycle();
        $livestock = $this->makeType(['name' => 'Flock note', 'fields' => [['label' => 'Note', 'kind' => 'short_text']]]);
        $cropType = $this->makeType(['name' => 'Field walk', 'applies_to' => 'crop', 'fields' => [['label' => 'Note', 'kind' => 'short_text']]]);
        $both = $this->makeType(['name' => 'Visit', 'applies_to' => 'both', 'fields' => [['label' => 'Note', 'kind' => 'short_text']]]);
        $fish = $this->makeType(['name' => 'Pond note', 'species_id' => Species::where('code', 'fish')->value('id'), 'fields' => [['label' => 'Note', 'kind' => 'short_text']]]);
        $chicken = $this->makeType(['name' => 'Hen note', 'species_id' => Species::where('code', 'chicken')->value('id'), 'fields' => [['label' => 'Note', 'kind' => 'short_text']]]);
        $maize = $this->makeType(['name' => 'Maize walk', 'applies_to' => 'crop', 'crop_type_id' => CropType::where('code', 'maize')->value('id'), 'fields' => [['label' => 'Note', 'kind' => 'short_text']]]);
        $yam = $this->makeType(['name' => 'Yam walk', 'applies_to' => 'crop', 'crop_type_id' => CropType::where('code', 'yam')->value('id'), 'fields' => [['label' => 'Note', 'kind' => 'short_text']]]);

        $this->record($livestock['id'], ['note' => 'x'])->assertCreated();
        $this->record($cropType['id'], ['note' => 'x'], $crop)->assertCreated();
        $this->record($both['id'], ['note' => 'x'])->assertCreated();
        $this->record($both['id'], ['note' => 'x'], $crop)->assertCreated();
        $this->record($chicken['id'], ['note' => 'x'])->assertCreated();
        $this->record($yam['id'], ['note' => 'x'], $crop)->assertCreated();

        $this->record($livestock['id'], ['note' => 'x'], $crop)->assertUnprocessable()->assertJsonValidationErrors('custom_record_type_id');
        $this->record($cropType['id'], ['note' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('custom_record_type_id');
        $this->record($fish['id'], ['note' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('custom_record_type_id');
        $this->record($maize['id'], ['note' => 'x'], $crop)->assertUnprocessable()->assertJsonValidationErrors('custom_record_type_id');

        // The catalogue per batch agrees.
        $names = fn (string $cycle) => array_column(array_filter($this->getJson('/api/v1/production-cycles/'.$cycle)->json('data.available_record_types'), fn ($t) => $t['type'] === 'custom'), 'name');
        $this->assertEqualsCanonicalizing(['Flock note', 'Visit', 'Hen note'], $names($this->layers));
        $this->assertEqualsCanonicalizing(['Field walk', 'Visit', 'Yam walk'], $names($crop));
    }

    public function test_a_custom_record_has_no_measurement_population_stock_or_money_effect(): void
    {
        $type = $this->makeType();
        $populationBefore = PopulationMovement::where('production_cycle_id', $this->layers)->count();
        $record = $this->record($type['id'], ['crates' => 7], null, ['recorded_at' => '2026-02-01T10:00:00+01:00', 'notes' => 'Morning round'])->assertCreated()->json('data');

        $this->assertSame('custom', $record['type']);
        $this->assertSame('Egg check', $record['type_name']);
        $this->assertNull($record['measurement']);
        $this->assertSame(0, $record['population_delta']);
        $this->assertSame('2026-02-01T09:00:00.000000Z', $record['recorded_at'], 'the event time is kept apart from created_at');
        $this->assertNotSame($record['recorded_at'], $record['created_at']);
        $this->assertSame($populationBefore, PopulationMovement::where('production_cycle_id', $this->layers)->count());
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(100, $this->getJson('/api/v1/production-cycles/'.$this->layers)->json('data.livestock.current_population'));
        $stored = OperationalRecord::findOrFail($record['id']);
        $this->assertSame($type['id'], $stored->custom_record_type_id);
        $this->assertSame(1, $stored->custom_schema_version);
        $this->assertSame('custom', $stored->type, 'the 40-char type column never holds an id');
    }

    public function test_recording_is_idempotent_and_needs_record_create(): void
    {
        $type = $this->makeType();
        $key = (string) Str::uuid();
        $first = $this->record($type['id'], ['crates' => 1], null, ['idempotency_key' => $key])->assertCreated()->json('data.id');
        $this->record($type['id'], ['crates' => 1], null, ['idempotency_key' => $key])->assertCreated()->assertJsonPath('data.id', $first);
        $this->record($type['id'], ['crates' => 2], null, ['idempotency_key' => $key])->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame(1, OperationalRecord::where('type', 'custom')->count());

        $this->signInAs($this->member(FarmRole::Vet));   // record.view only
        $this->record($type['id'], ['crates' => 1])->assertForbidden();
        $this->signInAs($this->member(FarmRole::FarmWorker));
        $this->record($type['id'], ['crates' => 1])->assertCreated();
    }

    public function test_a_custom_record_is_reversed_like_any_other_and_corrected_with_the_same_type(): void
    {
        $type = $this->makeType();
        $other = $this->makeType(['name' => 'Other check']);
        $record = $this->record($type['id'], ['crates' => 1])->assertCreated()->json('data');
        $reversal = $this->postJson('/api/v1/records/'.$record['id'].'/reverse', ['reason' => 'Wrong count', 'recorded_at' => '2026-03-01T10:00:00Z', 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data');

        $this->assertSame('reversal', $reversal['type']);
        $this->assertSame($type['id'], $reversal['custom_record_type_id']);
        $this->assertSame($reversal['id'], $this->getJson('/api/v1/records/'.$record['id'])->json('data.reversed_by_record_id'));
        $this->postJson('/api/v1/records/'.$record['id'].'/reverse', ['reason' => 'Again', 'recorded_at' => '2026-03-02T10:00:00Z', 'idempotency_key' => (string) Str::uuid()])->assertStatus(409)->assertJsonPath('code', 'record_already_reversed');

        $this->record($other['id'], ['crates' => 1], null, ['corrects_record_id' => $record['id']])->assertStatus(409)->assertJsonPath('code', 'invalid_correction');
        $this->record($type['id'], ['crates' => 2], null, ['corrects_record_id' => $record['id']])->assertCreated()->assertJsonPath('data.corrects_record_id', $record['id']);
    }

    public function test_an_archived_type_takes_no_new_records_but_old_ones_stay_readable(): void
    {
        $type = $this->makeType();
        $old = $this->record($type['id'], ['crates' => 5])->assertCreated()->json('data');
        $this->postJson(self::URL.'/'.$type['id'].'/archive')->assertOk();

        $this->record($type['id'], ['crates' => 5])->assertUnprocessable()->assertJsonValidationErrors('custom_record_type_id');
        $read = $this->getJson('/api/v1/records/'.$old['id'])->assertOk()->json('data');
        $this->assertSame('Egg check', $read['type_name']);
        $this->assertSame('Crates', $read['custom']['fields'][0]['label']);
        $this->assertSame('archived', $read['custom']['type']['status']);
        $this->assertCount(1, $this->getJson('/api/v1/records?type=custom')->json('data'));

        // it also leaves every catalogue
        $this->assertSame([], array_values(array_filter($this->getJson('/api/v1/master/record-types')->json('data'), fn ($t) => $t['type'] === 'custom')));
        $this->assertSame([], array_filter($this->getJson('/api/v1/production-cycles/'.$this->layers)->json('data.available_record_types'), fn ($t) => $t['type'] === 'custom'));

        $this->postJson(self::URL.'/'.$type['id'].'/unarchive')->assertOk();
        $this->record($type['id'], ['crates' => 5])->assertCreated();
    }

    public function test_type_and_id_must_agree_and_built_in_types_are_unchanged(): void
    {
        $type = $this->makeType();
        $base = ['production_cycle_id' => $this->layers, 'recorded_at' => '2026-02-01T10:00:00+01:00'];
        $this->postJson('/api/v1/records', $base + ['type' => 'custom', 'details' => [], 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('custom_record_type_id');
        $this->postJson('/api/v1/records', $base + ['type' => 'mortality', 'custom_record_type_id' => $type['id'], 'details' => ['quantity' => 1, 'cause' => 'x'], 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('custom_record_type_id');
        $this->getJson('/api/v1/record-types/custom/schema')->assertNotFound();

        $mortality = $this->postJson('/api/v1/records', $base + ['type' => 'mortality', 'details' => ['quantity' => 4, 'cause' => 'Heat'], 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->assertSame(-4, $mortality['population_delta']);
        $this->assertSame('Mortality', $mortality['type_name']);
        $this->assertNull($mortality['custom_record_type_id']);
        $this->assertArrayNotHasKey('custom', $mortality);
        $this->assertSame(96, $this->getJson('/api/v1/production-cycles/'.$this->layers)->json('data.livestock.current_population'));
    }

    // ------------------------------------------------------------------ visibility, public types, copy

    public function test_another_farms_private_type_is_invisible_and_cannot_be_used(): void
    {
        $mine = $this->makeType();
        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);

        $this->assertSame([], $this->getJson(self::URL.'?include_archived=true')->json('data'));
        $this->getJson(self::URL.'/'.$mine['id'])->assertNotFound();
        $this->patchJson(self::URL.'/'.$mine['id'], ['name' => 'Stolen'])->assertNotFound();
        $this->postJson(self::URL.'/'.$mine['id'].'/archive')->assertNotFound();
        $this->postJson(self::URL.'/'.$mine['id'].'/copy')->assertNotFound();

        $theirs = $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => 'Theirs', 'operation_type_id' => OperationType::where('code', 'poultry')->firstOrFail()->id,
            'species_id' => Species::where('code', 'chicken')->firstOrFail()->id, 'production_purpose' => 'breeding', 'initial_population' => 10, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
        $this->record($mine['id'], ['crates' => 1], $theirs)->assertUnprocessable()->assertJsonValidationErrors('custom_record_type_id');
        $this->assertSame([], array_filter($this->getJson('/api/v1/master/record-types')->json('data'), fn ($t) => $t['type'] === 'custom'));
        // and my own cycle id from their session is a plain 404
        $this->record($mine['id'], ['crates' => 1], $this->layers)->assertNotFound();
    }

    public function test_public_types_are_visible_usable_but_not_editable_and_can_be_copied(): void
    {
        $public = CustomRecordType::create(['farm_id' => null, 'name' => 'Biosecurity visit', 'applies_to' => 'livestock', 'source' => 'public', 'status' => 'active', 'version' => 1,
            'fields' => [['key' => 'visitor', 'label' => 'Visitor', 'kind' => 'short_text', 'required' => true, 'hidden' => false], ['key' => 'boots', 'label' => 'Boots washed', 'kind' => 'yes_no', 'required' => false, 'hidden' => false]]]);

        $list = $this->getJson(self::URL)->assertOk()->json('data');
        $this->assertSame(['public'], array_column($list, 'source'));
        $this->assertFalse($list[0]['can_edit']);
        $this->getJson(self::URL.'?source=farm')->assertJsonCount(0, 'data');

        $this->patchJson(self::URL.'/'.$public->id, ['name' => 'Mine now'])->assertNotFound();
        $this->postJson(self::URL.'/'.$public->id.'/archive')->assertNotFound();
        $this->record($public->id, ['visitor' => 'Vet', 'boots' => true])->assertCreated()->assertJsonPath('data.type_name', 'Biosecurity visit');

        // Copy: private, editable, version 1, and the public original leaves this farm's forms while the copy is active.
        $copy = $this->postJson(self::URL.'/'.$public->id.'/copy')->assertCreated()->json('data');
        $this->assertSame('farm', $copy['source']);
        $this->assertSame($public->id, $copy['copied_from_id']);
        $this->assertSame('Biosecurity visit', $copy['name']);
        $this->assertSame(1, $copy['version']);
        $this->assertSame([$copy['id']], array_column(array_filter($this->getJson('/api/v1/master/record-types')->json('data'), fn ($t) => $t['type'] === 'custom'), 'custom_record_type_id'));
        $this->patchJson(self::URL.'/'.$copy['id'], ['name' => 'Our visit'])->assertOk()->assertJsonPath('data.name', 'Our visit');
        $this->postJson(self::URL.'/'.$public->id.'/copy', ['name' => 'Our visit'])->assertStatus(409)->assertJsonPath('code', 'duplicate_name');
        $this->postJson(self::URL.'/'.$copy['id'].'/copy')->assertNotFound();   // only public types can be copied
        $public->refresh();
        $this->assertSame('Biosecurity visit', $public->name, 'the public type is untouched');

        // an archived public type cannot be copied or used
        $public->update(['status' => 'archived']);
        $this->postJson(self::URL.'/'.$public->id.'/copy', ['name' => 'Another'])->assertNotFound();
        $this->record($public->id, ['visitor' => 'Vet'])->assertUnprocessable();
    }

    // ------------------------------------------------------------------ catalogues, listing, quick record

    public function test_the_catalogues_include_custom_types_with_their_field_schema(): void
    {
        $type = $this->makeType(['fields' => [
            ['label' => 'Crates', 'kind' => 'number', 'unit' => 'crates', 'required' => true],
            ['label' => 'Secret', 'kind' => 'short_text', 'hidden' => true],
        ]]);

        $schemas = collect($this->getJson('/api/v1/master/record-types')->assertOk()->json('data'));
        $this->assertTrue($schemas->contains('type', 'mortality'), 'built-in types are still there');
        $entry = $schemas->firstWhere('type', 'custom');
        $this->assertSame($type['id'], $entry['custom_record_type_id']);
        $this->assertSame('Egg check', $entry['name']);
        $this->assertSame(['crates'], array_column($entry['custom_fields'], 'key'), 'the form shows visible fields only');
        $this->assertNull($entry['measurement']);
        $this->assertSame('none', $entry['population_effect']);
        $this->assertSame('record.create', $entry['permission']);
        $this->assertFalse($entry['inventory_effect_enabled']);

        $ops = collect($this->getJson('/api/v1/master/record-operations')->assertOk()->json('data'));
        $op = $ops->firstWhere('code', 'custom:'.$type['id']);
        $this->assertSame('normal', $op['display_mode']);
        $this->assertSame('Egg check', $op['label']);
        $this->assertSame('livestock', $op['cycle_kind']);
        $this->assertNotNull($ops->firstWhere('code', 'feed_use'), 'built-in operations are unchanged');

        $detail = $this->getJson('/api/v1/master/record-operations/custom:'.$type['id'])->assertOk()->json('data');
        $record = $detail['actions']['record'][0];
        $this->assertSame(['type' => 'custom', 'custom_record_type_id' => $type['id']], $record['submission']['body_defaults']);
        $this->assertContains('details.crates', $record['fields']['required']);
        $this->assertNotContains('details.secret', [...$record['fields']['required'], ...$record['fields']['optional']]);
        $this->assertNull($record['inventory_effect']);

        $this->getJson('/api/v1/master/record-operations/custom:'.Str::uuid())->assertNotFound();
        $this->signInAs($this->member(FarmRole::FarmWorker));
        $this->assertTrue(collect($this->getJson('/api/v1/master/record-types')->json('data'))->contains('type', 'custom'), 'anyone who can record sees the types they can record with');
    }

    public function test_quick_record_offers_the_farms_own_types_first(): void
    {
        $type = $this->makeType(['name' => 'Egg check']);
        $quick = $this->getJson('/api/v1/dashboard')->assertOk()->json('data.quick_record');

        $this->assertSame('custom', $quick[0]['type']);
        $this->assertSame($type['id'], $quick[0]['custom_record_type_id']);
        $this->assertSame('Egg check', $quick[0]['label']);
        $this->assertSame(1, $quick[0]['applies_to_cycles']);
        $this->assertLessThanOrEqual(6, count($quick));

        $this->makeType(['name' => 'Crop only', 'applies_to' => 'crop']);   // no crop project: not offered
        $this->assertCount(1, array_filter($this->getJson('/api/v1/dashboard')->json('data.quick_record'), fn ($q) => $q['type'] === 'custom'));
    }

    public function test_records_list_filters_by_custom_type_and_returns_labelled_values(): void
    {
        $a = $this->makeType();
        $b = $this->makeType(['name' => 'Water check', 'fields' => [['label' => 'Litres', 'kind' => 'number', 'unit' => 'l']]]);
        $this->record($a['id'], ['crates' => 1])->assertCreated();
        $this->record($b['id'], ['litres' => '20.5'])->assertCreated();
        $this->postJson('/api/v1/records', ['production_cycle_id' => $this->layers, 'type' => 'mortality', 'details' => ['quantity' => 1, 'cause' => 'x'], 'recorded_at' => '2026-02-01T10:00:00+01:00', 'idempotency_key' => (string) Str::uuid()])->assertCreated();

        $this->assertCount(2, $this->getJson('/api/v1/records?type=custom')->json('data'));
        $only = $this->getJson('/api/v1/records?type=custom&custom_record_type_id='.$b['id'])->assertOk()->json('data');
        $this->assertCount(1, $only);
        $this->assertSame([['key' => 'litres', 'label' => 'Litres', 'kind' => 'number', 'unit' => 'l', 'hidden' => false, 'value' => '20.5']], $only[0]['custom']['fields']);
        $this->getJson('/api/v1/records?custom_record_type_id=not-a-uuid')->assertUnprocessable();

        // A renamed label shows on old records.
        $this->patchJson(self::URL.'/'.$b['id'], ['fields' => [['key' => 'litres', 'label' => 'Water used', 'kind' => 'number', 'unit' => 'l']]])->assertOk();
        $this->assertSame('Water used', $this->getJson('/api/v1/records?custom_record_type_id='.$b['id'])->json('data.0.custom.fields.0.label'));
    }

    public function test_a_closed_cycle_takes_no_custom_record(): void
    {
        $type = $this->makeType();
        $this->postJson('/api/v1/production-cycles/'.$this->layers.'/close', ['end_date' => '2026-03-01', 'reason' => 'Finished'])->assertOk();
        $this->record($type['id'], ['crates' => 1])->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
    }
}
