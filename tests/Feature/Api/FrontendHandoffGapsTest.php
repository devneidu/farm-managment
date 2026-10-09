<?php

namespace Tests\Feature\Api;

use App\Enums\FarmRole;
use App\Enums\Permission;
use App\Models\CropType;
use App\Models\FarmMembership;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\OperationType;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Species;
use App\Models\SpeciesCapability;
use App\Models\StorageLocation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Team\TeamTestCase;

/**
 * Final frontend-handoff corrections GAP-01..GAP-08: discoverable permissions, cycle-valid record types, item kind, stock metadata,
 * reason ambiguity, route normalisation, farm discovery and output-named sale/purchase lines.
 */
class FrontendHandoffGapsTest extends TeamTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->onPlan('farm-pro');
        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
        $this->signInAs($this->owner);
    }

    // ------------------------------------------------------------------ helpers

    private function at(int $hoursAgo = 1): string
    {
        return now()->utc()->subHours($hoursAgo)->format('Y-m-d\TH:i:s\Z');
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    private function livestock(string $species = 'chicken'): string
    {
        $operation = Species::where('code', $species)->firstOrFail()->operationType->code;

        return $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => ucfirst($species).' '.Str::random(4), 'operation_type_id' => OperationType::where('code', $operation)->firstOrFail()->id,
            'species_id' => Species::where('code', $species)->firstOrFail()->id, 'production_purpose' => ($species === 'honeybee' ? 'colony_breeding' : 'breeding'), 'initial_population' => 100, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
    }

    private function crop(): string
    {
        return $this->postJson('/api/v1/production-cycles', ['kind' => 'crop', 'name' => 'Yam '.Str::random(4), 'operation_type_id' => OperationType::where('code', 'crops')->firstOrFail()->id,
            'crop_type_id' => CropType::where('code', 'yam')->firstOrFail()->id, 'initial_planting_units' => 800, 'planting_unit_type' => 'heap', 'planting_material_type' => 'tuber', 'planting_date' => '2026-01-01'])->assertCreated()->json('data.id');
    }

    private function eggsRecord(string $cycle, int $pieces)
    {
        return $this->postJson('/api/v1/records', ['production_cycle_id' => $cycle, 'type' => 'egg_collection', 'details' => ['components' => [['quantity' => $pieces, 'unit' => 'piece']]], 'recorded_at' => $this->at(10), 'idempotency_key' => $this->key()]);
    }

    private function line(array $target, string $qty, string $unit, string $amount): array
    {
        return ['kind' => 'stock'] + $target + ['components' => [['quantity' => $qty, 'unit' => $unit]], 'amount' => $amount];
    }

    private function sale(array $items, array $extra = [])
    {
        return $this->postJson('/api/v1/sales', array_replace(['recorded_at' => $this->at(3), 'idempotency_key' => $this->key(), 'items' => $items], $extra));
    }

    private function purchase(array $items, array $extra = [])
    {
        return $this->postJson('/api/v1/purchases', array_replace(['recorded_at' => $this->at(3), 'idempotency_key' => $this->key(), 'items' => $items], $extra));
    }

    private function balance(string $kind): array
    {
        return $this->getJson('/api/v1/inventory/output-balances')->assertOk()->json('data.'.$kind);
    }

    // ------------------------------------------------------------------ GAP-01 / GAP-04

    public function test_record_schemas_state_every_permission_the_backend_checks_and_keep_the_legacy_field(): void
    {
        $types = collect($this->getJson('/api/v1/master/record-types')->assertOk()->json('data'))->keyBy('type');

        foreach (['egg_collection', 'milk'] as $type) {
            $this->assertSame(['record.create', 'inventory.use'], $types[$type]['permissions_required']['always']);
            $this->assertSame([], $types[$type]['permissions_required']['when_inventory_linked']);
            $this->assertSame('record.create', $types[$type]['permission']); // legacy field untouched
        }
        foreach (['feed_use', 'fertilizer_application', 'pesticide_application', 'planting', 'crop_harvest'] as $type) {
            $this->assertSame(['record.create'], $types[$type]['permissions_required']['always'], $type);
            $this->assertSame(['inventory.use'], $types[$type]['permissions_required']['when_inventory_linked'], $type);
        }
        $this->assertSame(['record.adjust'], $types['population_adjustment']['permissions_required']['always']);
        $this->assertSame('record.adjust', $types['population_adjustment']['permission']);
        $this->assertSame(['record.create'], $types['mortality']['permissions_required']['always']);
        $this->assertSame([], $types['mortality']['permissions_required']['when_inventory_linked']);
        foreach ($types as $type) {
            $this->assertSame(['record.reverse'], $type['permissions_required']['when_correcting']);
        }

        // The single-type endpoint says the same.
        $this->assertSame($types['milk']['permissions_required'], $this->getJson('/api/v1/record-types/milk/schema')->assertOk()->json('data.permissions_required'));
    }

    public function test_declared_permissions_match_what_roles_actually_get_from_the_endpoint(): void
    {
        $cycle = $this->livestock();
        $types = collect($this->getJson('/api/v1/master/record-types')->assertOk()->json('data'))->keyBy('type');
        $required = $types['egg_collection']['permissions_required']['always'];

        foreach ([FarmRole::Manager, FarmRole::FarmWorker, FarmRole::Vet, FarmRole::Finance] as $role) {
            $user = $this->member($role);
            $membership = $this->membershipOf($user);
            $holds = collect($required)->every(fn ($permission) => $membership->role->can(Permission::from($permission)));
            $this->signInAs($user);
            $status = $this->eggsRecord($cycle, 1)->status();
            $this->assertSame($holds ? 201 : 403, $status, $role->value);
        }
    }

    public function test_record_schemas_describe_stock_and_output_integration_structurally(): void
    {
        $types = collect($this->getJson('/api/v1/master/record-types')->assertOk()->json('data'))->keyBy('type');

        $eggs = $types['egg_collection']['inventory'];
        $this->assertSame(['automatic_output', 'in', 'eggs', 'produce', ['count'], false, true], [$eggs['mode'], $eggs['direction'], $eggs['output'], $eggs['item_category'], $eggs['dimensions'], $eggs['optional'], $eggs['creates_item']]);
        $this->assertSame(['storage_location_id' => 'optional'], $eggs['fields']);
        $this->assertSame('milk', $types['milk']['inventory']['output']);
        $this->assertSame(['volume'], $types['milk']['inventory']['dimensions']);

        $feed = $types['feed_use']['inventory'];
        $this->assertSame(['optional_link', 'out', null, 'feed', true], [$feed['mode'], $feed['direction'], $feed['output'], $feed['item_category'], $feed['optional']]);
        $this->assertSame(['item_id' => 'required', 'storage_location_id' => 'required', 'lot_id' => 'optional'], $feed['fields']);

        $harvest = $types['crop_harvest']['inventory'];
        $this->assertSame(['in', 'produce'], [$harvest['direction'], $harvest['item_category']]);
        $this->assertSame('optional', $harvest['fields']['lot']); // a harvest may open a new lot
        $this->assertArrayNotHasKey('lot', $types['planting']['inventory']['fields']);
        $this->assertNull($types['mortality']['inventory']);
        $this->assertNull($types['weeding']['inventory']);

        // Area sub-fields are named, and never confused with the stock/planting quantity.
        $this->assertSame(['treated_area'], $types['fertilizer_application']['area_fields']);
        $this->assertSame(['affected_area'], $types['crop_loss']['area_fields']);
        $this->assertSame([], $types['milk']['area_fields']);

        // The legacy flat fields are preserved.
        $this->assertSame(['produce', 'in', true, 'eggs', false], [$types['egg_collection']['inventory_category'], $types['egg_collection']['inventory_direction'], $types['egg_collection']['inventory_automatic'], $types['egg_collection']['inventory_output'], $types['egg_collection']['inventory_required']]);

        // Consistency: a type that always needs inventory.use is the automatic-output one, and conditional ones are the optional links.
        foreach ($types as $type) {
            if (in_array('inventory.use', $type['permissions_required']['always'], true)) {
                $this->assertSame('automatic_output', $type['inventory']['mode'], $type['type']);
            }
            if ($type['permissions_required']['when_inventory_linked'] !== []) {
                $this->assertSame('optional_link', $type['inventory']['mode'], $type['type']);
            }
        }
    }

    // ------------------------------------------------------------------ GAP-02

    public function test_cycle_detail_lists_exactly_the_record_types_record_creation_accepts(): void
    {
        $poultry = $this->livestock();
        $dairy = $this->livestock('cattle');
        $crop = $this->crop();
        $allTypes = array_column($this->getJson('/api/v1/master/record-types')->assertOk()->json('data'), 'type');

        $availability = [];
        foreach ([$poultry, $dairy, $crop] as $cycle) {
            $detail = $this->getJson('/api/v1/production-cycles/'.$cycle)->assertOk();
            $availability[$cycle] = array_column($detail->json('data.available_record_types'), 'type');
            $this->assertSame($availability[$cycle], array_column($this->getJson('/api/v1/production-cycles/'.$cycle.'/summary')->assertOk()->json('data.available_record_types'), 'type'));
        }
        $this->assertContains('egg_collection', $availability[$poultry]);
        $this->assertContains('mortality', $availability[$poultry]);
        $this->assertNotContains('milk', $availability[$poultry]);
        $this->assertNotContains('planting', $availability[$poultry]);
        $this->assertContains('milk', $availability[$dairy]);
        $this->assertNotContains('egg_collection', $availability[$dairy]);
        $this->assertContains('planting', $availability[$crop]);
        $this->assertContains('general_note', $availability[$crop]);
        $this->assertNotContains('mortality', $availability[$crop]);
        $this->assertNotContains('feed_use', $availability[$crop]);

        // Same rules as creation: a type is advertised exactly when POST /records does not refuse it on `type`.
        foreach ($availability as $cycle => $available) {
            foreach ($allTypes as $type) {
                $response = $this->postJson('/api/v1/records', ['production_cycle_id' => $cycle, 'type' => $type, 'details' => [], 'recorded_at' => $this->at(2), 'idempotency_key' => $this->key()]);
                $refusedOnType = $response->status() === 422 && array_key_exists('type', $response->json('errors') ?? []);
                $this->assertSame(! in_array($type, $available, true), $refusedOnType, $type.' on '.$cycle);
            }
        }

        // Each entry carries the permissions, so the frontend intersects with GET /farm → membership.permissions.
        $egg = collect($this->getJson('/api/v1/production-cycles/'.$poultry)->json('data.available_record_types'))->firstWhere('type', 'egg_collection');
        $this->assertSame(['record.create', 'inventory.use'], $egg['permissions_required']['always']);
    }

    public function test_available_record_types_follow_species_capability_and_cycle_status_and_stay_off_lists_and_writes(): void
    {
        $poultry = $this->livestock();
        $cattle = $this->livestock('cattle');
        SpeciesCapability::where('species_id', Species::where('code', 'chicken')->value('id'))->whereHas('capability', fn ($q) => $q->where('code', 'produces_eggs'))->update(['enabled' => false]);
        $types = fn (string $id) => array_column($this->getJson('/api/v1/production-cycles/'.$id)->json('data.available_record_types'), 'type');
        $this->assertNotContains('egg_collection', $types($poultry));
        $this->assertContains('mortality', $types($poultry));

        $this->assertArrayNotHasKey('available_record_types', $this->getJson('/api/v1/production-cycles')->assertOk()->json('data.0'));
        $this->assertArrayNotHasKey('available_record_types', $this->postJson('/api/v1/production-cycles/'.$cattle.'/close', ['end_date' => '2026-02-01', 'reason' => 'Done'])->assertOk()->json('data'));
        $this->assertSame([], $this->getJson('/api/v1/production-cycles/'.$cattle)->json('data.available_record_types')); // closed cycle accepts nothing

        $this->postJson('/api/v1/production-cycles/'.$cattle.'/reopen', ['reason' => 'Mistake'])->assertOk();
        $this->assertContains('milk', $types($cattle));
    }

    public function test_cycle_detail_is_tenant_scoped(): void
    {
        $cycle = $this->livestock();
        [$other] = $this->otherFarm();
        $this->signInAs($other)->getJson('/api/v1/production-cycles/'.$cycle)->assertNotFound();
    }

    // ------------------------------------------------------------------ GAP-03

    public function test_inventory_items_expose_a_stable_kind_and_system_flag_never_derived_from_the_name(): void
    {
        $feed = $this->postJson('/api/v1/inventory/items', ['name' => 'Layer mash', 'category' => 'feed', 'stock_unit' => 'kg'])->assertCreated();
        $this->assertSame(['feed', false], [$feed->json('data.kind'), $feed->json('data.is_system_managed')]);

        // A farmer's own non-produce item merely NAMED "Eggs" is a general, unmanaged item.
        $named = $this->postJson('/api/v1/inventory/items', ['name' => 'Eggs', 'category' => 'general_supply', 'stock_unit' => 'piece'])->assertCreated()->json('data');
        $this->assertSame(['general', false], [$named['kind'], $named['is_system_managed']]);

        $this->eggsRecord($this->livestock(), 30)->assertCreated();
        $balance = $this->balance('eggs');
        $this->assertNotSame($named['id'], $balance['inventory_item_id']);
        $this->assertTrue($balance['exists']);

        $show = $this->getJson('/api/v1/inventory/items/'.$balance['inventory_item_id'])->assertOk();
        $this->assertSame(['eggs', true], [$show->json('data.kind'), $show->json('data.is_system_managed')]);
        $list = collect($this->getJson('/api/v1/inventory/items?per_page=100')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('eggs', $list[$balance['inventory_item_id']]['kind']);
        $this->assertSame('general', $list[$named['id']]['kind']);
        $this->assertSame('feed', $list[$feed->json('data.id')]['kind']);
        $this->assertSame(['feed', 'eggs', 'milk', 'general'], array_keys($this->getJson('/api/v1/master/inventory-options')->json('data.reasons.by_item_kind'))); // kind values index the options

        $this->postJson('/api/v1/inventory/stock-in', ['output' => 'milk', 'reason' => 'opening_balance', 'components' => [['quantity' => '5', 'unit' => 'l']], 'recorded_at' => $this->at(5), 'idempotency_key' => $this->key()])->assertCreated();
        $this->assertSame('milk', $this->getJson('/api/v1/inventory/items/'.$this->balance('milk')['inventory_item_id'])->json('data.kind'));
    }

    // ------------------------------------------------------------------ GAP-05

    public function test_flat_reasons_say_for_which_item_kinds_a_reason_is_really_manual(): void
    {
        $reasons = $this->getJson('/api/v1/master/inventory-options')->assertOk()->json('data.reasons');
        $byKind = $reasons['by_item_kind'];
        $in = collect($reasons['in'])->keyBy('code');
        $out = collect($reasons['out'])->keyBy('code');

        // The ambiguity: flat production is manual (true for feed), but eggs/milk production is automatic.
        $this->assertTrue($in['production']['manual']);
        $this->assertSame(['feed'], $in['production']['manual_for_kinds']);
        $this->assertFalse(collect($byKind['eggs']['in'])->firstWhere('code', 'production')['manual']);
        $this->assertTrue(collect($byKind['feed']['in'])->firstWhere('code', 'production')['manual']); // feed production stays enterable by hand

        $this->assertSame(['feed', 'eggs', 'milk', 'general'], $in['donation']['manual_for_kinds']);
        $this->assertSame([], $out['sale']['manual_for_kinds']); // a sale is only ever made through /sales
        $this->assertSame([], $out['incubation']['manual_for_kinds']);
        $this->assertSame(['eggs', 'milk'], array_values(array_intersect(['eggs', 'milk'], $out['internal_use']['manual_for_kinds'])));
        $this->assertSame(['general'], $out['use']['manual_for_kinds']);
        $this->assertTrue($reasons['flat_lists']['deprecated']);
        $this->assertSame('by_item_kind', $reasons['flat_lists']['authoritative']);

        // Derived, never hand-maintained: every kind named is one whose by_item_kind entry is manual.
        foreach (['in' => $in, 'out' => $out] as $direction => $flat) {
            foreach ($flat as $code => $row) {
                foreach (['feed', 'eggs', 'milk', 'general'] as $kind) {
                    $entry = collect($byKind[$kind][$direction])->firstWhere('code', $code);
                    $this->assertSame($entry !== null && $entry['manual'] === true, in_array($kind, $row['manual_for_kinds'], true), $direction.'.'.$code.' for '.$kind);
                }
            }
        }
    }

    // ------------------------------------------------------------------ GAP-06

    public function test_every_metadata_route_exposes_a_normalised_url_without_changing_existing_paths(): void
    {
        $data = $this->getJson('/api/v1/master/inventory-options')->assertOk()->json('data.reasons');
        $routes = [];
        foreach (['in', 'out'] as $direction) {
            foreach ($data['by_item_kind'] as $kind) {
                foreach ($kind[$direction] as $entry) {
                    $routes[] = $entry['route'];
                    array_push($routes, ...($entry['also'] ?? []));
                }
            }
            foreach ($data[$direction] as $entry) {
                if ($entry['route'] !== null) {
                    $routes[] = $entry['route'];
                }
            }
        }
        $this->assertNotEmpty($routes);
        foreach ($routes as $route) {
            $this->assertStringStartsWith('/', $route['path']);
            $this->assertStringStartsNotWith('/api/v1', $route['path']); // existing semantics: relative to /api/v1
            $this->assertSame('/api/v1'.$route['path'], $route['url']);
        }
        $this->assertSame('/records', collect($data['by_item_kind']['eggs']['in'])->firstWhere('code', 'production')['route']['path']);
        $this->assertSame('/api/v1/sales', collect($data['out'])->firstWhere('code', 'sale')['route']['url']);

        // Dashboard quick_add: `endpoint` stays absolute, `url` equals it, `path` is the relative form.
        foreach ($this->getJson('/api/v1/dashboard')->assertOk()->json('data.quick_add') as $action) {
            $this->assertStringStartsWith('/api/v1/', $action['endpoint']);
            $this->assertSame($action['endpoint'], $action['url']);
            $this->assertSame('/api/v1'.$action['path'], $action['url']);
        }
    }

    public function test_task_record_prefill_exposes_endpoint_url_and_relative_path(): void
    {
        $cycle = $this->livestock();
        $task = $this->postJson('/api/v1/tasks', ['title' => 'Weigh birds', 'category' => 'feeding_watering', 'due_date' => '2026-10-14', 'production_cycle_id' => $cycle, 'linked_record_type' => 'weight', 'idempotency_key' => $this->key()])->assertCreated()->json('data.id');
        $prefill = $this->getJson('/api/v1/tasks/'.$task.'/record-prefill')->assertOk()->json('data');
        $this->assertSame('/api/v1/records', $prefill['endpoint']);
        $this->assertSame('/api/v1/records', $prefill['url']);
        $this->assertSame('/records', $prefill['path']);
    }

    // ------------------------------------------------------------------ GAP-07

    public function test_auth_me_lists_the_active_farm_memberships_without_permissions(): void
    {
        $me = $this->getJson('/api/v1/auth/me')->assertOk();
        $this->assertSame([['id' => $this->farm->id, 'name' => 'Green Acres', 'role' => 'owner']], $me->json('data.farms'));
        $this->assertSame($this->farm->id, $me->json('data.farm.id')); // existing field unchanged

        [, $second] = $this->otherFarm();
        $second->update(['name' => 'Second Farm']);
        FarmMembership::create(['farm_id' => $second->id, 'user_id' => $this->owner->id, 'role' => FarmRole::Manager->value]);
        $farms = $this->getJson('/api/v1/auth/me')->assertOk()->json('data.farms');
        $this->assertSame([$this->farm->id, $second->id], array_column($farms, 'id'));
        $this->assertSame(['owner', 'manager'], array_column($farms, 'role'));
        $this->assertSame(['id', 'name', 'role'], array_keys($farms[1])); // no permissions, no farm settings

        // The listed id is what X-Farm-Id accepts; permissions come from GET /farm of the selected farm.
        $selected = $this->withHeader('X-Farm-Id', $second->id)->getJson('/api/v1/farm')->assertOk();
        $this->assertSame($second->id, $selected->json('data.id'));
        $this->assertSame('manager', $selected->json('data.membership.role'));
        $this->assertIsArray($selected->json('data.membership.permissions'));
        $this->assertSame($this->farm->id, $this->withoutHeader('X-Farm-Id')->getJson('/api/v1/farm')->json('data.id')); // no header = default farm
    }

    public function test_auth_me_farms_are_active_memberships_of_the_caller_only(): void
    {
        [, $second] = $this->otherFarm();
        $user = User::factory()->onboarded('First Farm')->create();
        $membership = FarmMembership::create(['farm_id' => $second->id, 'user_id' => $user->id, 'role' => FarmRole::FarmWorker->value]);
        [$stranger, $strangerFarm] = $this->otherFarm();

        $this->signInAs($user);
        $this->assertCount(2, $this->getJson('/api/v1/auth/me')->json('data.farms'));

        $this->removeMembership($user, $second);
        $farms = $this->getJson('/api/v1/auth/me')->assertOk()->json('data.farms');
        $this->assertSame(['First Farm'], array_column($farms, 'name')); // removed membership is gone
        $this->assertNotContains($strangerFarm->id, array_column($farms, 'id')); // never another user's farm
        $this->withHeader('X-Farm-Id', $second->id)->getJson('/api/v1/farm')->assertForbidden()->assertJsonPath('code', 'farm_access_denied');
        $this->withHeader('X-Farm-Id', $strangerFarm->id)->getJson('/api/v1/farm')->assertForbidden()->assertJsonPath('code', 'farm_access_denied');
        $this->assertNotNull($membership->fresh());
    }

    public function test_a_user_without_a_farm_gets_an_empty_list(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->signInAs($user);
        $this->assertSame([], $this->getJson('/api/v1/auth/me')->assertOk()->json('data.farms'));
    }

    // ------------------------------------------------------------------ GAP-08

    public function test_a_purchase_line_can_name_the_output_and_follows_the_stock_in_store_rules(): void
    {
        $this->assertSame(0, InventoryItem::where('system_key', 'output:eggs')->count());
        $payload = ['recorded_at' => $this->at(3), 'idempotency_key' => $this->key(), 'items' => [$this->line(['output' => 'eggs'], '3', 'piece', '900.00')]];

        $purchase = $this->postJson('/api/v1/purchases', $payload)->assertCreated()->json('data');
        $item = InventoryItem::where('system_key', 'output:eggs')->sole(); // created like the stock-in workflow does
        $store = StorageLocation::sole();
        $this->assertSame('Main Store', $store->name);
        $this->assertSame([$item->id, $store->id], [$purchase['items'][0]['inventory_item_id'], $purchase['items'][0]['storage_location_id']]);
        $movement = InventoryMovement::where('inventory_item_id', $item->id)->sole();
        $this->assertSame(['purchase', $purchase['id']], [$movement->reason, $movement->purchase_id]);
        $this->assertSame('3', $this->balance('eggs')['available']['quantity']);
        $this->assertSame('900.00', $purchase['total_amount']);
        $this->assertSame(1, Purchase::count());

        // Exactly-once: the identical request replays the original purchase, no second movement.
        $again = $this->postJson('/api/v1/purchases', $payload)->assertCreated()->json('data');
        $this->assertSame($purchase['id'], $again['id']);
        $this->assertSame(1, InventoryMovement::where('inventory_item_id', $item->id)->count());
        $this->assertSame(1, InventoryItem::where('system_key', 'output:eggs')->count());

        // Two output lines in one purchase share the one item and one store.
        $this->purchase([$this->line(['output' => 'milk'], '10', 'l', '5000.00'), $this->line(['output' => 'eggs'], '2', 'piece', '600.00')])->assertCreated();
        $this->assertSame(1, StorageLocation::count());
        $this->assertSame('5', $this->balance('eggs')['available']['quantity']);
        $this->assertSame('10', $this->balance('milk')['available']['quantity']);
    }

    public function test_purchase_with_several_stores_needs_a_choice_and_honours_an_explicit_one(): void
    {
        $one = $this->postJson('/api/v1/storage-locations', ['name' => 'Egg room', 'type' => 'store'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/storage-locations', ['name' => 'Cold room', 'type' => 'store'])->assertCreated();

        $this->purchase([$this->line(['output' => 'eggs'], '3', 'piece', '900.00')])->assertUnprocessable()->assertJsonValidationErrors('items.0.storage_location_id');
        $this->assertSame(0, Purchase::count());
        $this->assertSame(0, InventoryItem::where('system_key', 'output:eggs')->count()); // the failed purchase left nothing behind

        $this->purchase([$this->line(['output' => 'eggs', 'storage_location_id' => $one], '3', 'piece', '900.00')])->assertCreated()->assertJsonPath('data.items.0.storage_location_id', $one);
    }

    public function test_sale_line_by_output_takes_existing_stock_and_never_creates_any(): void
    {
        $line = [$this->line(['output' => 'eggs'], '5', 'piece', '1500.00')];

        $this->sale($line)->assertStatus(409)->assertJsonPath('code', 'insufficient_stock')->assertJsonPath('details.available.quantity', '0');
        $this->assertSame(0, InventoryItem::where('system_key', 'output:eggs')->count()); // no fake stock because someone tried to sell
        $this->assertSame(0, StorageLocation::count());
        $this->assertSame(0, Sale::count());

        $this->eggsRecord($this->livestock(), 40)->assertCreated();
        $item = InventoryItem::where('system_key', 'output:eggs')->sole();
        $sale = $this->sale($line)->assertCreated()->json('data');
        $this->assertSame($item->id, $sale['items'][0]['inventory_item_id']);
        $this->assertSame('35', $this->balance('eggs')['available']['quantity']);
        $movement = InventoryMovement::where('sale_id', $sale['id'])->sole();
        $this->assertSame(['sale', $item->id], [$movement->reason, $movement->inventory_item_id]);
        $this->assertSame('crop_sales', Sale::findOrFail($sale['id'])->category->code); // produce, like the id-based line

        $this->sale([$this->line(['output' => 'eggs'], '100', 'piece', '1.00')])->assertStatus(409)->assertJsonPath('code', 'insufficient_stock'); // normal domain behaviour
        $this->assertSame('35', $this->balance('eggs')['available']['quantity']);
        $this->assertSame(1, Sale::count());

        $this->postJson('/api/v1/sales/'.$sale['id'].'/cancel', ['reason' => 'Buyer cancelled', 'recorded_at' => $this->at(0), 'idempotency_key' => $this->key()])->assertOk();
        $this->assertSame('40', $this->balance('eggs')['available']['quantity']);
    }

    public function test_sale_line_by_output_is_idempotent_and_needs_a_store_choice_when_several_are_active(): void
    {
        $this->eggsRecord($this->livestock(), 40)->assertCreated();
        $payload = ['recorded_at' => $this->at(3), 'idempotency_key' => $this->key(), 'items' => [$this->line(['output' => 'eggs'], '5', 'piece', '1500.00')]];
        $first = $this->postJson('/api/v1/sales', $payload)->assertCreated()->json('data.id');
        $this->assertSame($first, $this->postJson('/api/v1/sales', $payload)->assertCreated()->json('data.id'));
        $this->assertSame('35', $this->balance('eggs')['available']['quantity']);

        $main = StorageLocation::sole();
        $this->postJson('/api/v1/storage-locations', ['name' => 'Cold room', 'type' => 'store'])->assertCreated();
        $this->sale([$this->line(['output' => 'eggs'], '1', 'piece', '300.00')])->assertUnprocessable()->assertJsonValidationErrors('items.0.storage_location_id');
        $this->sale([$this->line(['output' => 'eggs', 'storage_location_id' => $main->id], '1', 'piece', '300.00')])->assertCreated();
        $this->assertSame('34', $this->balance('eggs')['available']['quantity']);
    }

    public function test_exactly_one_semantic_stock_target_is_required_and_the_item_id_keeps_working(): void
    {
        $this->eggsRecord($this->livestock(), 40)->assertCreated();
        $balance = $this->balance('eggs');
        $store = StorageLocation::sole()->id;

        foreach (['sale', 'purchase'] as $kind) {
            $send = fn (array $target) => $this->{$kind}([$this->line($target, '1', 'piece', '100.00')]);
            $send([])->assertUnprocessable()->assertJsonValidationErrors('items.0.inventory_item_id');
            $send(['output' => 'eggs', 'inventory_item_id' => $balance['inventory_item_id'], 'storage_location_id' => $store])->assertUnprocessable()->assertJsonValidationErrors('items.0.output');
            $send(['output' => 'chickens'])->assertUnprocessable()->assertJsonValidationErrors('items.0.output');
            $send(['output' => ['eggs']])->assertUnprocessable();
            // Backwards compatible: the explicit item + store still works.
            $send(['inventory_item_id' => $balance['inventory_item_id'], 'storage_location_id' => $store])->assertCreated();
        }
        $this->assertSame('40', $this->balance('eggs')['available']['quantity']); // -1 sold, +1 bought

        $nonStock = ['kind' => 'non_stock', 'description' => 'Transport', 'output' => 'eggs', 'amount' => '100.00'];
        $this->purchase([$nonStock])->assertUnprocessable()->assertJsonValidationErrors('items.0.output');
        $this->sale([['kind' => 'other', 'description' => 'Crates', 'output' => 'eggs', 'amount' => '100.00']])->assertUnprocessable()->assertJsonValidationErrors('items.0.output');
        // id-based stock lines still need their store.
        $this->sale([$this->line(['inventory_item_id' => $balance['inventory_item_id']], '1', 'piece', '100.00')])->assertUnprocessable()->assertJsonValidationErrors('items.0.storage_location_id');
    }

    public function test_output_lines_are_tenant_scoped_and_respect_permissions(): void
    {
        $this->eggsRecord($this->livestock(), 40)->assertCreated();

        [$other] = $this->otherFarm();
        $this->signInAs($other);
        $this->sale([$this->line(['output' => 'eggs'], '1', 'piece', '100.00')])->assertStatus(409)->assertJsonPath('code', 'insufficient_stock'); // my eggs are not theirs
        $this->purchase([$this->line(['output' => 'eggs'], '4', 'piece', '100.00')])->assertCreated();
        $this->assertSame(2, InventoryItem::where('system_key', 'output:eggs')->count()); // each farm has its own item
        $this->assertSame('4', $this->balance('eggs')['available']['quantity']);
        $this->signInAs($this->owner);
        $this->assertSame('40', $this->balance('eggs')['available']['quantity']);

        $this->signInAs($this->member(FarmRole::Vet));
        $this->sale([$this->line(['output' => 'eggs'], '1', 'piece', '100.00')])->assertForbidden();
        $this->purchase([$this->line(['output' => 'eggs'], '1', 'piece', '100.00')])->assertForbidden();
    }
}
