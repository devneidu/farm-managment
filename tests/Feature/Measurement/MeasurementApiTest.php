<?php

namespace Tests\Feature\Measurement;

use App\Enums\FarmRole;
use App\Events\Measurement\PackageConversionChanged;
use App\Models\CropType;
use App\Models\FarmUnitPreference;
use App\Models\MeasurementContext;
use App\Models\PackageConversion;
use App\Services\Measurement\MeasurementConverter;
use App\Support\Measurement\Decimal;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

class MeasurementApiTest extends MeasurementTestCase
{
    private function unitCodes(string $dimension, string $query = ''): array
    {
        return collect($this->getJson("/api/v1/master/units?dimension={$dimension}{$query}")->assertOk()->json('data'))->pluck('code')->all();
    }

    // ---- selectors ---------------------------------------------------------------------------------------------

    public function test_dimensions_list_with_canonical_units(): void
    {
        $data = collect($this->signInAs($this->owner)->getJson('/api/v1/master/measurement-dimensions')->assertOk()->json('data'))->keyBy('code');

        $this->assertSame(['weight', 'volume', 'area', 'count', 'temperature', 'package'], $data->keys()->all());
        $this->assertSame('g', $data['weight']['canonical_unit']['code']);
        $this->assertSame('ml', $data['volume']['canonical_unit']['code']);
        $this->assertSame('sq_m', $data['area']['canonical_unit']['code']);
        $this->assertSame('celsius', $data['temperature']['canonical_unit']['code']);
        $this->assertNull($data['package']['canonical_unit']);
        $this->assertTrue($data['weight']['supports_preference']);
        $this->assertFalse($data['count']['supports_preference']);
        $this->assertSame(5, $data['weight']['unit_count']);
    }

    public function test_units_are_only_ever_returned_for_the_requested_dimension(): void
    {
        $this->signInAs($this->owner);

        // Water -> volume: no kg, tonne, lb, acre, crate, bag, head
        $this->assertSame(['ml', 'cl', 'l'], $this->unitCodes('volume'));
        // Live weight -> weight
        $this->assertSame(['mg', 'g', 'kg', 'tonne', 'lb'], $this->unitCodes('weight'));
        // Land area -> area
        $this->assertSame(['sq_m', 'hectare', 'acre'], $this->unitCodes('area'));
        $this->assertSame(['celsius', 'fahrenheit'], $this->unitCodes('temperature'));
        $this->assertEqualsCanonicalizing(['bag', 'sack', 'crate', 'tray', 'carton', 'bottle'], $this->unitCodes('package'));

        foreach ($this->getJson('/api/v1/master/units?dimension=volume')->json('data') as $unit) {
            $this->assertSame('volume', $unit['dimension']);
        }
    }

    public function test_count_units_can_be_narrowed_by_family(): void
    {
        $this->signInAs($this->owner);

        $this->assertEqualsCanonicalizing(['piece', 'egg', 'head', 'planting_unit'], $this->unitCodes('count'));
        $this->assertSame(['head'], $this->unitCodes('count', '&family=head'));
        $this->assertTrue($this->getJson('/api/v1/master/units?dimension=count&family=head')->json('data.0.integer_only'));
    }

    public function test_dimension_is_required_and_validated(): void
    {
        $this->signInAs($this->owner);

        $this->getJson('/api/v1/master/units')->assertStatus(422)->assertJsonValidationErrors('dimension');
        $this->getJson('/api/v1/master/units?dimension=furlongs')->assertStatus(422)->assertJsonValidationErrors('dimension');
    }

    public function test_inactive_units_are_hidden_from_selectors_unless_requested(): void
    {
        $this->unit('lb')->update(['is_active' => false]);
        $this->signInAs($this->owner);

        $this->assertNotContains('lb', $this->unitCodes('weight'));
        $this->assertContains('lb', $this->unitCodes('weight', '&include_inactive=true'));
        $this->assertSame(4, collect($this->getJson('/api/v1/master/measurement-dimensions')->json('data'))->firstWhere('code', 'weight')['unit_count']);
    }

    public function test_unit_output_shape_is_frontend_friendly_without_internals(): void
    {
        $unit = $this->signInAs($this->owner)->getJson('/api/v1/master/units?dimension=area')->json('data.1');

        $this->assertSame(['id', 'code', 'name', 'symbol', 'dimension', 'family', 'is_canonical', 'integer_only', 'decimal_places', 'is_active', 'source'], array_keys($unit));
        $this->assertSame('hectare', $unit['code']);
        $this->assertSame('ha', $unit['symbol']);
        $this->assertSame('system', $unit['source']);
    }

    // ---- RBAC ----------------------------------------------------------------------------------------------------

    public function test_every_role_can_read_but_only_owner_and_manager_can_manage(): void
    {
        $this->conversion($this->farm, 'Eggs', 'crate', 'piece', 30);
        $body = ['context_type' => 'custom', 'context_id' => $this->measurementContext($this->farm, 'Feed')->id, 'package_unit' => 'bag', 'target_unit' => 'kg', 'quantity_per_package' => '25'];

        foreach ([FarmRole::FarmWorker, FarmRole::Finance] as $role) {
            $this->signInAs($this->member($role));
            $this->getJson('/api/v1/master/units?dimension=volume')->assertOk();
            $this->getJson('/api/v1/master/measurement-dimensions')->assertOk();
            $this->getJson('/api/v1/settings/units')->assertOk();
            $this->getJson('/api/v1/settings/package-conversions')->assertOk()->assertJsonCount(1, 'data');
            $this->postJson('/api/v1/measurements/normalize', ['components' => $this->parts([[2, 'crate']]), 'context' => $this->ctx('Eggs')])->assertOk();

            $this->putJson('/api/v1/settings/units', ['preferences' => ['weight' => 'g']])->assertForbidden();
            $this->postJson('/api/v1/settings/package-conversions', $body)->assertForbidden();
            $this->patchJson('/api/v1/settings/package-conversions/'.PackageConversion::first()->id, ['is_active' => false])->assertForbidden();

            $this->getJson('/api/v1/settings/measurement-contexts')->assertOk();
            $this->postJson('/api/v1/settings/measurement-contexts', ['name' => 'Layer Mash'])->assertForbidden();
            $this->patchJson('/api/v1/settings/measurement-contexts/'.$body['context_id'], ['name' => 'Renamed'])->assertForbidden();
        }

        $this->signInAs($this->member(FarmRole::Manager))->postJson('/api/v1/settings/package-conversions', $body)->assertCreated();
        $this->signInAs($this->owner)->putJson('/api/v1/settings/units', ['preferences' => ['weight' => 'g']])->assertOk();
    }

    public function test_authentication_is_required(): void
    {
        $this->getJson('/api/v1/master/units?dimension=volume')->assertUnauthorized();
        $this->postJson('/api/v1/measurements/normalize', [])->assertUnauthorized();
    }

    public function test_measurement_is_not_plan_gated(): void
    {
        // the default Free plan has no measurement entitlement and still gets full measurement functionality
        $context = $this->signInAs($this->owner)->postJson('/api/v1/settings/measurement-contexts', ['name' => 'Eggs'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/settings/package-conversions', [
            'context_type' => 'custom', 'context_id' => $context, 'package_unit' => 'crate', 'target_unit' => 'piece', 'quantity_per_package' => 30,
        ])->assertCreated();
    }

    // ---- measurement contexts ------------------------------------------------------------------------------------

    public function test_a_measurement_context_has_a_generated_id_and_a_name_that_is_only_display(): void
    {
        $created = $this->signInAs($this->owner)->postJson('/api/v1/settings/measurement-contexts', ['name' => '  Feed   Grower Mash '])
            ->assertCreated()->assertJsonPath('data.name', 'Feed Grower Mash')->assertJsonPath('data.is_active', true);
        $id = $created->json('data.id');
        $this->assertTrue(Str::isUuid($id));
        $this->assertSame($this->farm->id, MeasurementContext::findOrFail($id)->farm_id);

        $this->postJson('/api/v1/settings/measurement-contexts', ['name' => 'feed grower  MASH'])
            ->assertStatus(409)->assertJsonPath('code', 'measurement_context_exists')->assertJsonPath('details.existing_id', $id);

        foreach ([['name' => '<b>'], ['name' => 'x'], ['name' => null], [], ['name' => 'Ok', 'farm_id' => fake()->uuid()], ['name' => 'Ok', 'id' => fake()->uuid()]] as $bad) {
            $this->postJson('/api/v1/settings/measurement-contexts', $bad)->assertStatus(422);
        }
        $this->assertSame(1, MeasurementContext::count());
    }

    public function test_renaming_a_context_keeps_conversions_working_and_changes_only_the_label(): void
    {
        $conversion = $this->conversion($this->farm, 'Feed Grower Mash', 'bag', 'kg', 25);
        $contextId = $conversion->context_id;
        $this->signInAs($this->owner);

        $before = $this->postJson('/api/v1/measurements/normalize', ['components' => $this->parts([[2, 'bag']]), 'context' => ['type' => 'custom', 'id' => $contextId]])
            ->assertOk()->assertJsonPath('data.total.quantity', '50')->json('data.snapshot');

        $this->patchJson("/api/v1/settings/measurement-contexts/{$contextId}", ['name' => 'Grower Mash (25 kg)'])
            ->assertOk()->assertJsonPath('data.id', $contextId)->assertJsonPath('data.name', 'Grower Mash (25 kg)');

        // the conversion is still attached to the same identity, version untouched, new label shown
        $this->getJson('/api/v1/settings/package-conversions')->assertOk()
            ->assertJsonPath('data.0.id', $conversion->id)->assertJsonPath('data.0.version', 1)
            ->assertJsonPath('data.0.context', ['type' => 'custom', 'id' => $contextId, 'label' => 'Grower Mash (25 kg)']);
        $this->postJson('/api/v1/measurements/normalize', ['components' => $this->parts([[2, 'bag']]), 'context' => ['type' => 'custom', 'id' => $contextId]])
            ->assertOk()->assertJsonPath('data.total.quantity', '50')->assertJsonPath('data.snapshot.packages.0.context.label', 'Grower Mash (25 kg)');

        // the snapshot taken earlier is unchanged history and still replays
        $this->assertSame('Feed Grower Mash', $before['packages'][0]['context']['label']);
        $this->assertSame('50', app(MeasurementConverter::class)->replay($before)->total->value);

        // the old name is free again; the new name is taken
        $this->postJson('/api/v1/settings/measurement-contexts', ['name' => 'Feed Grower Mash'])->assertCreated();
        $this->postJson('/api/v1/settings/measurement-contexts', ['name' => 'grower mash (25 kg)'])->assertStatus(409);
    }

    public function test_deactivating_a_context_blocks_new_use_but_not_history(): void
    {
        $conversion = $this->conversion($this->farm, 'Eggs', 'crate', 'piece', 30);
        $this->signInAs($this->owner);
        $ctx = ['type' => 'custom', 'id' => $conversion->context_id];

        $snapshot = $this->postJson('/api/v1/measurements/normalize', ['components' => $this->parts([[3, 'crate']]), 'context' => $ctx])->assertOk()->json('data.snapshot');

        $this->patchJson("/api/v1/settings/measurement-contexts/{$conversion->context_id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson('/api/v1/settings/measurement-contexts')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/settings/measurement-contexts?include_inactive=true')->assertJsonCount(1, 'data');

        $this->postJson('/api/v1/measurements/normalize', ['components' => $this->parts([[3, 'crate']]), 'context' => $ctx])->assertStatus(422)->assertJsonValidationErrors('context.id');
        $this->postJson('/api/v1/settings/package-conversions', [
            'context_type' => 'custom', 'context_id' => $conversion->context_id, 'package_unit' => 'tray', 'target_unit' => 'piece', 'quantity_per_package' => 30,
        ])->assertStatus(422)->assertJsonValidationErrors('context_id');
        $this->assertSame('90', app(MeasurementConverter::class)->replay($snapshot)->total->value);
    }

    public function test_measurement_contexts_are_farm_scoped(): void
    {
        [$otherOwner, $otherFarm] = $this->otherFarm();
        $foreign = $this->measurementContext($otherFarm, 'Eggs');
        $mine = $this->measurementContext($this->farm, 'Eggs'); // the same name in two farms is fine
        $this->conversion($otherFarm, 'Eggs', 'crate', 'piece', 12);

        $this->signInAs($this->owner);
        $this->assertSame([$mine->id], collect($this->getJson('/api/v1/settings/measurement-contexts')->assertOk()->json('data'))->pluck('id')->all());
        $this->patchJson("/api/v1/settings/measurement-contexts/{$foreign->id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->assertSame('Eggs', $foreign->fresh()->name);

        // farm A can neither define a conversion on, nor normalize with, farm B's context id
        $this->postJson('/api/v1/settings/package-conversions', [
            'context_type' => 'custom', 'context_id' => $foreign->id, 'package_unit' => 'crate', 'target_unit' => 'piece', 'quantity_per_package' => 30,
        ])->assertStatus(422)->assertJsonValidationErrors('context_id');
        $this->postJson('/api/v1/measurements/normalize', ['components' => $this->parts([[1, 'crate']]), 'context' => ['type' => 'custom', 'id' => $foreign->id]])
            ->assertStatus(422)->assertJsonValidationErrors('context.id');
        $this->assertSame(1, PackageConversion::count());

        $this->signInAs($otherOwner)->postJson('/api/v1/measurements/normalize', ['components' => $this->parts([[1, 'crate']]), 'context' => ['type' => 'custom', 'id' => $foreign->id]])
            ->assertOk()->assertJsonPath('data.total.quantity', '12');
    }

    // ---- package conversions -------------------------------------------------------------------------------------

    public function test_create_a_custom_context_conversion(): void
    {
        Event::fake([PackageConversionChanged::class]);

        $contextId = $this->signInAs($this->owner)->postJson('/api/v1/settings/measurement-contexts', ['name' => 'Feed Grower Mash'])->assertCreated()->json('data.id');

        $response = $this->postJson('/api/v1/settings/package-conversions', [
            'context_type' => 'custom', 'context_id' => $contextId, 'package_unit' => 'bag', 'target_unit' => 'kg', 'quantity_per_package' => '25',
        ])->assertCreated()
            ->assertJsonPath('data.context', ['type' => 'custom', 'id' => $contextId, 'label' => 'Feed Grower Mash'])
            ->assertJsonPath('data.package_unit.code', 'bag')
            ->assertJsonPath('data.target_unit.code', 'kg')
            ->assertJsonPath('data.target_unit.dimension', 'weight')
            ->assertJsonPath('data.quantity_per_package', '25')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.is_active', true);

        $this->assertSame($this->farm->id, PackageConversion::findOrFail($response->json('data.id'))->farm_id);
        Event::assertDispatched(PackageConversionChanged::class, fn ($e) => $e->action === 'created');
    }

    public function test_create_a_crop_context_conversion_for_maize(): void
    {
        $maize = CropType::where('code', 'maize')->firstOrFail();

        $this->signInAs($this->owner)->postJson('/api/v1/settings/package-conversions', [
            'context_type' => 'crop_type', 'context_id' => $maize->id, 'package_unit' => 'bag', 'target_unit' => 'kg', 'quantity_per_package' => '50',
        ])->assertCreated()->assertJsonPath('data.context', ['type' => 'crop_type', 'id' => $maize->id, 'label' => $maize->name]);

        $this->postJson('/api/v1/measurements/normalize', [
            'components' => $this->parts([[12, 'bag'], [18, 'kg']]), 'context' => ['type' => 'crop_type', 'id' => $maize->id],
        ])->assertOk()->assertJsonPath('data.total', ['quantity' => '618', 'unit' => 'kg']);

        $body = ['context_type' => 'crop_type', 'package_unit' => 'bag', 'target_unit' => 'kg', 'quantity_per_package' => '50'];
        // an id that is not a crop, a malformed id, another kind of entity's id, and an inactive crop are all refused
        foreach ([fake()->uuid(), 'maize', $this->measurementContext($this->farm, 'Some context')->id] as $bad) {
            $this->postJson('/api/v1/settings/package-conversions', ['context_id' => $bad] + $body)->assertStatus(422)->assertJsonValidationErrors('context_id');
        }
        $yam = CropType::where('code', 'yam')->firstOrFail();
        CropType::whereKey($yam->id)->update(['is_active' => false]);
        $this->postJson('/api/v1/settings/package-conversions', ['context_id' => $yam->id] + $body)->assertStatus(422)->assertJsonValidationErrors('context_id');
        $this->assertSame(1, PackageConversion::count());
    }

    public function test_the_context_type_must_match_what_the_id_refers_to(): void
    {
        $maize = CropType::where('code', 'maize')->firstOrFail();
        $custom = $this->measurementContext($this->farm, 'Eggs');
        $this->signInAs($this->owner);
        $body = ['package_unit' => 'crate', 'target_unit' => 'piece', 'quantity_per_package' => '30'];

        $this->postJson('/api/v1/settings/package-conversions', ['context_type' => 'custom', 'context_id' => $maize->id] + $body)->assertStatus(422)->assertJsonValidationErrors('context_id');
        $this->postJson('/api/v1/settings/package-conversions', ['context_type' => 'crop_type', 'context_id' => $custom->id] + $body)->assertStatus(422)->assertJsonValidationErrors('context_id');
        $this->postJson('/api/v1/settings/package-conversions', ['context_type' => 'custom'] + $body)->assertStatus(422)->assertJsonValidationErrors('context_id');
        $this->assertSame(0, PackageConversion::count());
    }

    public function test_conversion_ratio_is_validated_strictly(): void
    {
        $this->signInAs($this->owner);
        $base = ['context_type' => 'custom', 'context_id' => $this->measurementContext($this->farm, 'Eggs')->id, 'package_unit' => 'crate', 'target_unit' => 'piece'];

        foreach (['0', '-5', '0.0', 'abc', '1e3', '30.1234567'] as $bad) {
            $this->postJson('/api/v1/settings/package-conversions', $base + ['quantity_per_package' => $bad])
                ->assertStatus(422)->assertJsonPath('code', 'invalid_conversion_ratio');
        }
        // a crate holds a whole number of pieces
        $this->postJson('/api/v1/settings/package-conversions', $base + ['quantity_per_package' => '30.5'])->assertStatus(422)->assertJsonPath('code', 'invalid_conversion_ratio');
        $this->postJson('/api/v1/settings/package-conversions', $base + ['quantity_per_package' => ['x']])->assertStatus(422)->assertJsonValidationErrors('quantity_per_package');
        $this->assertSame(0, PackageConversion::count());
    }

    public function test_units_and_shape_are_validated_on_create(): void
    {
        $this->signInAs($this->owner);
        $base = ['context_type' => 'custom', 'context_id' => $this->measurementContext($this->farm, 'Eggs')->id, 'package_unit' => 'crate', 'target_unit' => 'piece', 'quantity_per_package' => 30];

        $this->postJson('/api/v1/settings/package-conversions', ['package_unit' => 'kg'] + $base)->assertStatus(422)->assertJsonPath('code', 'unit_dimension_mismatch');
        $this->postJson('/api/v1/settings/package-conversions', ['target_unit' => 'bag'] + $base)->assertStatus(422)->assertJsonPath('code', 'incompatible_units');
        $this->postJson('/api/v1/settings/package-conversions', ['target_unit' => 'furlong'] + $base)->assertStatus(422)->assertJsonPath('code', 'unknown_unit');
        $this->postJson('/api/v1/settings/package-conversions', ['context_type' => 'bogus'] + $base)->assertStatus(422)->assertJsonValidationErrors('context_type');
        // inventory_item is a valid type since Phase 9, but only for an active inventory item of THIS farm
        $this->postJson('/api/v1/settings/package-conversions', ['context_type' => 'inventory_item'] + $base)->assertStatus(422)->assertJsonValidationErrors('context_id');
        // a context can no longer be conjured from typed text on a conversion: only an id is accepted
        $this->postJson('/api/v1/settings/package-conversions', $base + ['context_label' => 'Brand new'])->assertStatus(422)->assertJsonValidationErrors('context_label');
        $this->postJson('/api/v1/settings/package-conversions', ['context_id' => null] + $base)->assertStatus(422)->assertJsonValidationErrors('context_id');
        $this->postJson('/api/v1/settings/package-conversions', ['context_id' => 'eggs'] + $base)->assertStatus(422)->assertJsonValidationErrors('context_id');
        $this->postJson('/api/v1/settings/package-conversions', ['context_id' => fake()->uuid()] + $base)->assertStatus(422)->assertJsonValidationErrors('context_id');
        $this->postJson('/api/v1/settings/package-conversions', $base + ['crop_type_id' => fake()->uuid()])->assertStatus(422)->assertJsonValidationErrors('crop_type_id');
        $this->assertSame(1, MeasurementContext::count()); // only the fixture context exists: nothing was auto-created
        $this->postJson('/api/v1/settings/package-conversions', $base + ['farm_id' => fake()->uuid()])->assertStatus(422)->assertJsonValidationErrors('farm_id');

        $this->unit('sack')->update(['is_active' => false]);
        $this->postJson('/api/v1/settings/package-conversions', ['package_unit' => 'sack'] + $base)->assertStatus(422)->assertJsonPath('code', 'unit_not_selectable');
        $this->assertSame(0, PackageConversion::count());
    }

    public function test_a_second_definition_for_the_same_context_and_package_conflicts(): void
    {
        $existing = $this->conversion($this->farm, 'Eggs', 'crate', 'piece', 30);
        $this->signInAs($this->owner);
        $body = ['context_type' => 'custom', 'context_id' => $existing->context_id, 'package_unit' => 'crate', 'target_unit' => 'piece', 'quantity_per_package' => 12];

        $this->postJson('/api/v1/settings/package-conversions', $body)
            ->assertStatus(409)->assertJsonPath('code', 'conversion_exists')->assertJsonPath('details.existing_id', $existing->id);

        // a different package in the same context is fine (crate and tray of eggs)
        $this->postJson('/api/v1/settings/package-conversions', ['package_unit' => 'tray'] + $body)->assertCreated();
        $this->assertSame(2, PackageConversion::count());
    }

    public function test_update_versions_the_definition_and_can_deactivate_and_reactivate(): void
    {
        Event::fake([PackageConversionChanged::class]);
        $conversion = $this->conversion($this->farm, 'Feed', 'bag', 'kg', 25);
        $this->signInAs($this->owner);

        $this->patchJson("/api/v1/settings/package-conversions/{$conversion->id}", ['quantity_per_package' => '20'])
            ->assertOk()->assertJsonPath('data.quantity_per_package', '20')->assertJsonPath('data.version', 2);

        // the label belongs to the context, not the conversion: it cannot be edited here
        $this->patchJson("/api/v1/settings/package-conversions/{$conversion->id}", ['context_label' => 'Feed (grower)'])->assertStatus(422)->assertJsonValidationErrors('context_label');

        // an identical value is not a change
        $this->patchJson("/api/v1/settings/package-conversions/{$conversion->id}", ['quantity_per_package' => '20.000'])->assertOk()->assertJsonPath('data.version', 2);

        $this->patchJson("/api/v1/settings/package-conversions/{$conversion->id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.version', 3);
        $this->getJson('/api/v1/settings/package-conversions')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/settings/package-conversions?include_inactive=true')->assertJsonCount(1, 'data');

        $this->patchJson("/api/v1/settings/package-conversions/{$conversion->id}", ['is_active' => true])->assertOk()->assertJsonPath('data.version', 4);

        Event::assertDispatched(PackageConversionChanged::class, fn ($e) => $e->action === 'deactivated');
        Event::assertDispatched(PackageConversionChanged::class, fn ($e) => $e->action === 'reactivated');
    }

    public function test_update_cannot_change_what_a_definition_is_about_and_revalidates_the_ratio(): void
    {
        $eggs = $this->conversion($this->farm, 'Eggs', 'crate', 'piece', 30);
        $this->signInAs($this->owner);

        foreach (['package_unit' => 'bag', 'context_type' => 'custom', 'context_id' => fake()->uuid(), 'crop_type_id' => fake()->uuid(), 'farm_id' => fake()->uuid()] as $field => $value) {
            $this->patchJson("/api/v1/settings/package-conversions/{$eggs->id}", [$field => $value])->assertStatus(422)->assertJsonValidationErrors($field);
        }

        $this->patchJson("/api/v1/settings/package-conversions/{$eggs->id}", ['quantity_per_package' => '0'])->assertStatus(422)->assertJsonPath('code', 'invalid_conversion_ratio');
        $this->patchJson("/api/v1/settings/package-conversions/{$eggs->id}", ['quantity_per_package' => '30.5'])->assertStatus(422)->assertJsonPath('code', 'invalid_conversion_ratio');
        // switching the target from pieces to kg makes a fraction legal again
        $this->patchJson("/api/v1/settings/package-conversions/{$eggs->id}", ['target_unit' => 'kg', 'quantity_per_package' => '1.5'])->assertOk()->assertJsonPath('data.target_unit.code', 'kg');
        // ...but switching back to pieces with the fractional ratio is refused
        $this->patchJson("/api/v1/settings/package-conversions/{$eggs->id}", ['target_unit' => 'piece'])->assertStatus(422)->assertJsonPath('code', 'invalid_conversion_ratio');
    }

    public function test_farm_isolation_for_listing_updating_and_normalizing(): void
    {
        [$otherOwner, $otherFarm] = $this->otherFarm();
        $foreign = $this->conversion($otherFarm, 'Eggs', 'crate', 'piece', 12);
        $mine = $this->conversion($this->farm, 'Eggs', 'crate', 'piece', 30);

        $this->signInAs($this->owner);
        $ids = collect($this->getJson('/api/v1/settings/package-conversions')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$mine->id], $ids);

        $this->patchJson("/api/v1/settings/package-conversions/{$foreign->id}", ['quantity_per_package' => 1])->assertNotFound();
        $this->assertSame('12', Decimal::trim((string) $foreign->fresh()->quantity_per_package));

        $this->postJson('/api/v1/measurements/normalize', ['components' => $this->parts([[1, 'crate']]), 'context' => $this->ctx('Eggs')])
            ->assertOk()->assertJsonPath('data.total.quantity', '30');
        $this->signInAs($otherOwner)->postJson('/api/v1/measurements/normalize', ['components' => $this->parts([[1, 'crate']]), 'context' => $this->ctx('Eggs', $otherFarm)])
            ->assertOk()->assertJsonPath('data.total.quantity', '12');

        // the client cannot choose the farm, not even with a header for a farm it does not belong to
        $this->signInAs($this->owner)->withHeader('X-Farm-Id', $otherFarm->id)->getJson('/api/v1/settings/package-conversions')->assertForbidden();
    }

    public function test_list_filters(): void
    {
        $this->conversion($this->farm, 'Eggs', 'crate', 'piece', 30);
        $this->conversion($this->farm, 'Eggs', 'tray', 'piece', 30);
        $this->conversion($this->farm, 'Maize', 'bag', 'kg', 50);
        $this->signInAs($this->owner);

        $this->getJson('/api/v1/settings/package-conversions?context_id='.$this->context('Eggs')->id)->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/settings/package-conversions?package_unit=bag')->assertJsonCount(1, 'data')->assertJsonPath('data.0.context.label', 'Maize');
        $this->getJson('/api/v1/settings/package-conversions?context_type=crop_type')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/settings/package-conversions?context_type=nope')->assertStatus(422);
    }

    // ---- normalize preview ---------------------------------------------------------------------------------------

    public function test_normalize_endpoint_returns_entered_normalized_total_and_snapshot(): void
    {
        $this->conversion($this->farm, 'Eggs', 'crate', 'piece', 30);

        $response = $this->signInAs($this->owner)->postJson('/api/v1/measurements/normalize', [
            'components' => $this->parts([[3, 'crate'], [14, 'piece']]), 'context' => $this->ctx('Eggs'),
        ])->assertOk()
            ->assertJsonPath('data.entered', [['quantity' => '3', 'unit' => 'crate'], ['quantity' => '14', 'unit' => 'piece']])
            ->assertJsonPath('data.normalized', ['quantity' => '104', 'unit' => 'piece'])
            ->assertJsonPath('data.total', ['quantity' => '104', 'unit' => 'piece'])
            ->assertJsonPath('data.snapshot.packages.0.per_package', '30');

        $this->assertSame(1, $response->json('data.snapshot.schema'));
    }

    public function test_normalize_plain_standard_conversion_and_errors_have_codes(): void
    {
        $this->signInAs($this->owner);

        $this->postJson('/api/v1/measurements/normalize', ['components' => $this->parts([[1500, 'g']]), 'result_unit' => 'kg'])
            ->assertOk()->assertJsonPath('data.total', ['quantity' => '1.5', 'unit' => 'kg'])->assertJsonPath('data.normalized', ['quantity' => '1500', 'unit' => 'g']);
        $this->postJson('/api/v1/measurements/normalize', ['components' => $this->parts([[100, 'celsius']]), 'result_unit' => 'fahrenheit'])
            ->assertOk()->assertJsonPath('data.total.quantity', '212');

        $this->postJson('/api/v1/measurements/normalize', ['components' => $this->parts([[5, 'kg']]), 'result_unit' => 'l'])
            ->assertStatus(422)->assertJsonPath('code', 'incompatible_units')->assertJsonPath('details.from_unit', 'kg')->assertJsonPath('details.to_unit', 'l');
        $this->postJson('/api/v1/measurements/normalize', ['components' => $this->parts([[2, 'bag']])])
            ->assertStatus(422)->assertJsonPath('code', 'conversion_context_required');
        $this->measurementContext($this->farm, 'Maize');
        $this->postJson('/api/v1/measurements/normalize', ['components' => $this->parts([[2, 'bag']]), 'context' => $this->ctx('Maize')])
            ->assertStatus(422)->assertJsonPath('code', 'conversion_not_configured')->assertJsonPath('details.unit', 'bag');
        $this->postJson('/api/v1/measurements/normalize', ['components' => $this->parts([['2.5', 'head']])])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_quantity');
        $this->postJson('/api/v1/measurements/normalize', ['components' => $this->parts([[5, 'nope']])])->assertStatus(422)->assertJsonPath('code', 'unknown_unit');
        $this->postJson('/api/v1/measurements/normalize', ['components' => []])->assertStatus(422)->assertJsonValidationErrors('components');
        $this->postJson('/api/v1/measurements/normalize', ['components' => $this->parts([[5, 'kg']]), 'farm_id' => fake()->uuid()])->assertStatus(422)->assertJsonValidationErrors('farm_id');
    }

    // ---- unit preferences ----------------------------------------------------------------------------------------

    public function test_preferences_default_to_nigeria_first_units(): void
    {
        $data = collect($this->signInAs($this->owner)->getJson('/api/v1/settings/units')->assertOk()->json('data.preferences'))->keyBy('dimension.code');

        $this->assertSame(['weight', 'volume', 'area', 'temperature'], $data->keys()->all());
        $this->assertSame('kg', $data['weight']['unit']['code']);
        $this->assertSame('l', $data['volume']['unit']['code']);
        $this->assertSame('hectare', $data['area']['unit']['code']);
        $this->assertSame('celsius', $data['temperature']['unit']['code']);
        $this->assertTrue($data['weight']['is_default']);
    }

    public function test_preferences_accept_units_of_their_own_dimension_and_are_farm_scoped(): void
    {
        [$otherOwner] = $this->otherFarm();

        $this->signInAs($this->owner)->putJson('/api/v1/settings/units', ['preferences' => ['weight' => 'lb', 'area' => 'acre']])
            ->assertOk()
            ->assertJsonPath('data.preferences.0.unit.code', 'lb')->assertJsonPath('data.preferences.0.is_default', false)
            ->assertJsonPath('data.preferences.1.unit.code', 'l')->assertJsonPath('data.preferences.1.is_default', true)
            ->assertJsonPath('data.preferences.2.unit.code', 'acre');

        $this->signInAs($otherOwner)->getJson('/api/v1/settings/units')->assertJsonPath('data.preferences.0.unit.code', 'kg');
        $this->assertSame(2, FarmUnitPreference::where('farm_id', $this->farm->id)->count());

        // partial change keeps the rest; null resets to the default
        $this->signInAs($this->owner)->putJson('/api/v1/settings/units', ['preferences' => ['weight' => null]])->assertOk()
            ->assertJsonPath('data.preferences.0.unit.code', 'kg')->assertJsonPath('data.preferences.0.is_default', true)
            ->assertJsonPath('data.preferences.2.unit.code', 'acre');
    }

    public function test_preferences_reject_wrong_dimension_unknown_inactive_and_unsupported(): void
    {
        $this->signInAs($this->owner);

        $this->putJson('/api/v1/settings/units', ['preferences' => ['weight' => 'l']])
            ->assertStatus(422)->assertJsonPath('code', 'unit_dimension_mismatch')->assertJsonPath('details.required_dimension', 'weight');
        $this->putJson('/api/v1/settings/units', ['preferences' => ['volume' => 'kg']])->assertStatus(422)->assertJsonPath('code', 'unit_dimension_mismatch');
        $this->putJson('/api/v1/settings/units', ['preferences' => ['weight' => 'crate']])->assertStatus(422)->assertJsonPath('code', 'unit_dimension_mismatch');
        $this->putJson('/api/v1/settings/units', ['preferences' => ['weight' => 'stone']])->assertStatus(422)->assertJsonPath('code', 'unknown_unit');
        $this->putJson('/api/v1/settings/units', ['preferences' => ['count' => 'head']])->assertStatus(422)->assertJsonValidationErrors('preferences.count');
        $this->putJson('/api/v1/settings/units', ['preferences' => []])->assertStatus(422);
        $this->putJson('/api/v1/settings/units', ['preferences' => ['weight' => 'g'], 'farm_id' => fake()->uuid()])->assertStatus(422)->assertJsonValidationErrors('farm_id');

        $this->unit('tonne')->update(['is_active' => false]);
        $this->putJson('/api/v1/settings/units', ['preferences' => ['weight' => 'tonne']])->assertStatus(422)->assertJsonPath('code', 'unit_not_selectable');
        $this->assertSame(0, FarmUnitPreference::count());
    }

    public function test_preferences_do_not_change_normalization(): void
    {
        $this->signInAs($this->owner)->putJson('/api/v1/settings/units', ['preferences' => ['weight' => 'lb']])->assertOk();

        $this->postJson('/api/v1/measurements/normalize', ['components' => $this->parts([[2, 'kg']])])
            ->assertOk()->assertJsonPath('data.normalized', ['quantity' => '2000', 'unit' => 'g'])->assertJsonPath('data.total.unit', 'kg');
    }

    public function test_standard_units_have_no_write_endpoints(): void
    {
        $this->signInAs($this->owner);

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->json($method, '/api/v1/master/units', ['code' => 'x'])->assertStatus(405);
        }
        $this->patchJson('/api/v1/master/units/'.$this->unit('kg')->id, ['to_canonical_factor' => 1])->assertNotFound();
    }
}
