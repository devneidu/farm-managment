<?php

namespace Tests\Feature\MasterData;

use App\Enums\FarmRole;
use App\Enums\Permission;
use App\Events\MasterData\CustomMasterDataChanged;
use App\Models\Breed;
use App\Models\CropVariety;
use App\Models\FarmMembership;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;

class CustomMasterDataTest extends MasterDataTestCase
{
    public function test_manager_and_owner_can_create_a_custom_breed_owned_by_their_farm(): void
    {
        foreach ([$this->owner, $this->member(FarmRole::Manager)] as $i => $user) {
            $response = $this->signInAs($user)->postJson('/api/v1/custom-breeds', [
                'species_id' => $this->species('chicken')->id, 'name' => "  Kuroiler {$i}  ",
            ])->assertCreated()
                ->assertJsonPath('data.source', 'farm')
                ->assertJsonPath('data.name', "Kuroiler {$i}")
                ->assertJsonPath('data.is_editable', true)
                ->assertJsonPath('data.code', null);

            $breed = Breed::findOrFail($response->json('data.id'));
            $this->assertSame($this->farm->id, $breed->farm_id);
        }
    }

    public function test_request_cannot_choose_the_farm_or_a_system_code(): void
    {
        [, $other] = $this->otherFarm();

        $this->signInAs($this->owner)->postJson('/api/v1/custom-breeds', [
            'species_id' => $this->species('chicken')->id, 'name' => 'Sneaky', 'farm_id' => $other->id,
        ])->assertStatus(422)->assertJsonValidationErrors('farm_id');

        $this->postJson('/api/v1/custom-breeds', [
            'species_id' => $this->species('chicken')->id, 'name' => 'Sneaky', 'code' => 'sneaky',
        ])->assertStatus(422)->assertJsonValidationErrors('code');

        $this->assertSame(0, Breed::count());
    }

    public function test_species_must_exist_be_active_and_name_valid(): void
    {
        $this->signInAs($this->owner);

        // a crop id is not a species
        $this->postJson('/api/v1/custom-breeds', ['species_id' => $this->crop('maize')->id, 'name' => 'Nope'])->assertStatus(422)->assertJsonValidationErrors('species_id');
        $this->postJson('/api/v1/custom-breeds', ['species_id' => fake()->uuid(), 'name' => 'Nope'])->assertStatus(422);
        $this->postJson('/api/v1/custom-breeds', ['name' => 'Nope'])->assertStatus(422);

        foreach (['', 'a', '<script>', str_repeat('x', 101)] as $bad) {
            $this->postJson('/api/v1/custom-breeds', ['species_id' => $this->species('chicken')->id, 'name' => $bad])->assertStatus(422)->assertJsonValidationErrors('name');
        }

        $this->species('rabbit')->update(['is_active' => false]);
        $this->postJson('/api/v1/custom-breeds', ['species_id' => $this->species('rabbit')->id, 'name' => 'Angora'])->assertStatus(422);
    }

    public function test_duplicate_names_are_rejected_within_scope_but_allowed_across_farms(): void
    {
        $system = $this->systemBreed('chicken', 'Broiler');
        $chicken = $this->species('chicken')->id;

        $this->signInAs($this->owner);
        $this->postJson('/api/v1/custom-breeds', ['species_id' => $chicken, 'name' => 'Layer Cross'])->assertCreated();

        // same name (case/space-insensitive) as own custom
        $this->postJson('/api/v1/custom-breeds', ['species_id' => $chicken, 'name' => 'layer   CROSS'])
            ->assertStatus(409)->assertJsonPath('code', 'duplicate_name')->assertJsonPath('details.source', 'farm');

        // same name as a system breed
        $this->postJson('/api/v1/custom-breeds', ['species_id' => $chicken, 'name' => 'BROILER'])
            ->assertStatus(409)->assertJsonPath('details.source', 'system')->assertJsonPath('details.existing_id', $system->id);

        // same name for a different species is fine
        $this->postJson('/api/v1/custom-breeds', ['species_id' => $this->species('cattle')->id, 'name' => 'Layer Cross'])->assertCreated();

        // another farm may use the same name
        [$ownerB] = $this->otherFarm();
        $this->signInAs($ownerB)->postJson('/api/v1/custom-breeds', ['species_id' => $chicken, 'name' => 'Layer Cross'])->assertCreated();
    }

    public function test_inactive_duplicate_points_to_reactivation(): void
    {
        $this->customBreed($this->farm, 'goat', 'Kalahari', active: false);

        $this->signInAs($this->owner)->postJson('/api/v1/custom-breeds', ['species_id' => $this->species('goat')->id, 'name' => 'Kalahari'])
            ->assertStatus(409)->assertJsonPath('details.is_active', false);
    }

    public function test_rename_deactivate_and_reactivate_dispatch_audit_events(): void
    {
        $breed = $this->customBreed($this->farm, 'pig', 'Duroc X');
        $this->signInAs($this->owner);
        Event::fake([CustomMasterDataChanged::class]);

        $this->patchJson("/api/v1/custom-breeds/{$breed->id}", ['name' => 'Duroc Cross'])->assertOk()->assertJsonPath('data.name', 'Duroc Cross');
        $this->patchJson("/api/v1/custom-breeds/{$breed->id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->patchJson("/api/v1/custom-breeds/{$breed->id}", ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
        $this->patchJson("/api/v1/custom-breeds/{$breed->id}", ['is_active' => true])->assertOk(); // no-op: no event

        Event::assertDispatchedTimes(CustomMasterDataChanged::class, 3);
        Event::assertDispatched(CustomMasterDataChanged::class, fn ($e) => $e->action === 'deactivated' && $e->actor->is($this->owner) && $e->record->is($breed));
        $this->assertDatabaseHas('breeds', ['id' => $breed->id, 'name' => 'Duroc Cross']);
    }

    public function test_creation_dispatches_created_event(): void
    {
        Event::fake([CustomMasterDataChanged::class]);

        $this->signInAs($this->owner)->postJson('/api/v1/custom-breeds', ['species_id' => $this->species('sheep')->id, 'name' => 'West African Dwarf'])->assertCreated();

        Event::assertDispatched(CustomMasterDataChanged::class, fn ($e) => $e->action === 'created');
    }

    public function test_deactivated_breed_leaves_selectors_but_row_is_kept(): void
    {
        $breed = $this->customBreed($this->farm, 'rabbit', 'New Zealand White');
        $rabbit = $this->species('rabbit')->id;

        $this->signInAs($this->owner)->patchJson("/api/v1/custom-breeds/{$breed->id}", ['is_active' => false])->assertOk();

        $this->getJson("/api/v1/master/species/{$rabbit}/breeds")->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/custom-breeds')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/custom-breeds?include_inactive=true')->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('breeds', ['id' => $breed->id, 'is_active' => false]);
    }

    public function test_system_records_cannot_be_modified_by_farm_users(): void
    {
        $system = $this->systemBreed('chicken', 'Broiler');
        $this->signInAs($this->owner);

        $this->patchJson("/api/v1/custom-breeds/{$system->id}", ['name' => 'Hacked'])->assertNotFound();
        $this->patchJson("/api/v1/custom-breeds/{$system->id}", ['is_active' => false])->assertNotFound();
        $this->assertDatabaseHas('breeds', ['id' => $system->id, 'name' => 'Broiler', 'is_active' => true]);

        // system rows never show up in the farm's custom list
        $this->getJson('/api/v1/custom-breeds')->assertJsonCount(0, 'data');
        // and there is no delete route at all
        $this->deleteJson("/api/v1/custom-breeds/{$system->id}")->assertStatus(405);
    }

    public function test_records_cannot_be_converted_between_system_and_custom_or_moved(): void
    {
        $breed = $this->customBreed($this->farm, 'chicken', 'Mine');
        [, $other] = $this->otherFarm();
        $this->signInAs($this->owner);

        foreach ([['farm_id' => null, 'name' => 'Mine2'], ['farm_id' => $other->id], ['species_id' => $this->species('cattle')->id], ['code' => 'mine']] as $payload) {
            $this->patchJson("/api/v1/custom-breeds/{$breed->id}", $payload)->assertStatus(422);
        }

        $fresh = $breed->fresh();
        $this->assertSame($this->farm->id, $fresh->farm_id);
        $this->assertSame($this->species('chicken')->id, $fresh->species_id);
        $this->assertNull($fresh->code);
    }

    public function test_farm_a_cannot_update_farm_b_records_even_with_the_uuid(): void
    {
        [$ownerB, $farmB] = $this->otherFarm();
        $theirs = $this->customBreed($farmB, 'chicken', 'Theirs');
        $theirVariety = $this->customVariety($farmB, 'yam', 'Their Yam');

        $this->signInAs($this->owner);
        $this->patchJson("/api/v1/custom-breeds/{$theirs->id}", ['name' => 'Stolen'])->assertNotFound();
        $this->patchJson("/api/v1/custom-breeds/{$theirs->id}", ['is_active' => false])->assertNotFound();
        $this->patchJson("/api/v1/custom-varieties/{$theirVariety->id}", ['is_active' => false])->assertNotFound();
        $this->getJson('/api/v1/custom-breeds')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/custom-varieties')->assertJsonCount(0, 'data');

        $this->assertSame('Theirs', $theirs->fresh()->name);
        $this->assertTrue($theirVariety->fresh()->is_active);

        // the true owner can
        $this->signInAs($ownerB)->patchJson("/api/v1/custom-breeds/{$theirs->id}", ['name' => 'Ours'])->assertOk();
    }

    public function test_multi_farm_user_acts_only_on_the_selected_farm(): void
    {
        [$ownerB, $farmB] = $this->otherFarm();
        $this->member(FarmRole::Manager, $farmB);
        $breedB = $this->customBreed($farmB, 'goat', 'Farm B Only');

        // owner of farm A is only a FarmWorker on farm B: manage denied there, and A's own context can't see B's row
        $user = $this->member(FarmRole::FarmWorker, $farmB, 'Dual');
        FarmMembership::create(['farm_id' => $this->farm->id, 'user_id' => $user->id, 'role' => FarmRole::Manager->value]);

        $this->signInAs($user)->withHeader('X-Farm-Id', $this->farm->id);
        $this->patchJson("/api/v1/custom-breeds/{$breedB->id}", ['name' => 'Xx'])->assertNotFound();

        $this->withHeader('X-Farm-Id', $farmB->id);
        $this->patchJson("/api/v1/custom-breeds/{$breedB->id}", ['name' => 'Xx'])->assertForbidden();
    }

    public function test_worker_and_finance_cannot_manage_but_can_read_custom_items(): void
    {
        $breed = $this->customBreed($this->farm, 'chicken', 'Existing');
        $payload = ['species_id' => $this->species('chicken')->id, 'name' => 'Attempt'];

        foreach ([FarmRole::FarmWorker, FarmRole::Finance] as $role) {
            $this->signInAs($this->member($role));
            $this->postJson('/api/v1/custom-breeds', $payload)->assertForbidden()->assertJsonPath('code', 'forbidden');
            $this->patchJson("/api/v1/custom-breeds/{$breed->id}", ['is_active' => false])->assertForbidden();
            $this->postJson('/api/v1/custom-varieties', ['crop_type_id' => $this->crop('yam')->id, 'name' => 'Attempt'])->assertForbidden();
            $this->getJson('/api/v1/custom-breeds')->assertOk()->assertJsonCount(1, 'data');
        }
        $this->assertSame(1, Breed::count());
    }

    public function test_permissions_are_centralised_in_the_role_map(): void
    {
        foreach ([FarmRole::Owner, FarmRole::Manager, FarmRole::FarmWorker, FarmRole::Finance] as $role) {
            $this->assertTrue($role->can(Permission::MasterDataView), "{$role->value} should view master data");
        }
        $this->assertTrue(FarmRole::Owner->can(Permission::MasterDataManage));
        $this->assertTrue(FarmRole::Manager->can(Permission::MasterDataManage));
        $this->assertFalse(FarmRole::FarmWorker->can(Permission::MasterDataManage));
        $this->assertFalse(FarmRole::Finance->can(Permission::MasterDataManage));
    }

    public function test_custom_crop_varieties_follow_the_same_rules(): void
    {
        $this->systemVariety('maize', 'Hybrid Sample');
        $maize = $this->crop('maize')->id;
        $this->signInAs($this->owner);

        $created = $this->postJson('/api/v1/custom-varieties', ['crop_type_id' => $maize, 'name' => ' Local  White '])
            ->assertCreated()->assertJsonPath('data.source', 'farm')->assertJsonPath('data.name', 'Local White');
        $this->assertSame($this->farm->id, CropVariety::find($created->json('data.id'))->farm_id);

        $this->postJson('/api/v1/custom-varieties', ['crop_type_id' => $maize, 'name' => 'local white'])->assertStatus(409);
        $this->postJson('/api/v1/custom-varieties', ['crop_type_id' => $maize, 'name' => 'hybrid sample'])->assertStatus(409)->assertJsonPath('details.source', 'system');
        $this->postJson('/api/v1/custom-varieties', ['crop_type_id' => $maize, 'name' => 'X1', 'farm_id' => $this->farm->id])->assertStatus(422);
        // livestock species id is not a crop
        $this->postJson('/api/v1/custom-varieties', ['crop_type_id' => $this->species('chicken')->id, 'name' => 'Nope'])->assertStatus(422);

        $id = $created->json('data.id');
        $this->patchJson("/api/v1/custom-varieties/{$id}", ['is_active' => false])->assertOk();
        $this->getJson("/api/v1/master/crops/{$maize}/varieties")->assertJsonCount(1, 'data'); // only the system one left
        $this->patchJson("/api/v1/custom-varieties/{$id}", ['is_active' => true, 'crop_type_id' => $this->crop('yam')->id])->assertStatus(422);
        $this->patchJson("/api/v1/custom-varieties/{$id}", ['is_active' => true])->assertOk();
        $this->getJson("/api/v1/master/crops/{$maize}/varieties")->assertJsonCount(2, 'data');
    }

    public function test_database_enforces_name_uniqueness_per_scope(): void
    {
        $this->customBreed($this->farm, 'goat', 'Boer');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->customBreed($this->farm, 'goat', ' boer ');
    }
}
