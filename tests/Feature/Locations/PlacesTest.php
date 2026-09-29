<?php

namespace Tests\Feature\Locations;

use App\Enums\FarmRole;
use App\Enums\PlaceKind;
use App\Events\Locations\PlaceChanged;
use App\Models\FarmMembership;
use App\Models\User;
use App\Services\Locations\PlaceService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Team\TeamTestCase;

class PlacesTest extends TeamTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAs($this->owner);
    }

    public static function kinds(): array
    {
        return [
            'locations' => ['locations', 'site', PlaceKind::Location],
            'areas' => ['production-areas', 'pen', PlaceKind::ProductionArea],
            'stores' => ['storage-locations', 'store', PlaceKind::StorageLocation],
        ];
    }

    private function createPlace(string $path = 'locations', string $name = 'Main Site', string $type = 'site', ?string $parent = null): array
    {
        return $this->postJson('/api/v1/'.$path, ['name' => $name, 'type' => $type, 'parent_id' => $parent])
            ->assertCreated()->json('data');
    }

    private function context(): FarmContext
    {
        return new FarmContext($this->farm, $this->membershipOf($this->owner));
    }

    #[DataProvider('kinds')]
    public function test_zero_places_then_top_level_create_rename_archive_reactivate(string $path, string $type, PlaceKind $kind): void
    {
        $this->getJson('/api/v1/'.$path)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/farm')->assertOk();
        $place = $this->createPlace($path, '  Pen   1  ', $type);
        $this->assertSame('7', $place['id'][14]);
        $this->assertSame('Pen 1', $place['name']);
        $this->assertSame($kind->value, $place['kind']);
        $this->assertNull($place['parent_id']);
        $this->assertSame(0, $place['depth']);
        $this->assertSame('Pen 1', $place['path_label']);
        $url = '/api/v1/'.$path.'/'.$place['id'];
        $this->patchJson($url, ['name' => 'House A'])->assertOk()->assertJsonPath('data.id', $place['id']);
        $this->patchJson($url, ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson('/api/v1/'.$path)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/'.$path.'?include_inactive=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($url)->assertOk()->assertJsonPath('data.name', 'House A');
        $this->patchJson($url, ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
        $this->deleteJson($url)->assertStatus(405);
        $this->assertDatabaseHas((new ($kind->model()))->getTable(), ['id' => $place['id'], 'farm_id' => $this->farm->id]);
    }

    #[DataProvider('kinds')]
    public function test_parent_paths_and_moves_are_optional_and_keep_identity(string $path, string $type): void
    {
        $site = $this->createPlace();
        $house = $this->createPlace('locations', 'Poultry House', 'house', $site['id']);
        $place = $this->createPlace($path, 'Pen A', $type, $house['id']);
        $this->assertSame('Main Site / Poultry House / Pen A', $place['path_label']);
        $this->assertSame([$site['id'], $house['id'], $place['id']], array_column($place['path'], 'id'));
        $this->assertSame(2, $place['depth']);
        $this->patchJson('/api/v1/locations/'.$site['id'], ['name' => 'Abuja Site'])->assertOk();
        $this->getJson('/api/v1/'.$path.'/'.$place['id'])->assertOk()->assertJsonPath('data.path_label', 'Abuja Site / Poultry House / Pen A');
        $this->patchJson('/api/v1/'.$path.'/'.$place['id'], ['parent_id' => null])->assertOk()
            ->assertJsonPath('data.depth', 0)->assertJsonPath('data.parent_id', null)->assertJsonPath('data.id', $place['id']);
    }

    #[DataProvider('kinds')]
    public function test_duplicates_include_case_whitespace_and_inactive_siblings_but_not_other_branches(string $path, string $type): void
    {
        $root = $this->createPlace($path, 'Pen One', $type);
        $this->postJson('/api/v1/'.$path, ['name' => " PEN\t one ", 'type' => $type])->assertConflict()->assertJsonPath('code', 'duplicate_location');
        $this->patchJson('/api/v1/'.$path.'/'.$root['id'], ['is_active' => false])->assertOk();
        $this->postJson('/api/v1/'.$path, ['name' => 'pen one', 'type' => $type])->assertConflict();
        $a = $this->createPlace('locations', 'Site A');
        $b = $this->createPlace('locations', 'Site B');
        $one = $this->createPlace($path, 'Pen One', $type, $a['id']);
        $two = $this->createPlace($path, 'Pen One', $type, $b['id']);
        $this->patchJson('/api/v1/'.$path.'/'.$two['id'], ['parent_id' => $a['id']])->assertConflict()->assertJsonPath('code', 'duplicate_location');
        $this->patchJson('/api/v1/'.$path.'/'.$one['id'], ['name' => 'PEN ONE'])->assertOk();
        $this->getJson('/api/v1/'.$path.'/'.$two['id'])->assertJsonPath('data.parent_id', $b['id']);
    }

    #[DataProvider('kinds')]
    public function test_database_unique_constraint_also_protects_root_siblings(string $path, string $type, PlaceKind $kind): void
    {
        $place = $this->createPlace($path, 'A', $type);
        $row = (array) DB::table((new ($kind->model()))->getTable())->find($place['id']);
        unset($row['parent_scope']);
        $row['id'] = (string) Str::uuid7();
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table((new ($kind->model()))->getTable())->insert($row);
    }

    #[DataProvider('kinds')]
    public function test_foreign_farm_ids_are_invisible_for_reads_writes_parents_and_filters(string $path, string $type): void
    {
        [$owner, $farm] = $this->otherFarm();
        $this->signInAs($owner);
        $parent = $this->createPlace();
        $foreign = $this->createPlace($path, 'Foreign', $type, $parent['id']);
        $this->signInAs($this->owner);
        $this->getJson('/api/v1/'.$path)->assertOk()->assertJsonCount(0, 'data');
        $url = '/api/v1/'.$path.'/'.$foreign['id'];
        $this->getJson($url)->assertNotFound();
        $this->patchJson($url, ['name' => 'Hijack'])->assertNotFound();
        $this->postJson('/api/v1/'.$path, ['name' => 'Local', 'type' => $type, 'parent_id' => $parent['id']])->assertNotFound();
        $local = $this->createPlace($path, 'Local', $type);
        $this->patchJson('/api/v1/'.$path.'/'.$local['id'], ['parent_id' => $parent['id']])->assertNotFound();
        $this->getJson('/api/v1/'.$path.'?parent_id='.$parent['id'])->assertNotFound();
        $this->withHeader('X-Farm-Id', $farm->id)->getJson('/api/v1/'.$path)->assertForbidden()->assertJsonPath('code', 'farm_access_denied');
    }

    #[DataProvider('kinds')]
    public function test_composite_foreign_key_prevents_cross_farm_parent_even_outside_service(string $path, string $type, PlaceKind $kind): void
    {
        $local = $this->createPlace($path, 'Local', $type);
        [$owner] = $this->otherFarm();
        $this->signInAs($owner);
        $foreign = $this->createPlace();
        $this->expectException(QueryException::class);
        DB::table((new ($kind->model()))->getTable())->where('id', $local['id'])->update([$kind->parentColumn() => $foreign['id']]);
    }

    public function test_x_farm_id_selects_only_an_active_membership(): void
    {
        [$owner, $farm] = $this->otherFarm();
        $this->signInAs($owner);
        $foreign = $this->createPlace();
        FarmMembership::create(['farm_id' => $farm->id, 'user_id' => $this->owner->id, 'role' => FarmRole::FarmWorker]);
        $this->signInAs($this->owner)->withHeader('X-Farm-Id', $farm->id);
        $this->getJson('/api/v1/locations')->assertOk()->assertJsonPath('data.0.id', $foreign['id']);
        $this->postJson('/api/v1/locations', ['name' => 'Denied', 'type' => 'site'])->assertForbidden();
        $this->removeMembership($this->owner, $farm);
        $this->getJson('/api/v1/locations')->assertForbidden();
    }

    public function test_self_direct_and_indirect_cycles_are_rejected_atomically(): void
    {
        $a = $this->createPlace('locations', 'A');
        $b = $this->createPlace('locations', 'B', 'building', $a['id']);
        $c = $this->createPlace('locations', 'C', 'house', $b['id']);
        foreach ([$a, $b, $c] as $parent) {
            $this->patchJson('/api/v1/locations/'.$a['id'], ['parent_id' => $parent['id'], 'name' => 'Bad move'])
                ->assertConflict()->assertJsonPath('code', 'location_cycle');
        }
        $this->getJson('/api/v1/locations/'.$a['id'])->assertJsonPath('data.parent_id', null)->assertJsonPath('data.name', 'A');
    }

    public function test_depth_limit_includes_terminal_area_and_entire_moved_subtree(): void
    {
        $parent = null;
        $nodes = [];
        for ($i = 0; $i <= PlaceService::MAX_DEPTH; $i++) {
            $nodes[] = $node = $this->createPlace('locations', 'Level '.$i, 'site', $parent);
            $parent = $node['id'];
        }
        foreach (self::kinds() as [$path, $type]) {
            $this->postJson('/api/v1/'.$path, ['name' => 'Too Deep', 'type' => $type, 'parent_id' => $parent])
                ->assertConflict()->assertJsonPath('code', 'location_depth_exceeded');
        }
        $root = $this->createPlace('locations', 'Moving');
        $this->createPlace('production-areas', 'Leaf', 'pen', $root['id']);
        $this->patchJson('/api/v1/locations/'.$root['id'], ['parent_id' => $nodes[6]['id']])
            ->assertConflict()->assertJsonPath('code', 'location_depth_exceeded');
        $this->patchJson('/api/v1/locations/'.$root['id'], ['parent_id' => $nodes[5]['id']])->assertOk();
    }

    #[DataProvider('kinds')]
    public function test_inactive_parent_and_active_descendant_rules(string $path, string $type): void
    {
        $parent = $this->createPlace();
        $child = $this->createPlace($path, 'Child', $type, $parent['id']);
        $parentUrl = '/api/v1/locations/'.$parent['id'];
        $childUrl = '/api/v1/'.$path.'/'.$child['id'];
        $this->patchJson($parentUrl, ['is_active' => false])->assertConflict()->assertJsonPath('code', 'location_has_active_children');
        $this->patchJson($childUrl, ['is_active' => false])->assertOk();
        $this->patchJson($parentUrl, ['is_active' => false])->assertOk();
        $this->getJson($childUrl)->assertOk()->assertJsonPath('data.path_label', 'Main Site / Child');
        $this->patchJson($childUrl, ['name' => 'Historical'])->assertOk();
        $this->patchJson($childUrl, ['is_active' => true])->assertConflict()->assertJsonPath('code', 'location_inactive');
        $this->postJson('/api/v1/'.$path, ['name' => 'New', 'type' => $type, 'parent_id' => $parent['id'], 'is_active' => false])->assertConflict();
        $other = $this->createPlace($path, 'Other', $type);
        $this->patchJson('/api/v1/'.$path.'/'.$other['id'], ['parent_id' => $parent['id']])->assertConflict();
        $this->patchJson($parentUrl, ['is_active' => true])->assertOk();
        $this->patchJson($childUrl, ['is_active' => true])->assertOk();
    }

    public function test_deactivation_checks_active_deep_descendants(): void
    {
        $root = $this->createPlace();
        $child = $this->createPlace('locations', 'House', 'house', $root['id']);
        $this->createPlace('production-areas', 'Pen', 'pen', $child['id']);
        $this->patchJson('/api/v1/locations/'.$root['id'], ['is_active' => false])->assertConflict();
    }

    #[DataProvider('kinds')]
    public function test_types_and_server_owned_fields_are_validated(string $path, string $type): void
    {
        $this->postJson('/api/v1/'.$path, ['name' => 'Valid', 'type' => 'bogus'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->postJson('/api/v1/'.$path, ['name' => '   ', 'type' => $type])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/'.$path, ['name' => 'Valid', 'type' => $type, 'farm_id' => $this->farm->id])->assertUnprocessable()->assertJsonValidationErrors('farm_id');
        $this->postJson('/api/v1/'.$path, ['name' => 'Valid', 'type' => $type, 'parent_id' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->postJson('/api/v1/'.$path, ['name' => 'Valid', 'type' => $type, 'parent_id' => (string) Str::uuid7()])->assertNotFound();
        $leaf = $this->createPlace('production-areas', 'Leaf', 'pen');
        $this->postJson('/api/v1/'.$path, ['name' => 'Valid', 'type' => $type, 'parent_id' => $leaf['id']])->assertNotFound();
        $place = $this->createPlace($path, 'Valid', $type);
        $this->patchJson('/api/v1/'.$path.'/'.$place['id'], ['id' => (string) Str::uuid7(), 'type' => null])->assertUnprocessable()->assertJsonValidationErrors(['id', 'type']);
    }

    public function test_catalogue_is_read_only_and_every_code_is_accepted_for_its_kind(): void
    {
        $catalogue = $this->getJson('/api/v1/master/location-types')->assertOk()->assertJsonCount(3, 'data')->json('data');
        foreach (array_values(self::kinds()) as $index => [$path, $unused, $kind]) {
            $this->assertSame($kind->value, $catalogue[$index]['kind']);
            $this->assertSame(array_keys($kind->types()), array_column($catalogue[$index]['types'], 'code'));
            foreach ($kind->types() as $code => $label) {
                $place = $this->createPlace($path, $label, $code);
                $this->assertSame($label, $place['type_label']);
            }
        }
        $this->postJson('/api/v1/master/location-types', ['code' => 'new'])->assertStatus(405);
    }

    #[DataProvider('kinds')]
    public function test_filters_pagination_order_and_paths(string $path, string $type): void
    {
        $parent = $this->createPlace('locations', 'Parent');
        $this->createPlace($path, 'Zulu', $type, $parent['id']);
        $alpha = $this->createPlace($path, 'Alpha', $type, $parent['id']);
        $this->createPlace($path, 'Beta', $type);
        $this->getJson('/api/v1/'.$path.'?parent_id='.$parent['id'].'&per_page=1')->assertOk()
            ->assertJsonPath('data.0.id', $alpha['id'])->assertJsonPath('data.0.path_label', 'Parent / Alpha')
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/v1/'.$path.'?parent_id='.$parent['id'].'&per_page=1&page=2')->assertJsonPath('data.0.name', 'Zulu');
        $this->getJson('/api/v1/'.$path.'?search=ALP&type='.$type)->assertOk()->assertJsonCount(1, 'data');
        $roots = $this->getJson('/api/v1/'.$path.'?top_level=1')->assertOk()->json('data');
        foreach ($roots as $row) {
            $this->assertNull($row['parent_id']);
        }
        $this->getJson('/api/v1/'.$path.'?search=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/'.$path.'?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/'.$path.'?top_level=1&parent_id='.$parent['id'])->assertUnprocessable();
        $this->getJson('/api/v1/'.$path.'?operation=poultry')->assertUnprocessable();
    }

    public function test_path_queries_do_not_grow_with_number_of_siblings(): void
    {
        $site = $this->createPlace();
        $house = $this->createPlace('locations', 'House', 'house', $site['id']);
        $this->createPlace('production-areas', 'First', 'pen', $house['id']);
        DB::enableQueryLog();
        $this->getJson('/api/v1/production-areas')->assertOk();
        $before = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 5; $i++) {
            $this->createPlace('production-areas', 'Pen '.$i, 'pen', $house['id']);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/production-areas')->assertOk()->assertJsonCount(6, 'data');
        $this->assertSame($before, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    #[DataProvider('kinds')]
    public function test_rbac_for_all_roles_and_free_plan(string $path, string $type): void
    {
        $place = $this->createPlace($path, 'Owner place', $type);
        foreach ([FarmRole::Manager, FarmRole::FarmWorker, FarmRole::Finance] as $role) {
            $this->signInAs($this->member($role));
            $this->getJson('/api/v1/'.$path)->assertOk();
            $this->getJson('/api/v1/'.$path.'/'.$place['id'])->assertOk();
            $this->getJson('/api/v1/master/location-types')->assertOk();
            $status = $role === FarmRole::Manager ? 201 : 403;
            $this->postJson('/api/v1/'.$path, ['name' => $role->value, 'type' => $type])->assertStatus($status);
            $this->patchJson('/api/v1/'.$path.'/'.$place['id'], ['name' => 'Managed'])->assertStatus($status === 201 ? 200 : 403);
        }
    }

    public function test_access_requires_authentication_verification_and_onboarding(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/locations')->assertUnauthorized();
        $this->signInAs(User::factory()->unverified()->create());
        $this->getJson('/api/v1/locations')->assertForbidden()->assertJsonPath('code', 'email_verification_required');
        $this->signInAs(User::factory()->create());
        $this->getJson('/api/v1/locations')->assertForbidden()->assertJsonPath('code', 'onboarding_required');
    }

    public function test_service_assignment_gate_rejects_inactive_and_foreign_places(): void
    {
        $place = $this->createPlace();
        $service = app(PlaceService::class);
        $this->assertSame($place['id'], $service->selectable($this->farm, PlaceKind::Location, $place['id'])->id);
        $this->patchJson('/api/v1/locations/'.$place['id'], ['is_active' => false])->assertOk();
        try {
            $service->selectable($this->farm, PlaceKind::Location, $place['id']);
            $this->fail('Inactive place accepted');
        } catch (ApiHttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        [, $farm] = $this->otherFarm();
        $this->expectException(ModelNotFoundException::class);
        $service->selectable($farm, PlaceKind::Location, $place['id']);
    }

    public function test_events_include_safe_changes_and_noop_emits_nothing(): void
    {
        Event::fake([PlaceChanged::class]);
        $place = $this->createPlace();
        Event::assertDispatched(PlaceChanged::class, fn ($e) => $e->action === 'created' && $e->place->farm_id === $this->farm->id && $e->actor->id === $this->owner->id);
        $url = '/api/v1/locations/'.$place['id'];
        $this->patchJson($url, ['name' => 'Renamed'])->assertOk();
        Event::assertDispatched(PlaceChanged::class, fn ($e) => $e->action === 'updated' && $e->changes['name'] === ['old' => 'Main Site', 'new' => 'Renamed']);
        $this->patchJson($url, ['is_active' => false])->assertOk();
        $this->patchJson($url, ['is_active' => true])->assertOk();
        Event::assertDispatched(PlaceChanged::class, fn ($e) => $e->action === 'deactivated');
        Event::assertDispatched(PlaceChanged::class, fn ($e) => $e->action === 'reactivated');
        Event::assertDispatchedTimes(PlaceChanged::class, 4);
        $this->patchJson($url, ['name' => 'Renamed'])->assertOk();
        Event::assertDispatchedTimes(PlaceChanged::class, 4);
        $this->patchJson($url, ['parent_id' => $place['id']])->assertConflict();
        Event::assertDispatchedTimes(PlaceChanged::class, 4);
    }

    public function test_service_does_not_allow_foreign_update(): void
    {
        $place = $this->createPlace();
        [$owner, $farm] = $this->otherFarm();
        $service = app(PlaceService::class);
        $this->expectException(ModelNotFoundException::class);
        $service->save(new FarmContext($farm, $this->membershipOf($owner, $farm)), $owner, PlaceKind::Location, ['name' => 'Hijack'], $place['id']);
    }

    public function test_service_checks_permission_without_controller(): void
    {
        $worker = $this->member(FarmRole::FarmWorker);
        try {
            app(PlaceService::class)->save(new FarmContext($this->farm, $this->membershipOf($worker)), $worker, PlaceKind::Location, ['name' => 'Denied', 'type' => 'site']);
            $this->fail('Worker bypassed service permission');
        } catch (ApiHttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('forbidden', $e->errorCode);
        }
        $this->assertSame(0, $this->farm->locations()->count());
    }

    public function test_outer_transaction_rollback_discards_mutation_and_event(): void
    {
        Event::fake([PlaceChanged::class]);
        try {
            DB::transaction(function () {
                app(PlaceService::class)->save($this->context(), $this->owner, PlaceKind::Location, ['name' => 'Rolled Back', 'type' => 'site']);
                Event::assertNotDispatched(PlaceChanged::class);
                throw new \RuntimeException('Deliberate rollback');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('Deliberate rollback', $e->getMessage());
        }
        Event::assertNotDispatched(PlaceChanged::class);
        $this->assertDatabaseMissing('locations', ['name' => 'Rolled Back']);
    }

    public function test_parent_changes_are_observable_and_history_uuid_remains_referenceable(): void
    {
        $parent = $this->createPlace();
        $place = $this->createPlace('production-areas', 'Pen', 'pen');
        Event::fake([PlaceChanged::class]);
        $this->patchJson('/api/v1/production-areas/'.$place['id'], ['parent_id' => $parent['id']])->assertOk();
        Event::assertDispatched(PlaceChanged::class, fn ($e) => $e->action === 'updated' && $e->changes['parent_id'] === ['old' => null, 'new' => $parent['id']]);
        $model = app(PlaceService::class)->find($this->farm, PlaceKind::ProductionArea, $place['id']);
        $this->expectException(\LogicException::class);
        $model->delete();
    }

    public function test_write_rate_limit_returns_standard_error(): void
    {
        config(['identity.rate_limits.location_write.user' => [1, 60]]);
        $this->createPlace();
        $this->postJson('/api/v1/storage-locations', ['name' => 'Store', 'type' => 'store'])->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests')->assertHeader('Retry-After');
    }
}
