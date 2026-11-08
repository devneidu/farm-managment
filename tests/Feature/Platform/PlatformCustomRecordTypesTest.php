<?php

namespace Tests\Feature\Platform;

use App\Enums\PlatformRole;
use App\Models\CustomRecordType;
use App\Models\OperationType;
use App\Models\PlatformAdmin;
use App\Models\Species;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Feature\Team\TeamTestCase;

/** Platform administration of farm-made record types: the overview (counts, never records), make-public (a copy) and archiving a public type. */
class PlatformCustomRecordTypesTest extends TeamTestCase
{
    private const BASE = '/api/v1/platform-admin/custom-record-types';

    private const FARM = '/api/v1/custom-record-types';

    private User $admin;

    private array $type;

    private string $cycle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['name' => 'Pat Admin']);
        PlatformAdmin::create(['user_id' => $this->admin->id, 'role' => PlatformRole::Admin]);

        $this->signInAs($this->owner);
        $this->type = $this->postJson(self::FARM, ['name' => 'Egg check', 'description' => 'Daily', 'applies_to' => 'livestock', 'fields' => [
            ['label' => 'Crates', 'kind' => 'number', 'unit' => 'crates', 'required' => true],
            ['label' => 'Private remark', 'kind' => 'short_text', 'hidden' => true],
        ]])->assertCreated()->json('data');
        $this->cycle = $this->flock();
    }

    private function flock(): string
    {
        return $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => 'Flock', 'operation_type_id' => OperationType::where('code', 'poultry')->firstOrFail()->id,
            'species_id' => Species::where('code', 'chicken')->firstOrFail()->id, 'production_purpose' => 'breeding', 'initial_population' => 50, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
    }

    private function record(string $typeId, array $details, string $cycle)
    {
        return $this->postJson('/api/v1/records', ['production_cycle_id' => $cycle, 'type' => 'custom', 'custom_record_type_id' => $typeId, 'details' => $details,
            'recorded_at' => '2026-02-01T10:00:00Z', 'idempotency_key' => (string) Str::uuid()]);
    }

    private function asAdmin(PlatformRole $role = PlatformRole::Admin): void
    {
        if ($role !== PlatformRole::Admin) {
            $this->admin = User::factory()->create();
            PlatformAdmin::create(['user_id' => $this->admin->id, 'role' => $role]);
        }
        $this->signInAs($this->admin);
    }

    public function test_the_overview_lists_what_farms_made_with_counts_and_never_their_records(): void
    {
        $this->record($this->type['id'], ['crates' => '5'], $this->cycle)->assertCreated();
        $this->record($this->type['id'], ['crates' => '6'], $this->cycle)->assertCreated();

        $this->asAdmin(PlatformRole::Support);   // reads need any platform role
        $res = $this->getJson(self::BASE)->assertOk();
        $row = $res->json('data.0');
        $this->assertSame($this->type['id'], $row['id']);
        $this->assertSame('Green Acres', $row['farm']['name']);
        $this->assertSame(2, $row['records_count']);
        $this->assertSame(1, $row['farms_using']);
        $this->assertSame(['crates', 'private_remark'], array_column($row['fields'], 'key'));
        $this->assertNull($row['public_copy_id']);
        $this->assertStringNotContainsString('"details"', $res->getContent(), 'the records themselves are never exposed');
        $this->assertStringNotContainsString('5.', $res->getContent() ?: '');
        $this->getJson(self::BASE.'/'.$this->type['id'])->assertOk()->assertJsonPath('data.records_count', 2);
        $this->getJson(self::BASE.'?source=public')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::BASE.'?q=egg')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::BASE.'?q=zzz')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_farm_owner_has_no_access_to_platform_administration(): void
    {
        $this->getJson(self::BASE)->assertForbidden()->assertJsonPath('code', 'platform_admin_required');
        $this->postJson(self::BASE.'/'.$this->type['id'].'/make-public')->assertForbidden();
    }

    public function test_making_a_type_public_creates_a_copy_and_leaves_the_farms_original_private(): void
    {
        $this->asAdmin();
        $public = $this->postJson(self::BASE.'/'.$this->type['id'].'/make-public')->assertCreated()->json('data');

        $this->assertSame('public', $public['source']);
        $this->assertNull($public['farm']);
        $this->assertSame($this->type['id'], $public['copied_from_id']);
        $this->assertSame(1, $public['version']);
        $this->assertSame(['crates'], array_column($public['fields'], 'key'), 'hidden fields are not published');
        $this->assertNotSame($this->type['id'], $public['id']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.custom_record_type_made_public', 'resource_id' => $public['id'], 'farm_id' => null]);

        $this->getJson(self::BASE.'/'.$this->type['id'])->assertOk()->assertJsonPath('data.public_copy_id', $public['id']);
        $this->getJson(self::BASE.'?source=public')->assertOk()->assertJsonPath('data.0.id', $public['id'])->assertJsonPath('data.0.copies_count', 0);

        // The original is still the farm's own, private and editable.
        $original = CustomRecordType::findOrFail($this->type['id']);
        $this->assertSame($this->farm->id, $original->farm_id);
        $this->assertSame('farm', $original->source);
        $this->assertCount(2, $original->fields);

        // Every farm sees the public one; other farms' private ones stay invisible.
        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $list = $this->getJson(self::FARM)->assertOk()->json('data');
        $this->assertSame([$public['id']], array_column($list, 'id'));
        $this->assertSame('public', $list[0]['source']);
        $theirs = $this->flock();
        $this->record($public['id'], ['crates' => '2'], $theirs)->assertCreated();
        $copy = $this->postJson(self::FARM.'/'.$public['id'].'/copy')->assertCreated()->json('data');

        $this->asAdmin();
        $this->getJson(self::BASE.'?source=public')->assertJsonPath('data.0.copies_count', 1)->assertJsonPath('data.0.farms_using', 1)->assertJsonPath('data.0.records_count', 1);
        $this->getJson(self::BASE.'?source=farm')->assertJsonCount(2, 'data');   // the original and the other farm's copy
        $this->assertNotNull($copy['id']);
    }

    public function test_make_public_refuses_repeats_name_clashes_archived_and_all_hidden_types(): void
    {
        $this->asAdmin();
        $first = $this->postJson(self::BASE.'/'.$this->type['id'].'/make-public')->assertCreated()->json('data');
        $this->postJson(self::BASE.'/'.$this->type['id'].'/make-public')->assertStatus(409)->assertJsonPath('code', 'already_public')->assertJsonPath('details.existing_id', $first['id']);

        // another farm's type with the same name
        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $same = $this->postJson(self::FARM, ['name' => 'Egg check', 'applies_to' => 'livestock', 'fields' => [['label' => 'Eggs', 'kind' => 'whole_number']]])->assertCreated()->json('data');
        $hidden = $this->postJson(self::FARM, ['name' => 'Hidden only', 'applies_to' => 'livestock', 'fields' => [['label' => 'Eggs', 'kind' => 'whole_number', 'hidden' => true]]])->assertCreated()->json('data');
        $archived = $this->postJson(self::FARM, ['name' => 'Old one', 'applies_to' => 'livestock', 'fields' => [['label' => 'Eggs', 'kind' => 'whole_number']]])->assertCreated()->json('data');
        $this->postJson(self::FARM.'/'.$archived['id'].'/archive')->assertOk();

        $this->asAdmin();
        $this->postJson(self::BASE.'/'.$same['id'].'/make-public')->assertStatus(409)->assertJsonPath('code', 'duplicate_name');
        $this->postJson(self::BASE.'/'.$hidden['id'].'/make-public')->assertUnprocessable()->assertJsonValidationErrors('fields');
        $this->postJson(self::BASE.'/'.$archived['id'].'/make-public')->assertStatus(409)->assertJsonPath('code', 'type_archived');
        $this->postJson(self::BASE.'/'.$first['id'].'/make-public')->assertNotFound();   // a public type is not a farm's type
    }

    public function test_only_the_admin_role_writes(): void
    {
        $this->asAdmin(PlatformRole::Support);
        $this->postJson(self::BASE.'/'.$this->type['id'].'/make-public')->assertForbidden();
        $this->assertSame(0, CustomRecordType::whereNull('farm_id')->count());

        $public = CustomRecordType::create(['farm_id' => null, 'name' => 'Public one', 'applies_to' => 'both', 'source' => 'public', 'status' => 'active', 'version' => 1,
            'fields' => [['key' => 'note', 'label' => 'Note', 'kind' => 'short_text', 'required' => false, 'hidden' => false]]]);
        $this->postJson(self::BASE.'/'.$public->id.'/archive')->assertForbidden();
    }

    public function test_archiving_a_public_type_stops_new_records_everywhere_but_keeps_old_ones_and_copies(): void
    {
        $this->asAdmin();
        $public = $this->postJson(self::BASE.'/'.$this->type['id'].'/make-public')->assertCreated()->json('data');

        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $theirs = $this->flock();
        $old = $this->record($public['id'], ['crates' => '2'], $theirs)->assertCreated()->json('data');
        $copy = $this->postJson(self::FARM.'/'.$public['id'].'/copy', ['name' => 'Our egg check'])->assertCreated()->json('data');

        $this->asAdmin();
        $this->postJson(self::BASE.'/'.$this->type['id'].'/archive')->assertNotFound();   // farm-made types are archived by their farm
        $this->postJson(self::BASE.'/'.$public['id'].'/archive')->assertOk()->assertJsonPath('data.status', 'archived');
        $this->postJson(self::BASE.'/'.$public['id'].'/archive')->assertStatus(409)->assertJsonPath('code', 'type_already_archived');
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.custom_record_type_archived', 'resource_id' => $public['id']]);

        $this->signInAs($otherOwner);
        $this->record($public['id'], ['crates' => '3'], $theirs)->assertUnprocessable()->assertJsonValidationErrors('custom_record_type_id');
        $this->getJson('/api/v1/records/'.$old['id'])->assertOk()->assertJsonPath('data.type_name', 'Egg check');
        $this->assertSame([], $this->getJson(self::FARM.'?source=public')->json('data'), 'archived public types leave the default list');
        $this->record($copy['id'], ['crates' => '3'], $theirs)->assertCreated();   // the farm's own copy keeps working

        $this->asAdmin();
        $this->postJson(self::BASE.'/'.$public['id'].'/unarchive')->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson(self::BASE.'/'.$public['id'].'/unarchive')->assertStatus(409)->assertJsonPath('code', 'type_not_archived');
    }
}
