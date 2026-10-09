<?php

namespace Tests\Feature\Production;

use App\Enums\FarmRole;
use App\Models\Breed;
use App\Models\Contact;
use App\Models\CropType;
use App\Models\PopulationMovement;
use App\Models\ProductionCycle;
use App\Models\ReferenceValue;
use App\Models\Species;
use Database\Seeders\LivestockBatchReferenceSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Team\TeamTestCase;

class LivestockBatchCreationTest extends TeamTestCase
{
    private const URL = '/api/v1/production-cycles';

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAs($this->owner);
    }

    private function payload(string $speciesCode = 'chicken', array $extra = []): array
    {
        $species = Species::where('code', $speciesCode)->firstOrFail();

        return array_replace([
            'kind' => 'livestock', 'name' => 'Batch '.Str::random(8), 'operation_type_id' => $species->operation_type_id,
            'species_id' => $species->id, 'production_purpose' => $speciesCode === 'honeybee' ? 'honey' : 'breeding',
            'initial_population' => 50, 'start_date' => '2026-01-01',
        ], $extra);
    }

    private function contact(array $extra = []): Contact
    {
        return Contact::create(array_replace([
            'farm_id' => $this->farm->id, 'name' => 'Supplier '.Str::random(8), 'kind' => 'person',
            'is_supplier' => true, 'is_customer' => false, 'is_active' => true, 'created_by' => $this->owner->id,
        ], $extra));
    }

    public function test_exact_catalogue_and_combined_reference_include_all_22_animals(): void
    {
        $this->assertCount(22, LivestockBatchReferenceSeeder::BREEDS);
        foreach (LivestockBatchReferenceSeeder::BREEDS as $code => $expected) {
            $species = Species::where('code', $code)->firstOrFail();
            $result = $this->getJson('/api/v1/master/species/'.$species->id.'/batch-reference')->assertOk()->json('data');
            $this->assertEqualsCanonicalizing($expected, array_column($result['breeds'], 'name'));
            $this->assertSame($code, $result['species']['code']);
            $this->assertSame(explode(', ', LivestockBatchReferenceSeeder::OPTIONS[$code][0]), array_column($result['purposes'], 'code'));
            $this->assertSame(explode(', ', LivestockBatchReferenceSeeder::OPTIONS[$code][1]), array_column($result['growth_stages'], 'code'));
            foreach ($result['breeds'] as $breed) {
                $this->assertSame('system', $breed['source']);
                $this->assertNotEmpty($breed['code']);
                $this->assertSame('7', $breed['id'][14]);
            }
            $this->assertSame(in_array($code, ['fish', 'snail', 'honeybee', 'grasscutter', 'camel', 'quail', 'ostrich'], true) ? 'Species / Type' : 'Breed / Strain', $result['breed_field_label']);
        }
    }

    public function test_reseeding_preserves_system_ids_edits_inactive_rows_and_custom_breeds(): void
    {
        $species = Species::where('code', 'chicken')->firstOrFail();
        $breed = Breed::system()->where('species_id', $species->id)->where('code', 'noiler')->firstOrFail();
        $breed->update(['name' => 'Edited Noiler', 'is_active' => false]);
        $custom = Breed::create(['species_id' => $species->id, 'farm_id' => $this->farm->id, 'name' => 'Noiler']);
        $purpose = ReferenceValue::where('list', 'livestock_purpose_chicken')->where('code', 'meat')->firstOrFail();
        $purpose->update(['name' => 'Table meat', 'is_active' => false]);
        $species->update(['breed_field_label' => 'Edited label']);
        $before = [Breed::count(), ReferenceValue::count()];
        (new LivestockBatchReferenceSeeder)->run();
        (new LivestockBatchReferenceSeeder)->run();
        $this->assertSame($before, [Breed::count(), ReferenceValue::count()]);
        $this->assertSame('Edited Noiler', $breed->fresh()->name);
        $this->assertFalse($breed->fresh()->is_active);
        $this->assertSame('Noiler', $custom->fresh()->name);
        $this->assertSame($this->farm->id, $custom->fresh()->farm_id);
        $this->assertSame('Table meat', $purpose->fresh()->name);
        $this->assertFalse($purpose->fresh()->is_active);
        $this->assertSame('Edited label', $species->fresh()->breed_field_label);
    }

    public function test_existing_uncoded_system_breed_is_reused_without_overwriting_or_reactivation(): void
    {
        $species = Species::where('code', 'chicken')->firstOrFail();
        $breed = Breed::system()->where('species_id', $species->id)->where('code', 'noiler')->firstOrFail();
        $breed->update(['code' => null, 'is_active' => false]);
        (new LivestockBatchReferenceSeeder)->run();
        $this->assertSame('noiler', $breed->fresh()->code);
        $this->assertFalse($breed->fresh()->is_active);
        $this->assertSame(1, Breed::system()->where('species_id', $species->id)->where('normalized_name', 'noiler')->count());
    }

    public function test_creation_persists_metadata_and_only_initial_population_side_effects(): void
    {
        $supplier = $this->contact();
        $breed = Breed::system()->whereHas('species', fn ($q) => $q->where('code', 'chicken'))->where('code', 'cobb_500')->firstOrFail();
        $cycle = $this->postJson(self::URL, $this->payload(extra: [
            'production_purpose' => 'meat', 'growth_stage' => 'chick', 'breed_id' => $breed->id,
            'acquisition_price_per_animal' => '1234.50', 'supplier_contact_id' => $supplier->id,
        ]))->assertCreated()->assertJsonPath('data.livestock.production_purpose.code', 'meat')
            ->assertJsonPath('data.livestock.growth_stage.code', 'chick')
            ->assertJsonPath('data.livestock.acquisition_price_per_animal', '1234.50')
            ->assertJsonPath('data.livestock.acquisition_currency', 'NGN')
            ->assertJsonPath('data.livestock.supplier_contact.id', $supplier->id)->json('data');
        $detail = ProductionCycle::findOrFail($cycle['id'])->livestock;
        $this->assertSame($supplier->id, $detail->supplier_contact_id);
        $this->assertSame('livestock_purpose_chicken', $detail->productionPurpose->list);
        $this->assertSame(50, PopulationMovement::sole()->quantity);
        $this->assertSame('2025-12-31 23:00:00', PopulationMovement::sole()->recorded_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('production_cycle_events', 1);
        foreach (['finance_transactions', 'purchases', 'payments', 'inventory_movements', 'operational_records'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        foreach (['', '/summary'] as $suffix) {
            $this->getJson(self::URL.'/'.$cycle['id'].$suffix)->assertOk()->assertJsonPath('data.livestock.acquisition_price_per_animal', '1234.50');
        }
        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.0.livestock.supplier_contact.id', $supplier->id);
        $this->patchJson(self::URL.'/'.$cycle['id'], ['growth_stage' => 'adult'])->assertConflict()->assertJsonPath('code', 'baseline_locked');
        $this->patchJson('/api/v1/contacts/'.$supplier->id, ['roles' => ['customer']])->assertConflict()->assertJsonPath('code', 'contact_in_use');
        $supplier->update(['is_active' => false]);
        $detail->productionPurpose->update(['is_active' => false]);
        $this->getJson(self::URL.'/'.$cycle['id'])->assertOk()->assertJsonPath('data.livestock.production_purpose.is_active', false)
            ->assertJsonPath('data.livestock.supplier_contact.is_active', false);
    }

    public function test_required_purpose_and_species_specific_validation_are_atomic(): void
    {
        $payload = $this->payload();
        unset($payload['production_purpose']);
        $this->postJson(self::URL, $payload)->assertUnprocessable()->assertJsonValidationErrors('production_purpose');
        foreach ([['production_purpose' => 'dairy'], ['growth_stage' => 'calf'], ['growth_stage' => 'pregnant'], ['production_purpose' => null]] as $invalid) {
            $this->postJson(self::URL, $this->payload(extra: $invalid))->assertUnprocessable()->assertJsonValidationErrors(array_key_first($invalid));
        }
        ReferenceValue::where('list', 'livestock_stage_chicken')->where('code', 'chick')->update(['is_active' => false]);
        $this->postJson(self::URL, $this->payload(extra: ['growth_stage' => 'chick']))->assertUnprocessable()->assertJsonValidationErrors('growth_stage');
        ReferenceValue::where('list', 'livestock_purpose_chicken')->where('code', 'breeding')->update(['is_active' => false]);
        $this->postJson(self::URL, $this->payload())->assertUnprocessable()->assertJsonValidationErrors('production_purpose');
        $this->assertDatabaseCount('production_cycles', 0);
        $this->assertDatabaseCount('population_movements', 0);
    }

    public function test_supplier_and_custom_breed_farm_isolation_and_reference_visibility(): void
    {
        [, $otherFarm] = $this->otherFarm();
        $supplier = $this->contact(['farm_id' => $otherFarm->id]);
        $this->postJson(self::URL, $this->payload(extra: ['supplier_contact_id' => $supplier->id]))->assertNotFound();
        foreach ([['is_active' => false], ['is_supplier' => false]] as $invalid) {
            $contact = $this->contact($invalid);
            $this->postJson(self::URL, $this->payload(extra: ['supplier_contact_id' => $contact->id]))->assertUnprocessable()->assertJsonValidationErrors('supplier_contact_id');
        }
        $species = Species::where('code', 'chicken')->firstOrFail();
        $theirs = Breed::create(['species_id' => $species->id, 'farm_id' => $otherFarm->id, 'name' => 'Their hybrid']);
        $own = Breed::create(['species_id' => $species->id, 'farm_id' => $this->farm->id, 'name' => 'Own hybrid']);
        $this->postJson(self::URL, $this->payload(extra: ['breed_id' => $theirs->id]))->assertNotFound();
        $this->postJson(self::URL, $this->payload(extra: ['breed_id' => $own->id]))->assertCreated();
        $ids = collect($this->getJson('/api/v1/master/species/'.$species->id.'/batch-reference')->assertOk()->json('data.breeds'))->pluck('id')->all();
        $this->assertContains($own->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    public static function prices(): array
    {
        return [['0', '0.00'], [0, '0.00'], ['42.1', '42.10'], [1250, '1250.00'], ['9999999999999999.99', '9999999999999999.99'], [null, null], [-1, false], ['1.001', false], ['1e3', false], [true, false], ['10000000000000000', false]];
    }

    #[DataProvider('prices')]
    public function test_exact_non_negative_price_validation(mixed $price, mixed $expected): void
    {
        $response = $this->postJson(self::URL, $this->payload(extra: ['acquisition_price_per_animal' => $price]));
        if ($expected === false) {
            $response->assertUnprocessable()->assertJsonValidationErrors('acquisition_price_per_animal');
            $this->assertDatabaseCount('population_movements', 0);
        } else {
            $response->assertCreated()->assertJsonPath('data.livestock.acquisition_price_per_animal', $expected);
        }
    }

    public function test_historical_null_values_remain_readable_and_optional_fields_remain_null(): void
    {
        $cycle = $this->postJson(self::URL, $this->payload())->assertCreated()
            ->assertJsonPath('data.livestock.growth_stage', null)->assertJsonPath('data.livestock.supplier_contact', null)
            ->assertJsonPath('data.livestock.acquisition_price_per_animal', null)->json('data.id');
        // Simulate a pre-migration batch: migration deliberately does not invent a purpose.
        DB::table('livestock_batch_details')->where('production_cycle_id', $cycle)->update(['production_purpose_id' => null]);
        $this->getJson(self::URL.'/'.$cycle)->assertOk()->assertJsonPath('data.livestock.production_purpose', null)->assertJsonPath('data.livestock.current_population', 50);
        $this->getJson(self::URL.'/'.$cycle.'/summary')->assertOk()->assertJsonPath('data.livestock.production_purpose', null);
    }

    public function test_fish_and_honeybee_have_specific_options_and_keep_population_units(): void
    {
        foreach ([['fish', 'table_fish', 'fingerling'], ['honeybee', 'honey', 'new_colony'], ['snail', 'meat', 'hatchling']] as [$species, $purpose, $stage]) {
            $this->postJson(self::URL, $this->payload($species, ['production_purpose' => $purpose, 'growth_stage' => $stage]))->assertCreated()
                ->assertJsonPath('data.livestock.population_unit', 'head')->assertJsonPath('data.livestock.current_population', 50);
        }
    }

    public function test_reference_inactive_filter_and_authorization(): void
    {
        $species = Species::where('code', 'chicken')->firstOrFail();
        ReferenceValue::where('list', 'livestock_stage_chicken')->where('code', 'chick')->update(['is_active' => false]);
        $url = '/api/v1/master/species/'.$species->id.'/batch-reference';
        $stages = array_column($this->getJson($url)->assertOk()->json('data.growth_stages'), 'code');
        $this->assertNotContains('chick', $stages);
        $this->assertContains('chick', array_column($this->getJson($url.'?include_inactive=true')->assertOk()->json('data.growth_stages'), 'code'));
        $this->signInAs($this->member(FarmRole::FarmWorker));
        $this->getJson($url)->assertOk();
        $this->postJson(self::URL, $this->payload())->assertForbidden();
        $this->getJson('/api/v1/master/species/'.Str::uuid().'/batch-reference')->assertNotFound();
    }

    public function test_rollback_refuses_to_discard_batch_metadata(): void
    {
        $this->postJson(self::URL, $this->payload())->assertCreated();
        $migration = require database_path('migrations/2026_10_25_100000_extend_livestock_batch_creation.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot roll back livestock batch creation');
        $migration->down();
    }

    public function test_livestock_metadata_is_rejected_on_crop_creation(): void
    {
        $crop = CropType::where('code', 'yam')->firstOrFail();
        $base = ['kind' => 'crop', 'name' => 'Yams', 'operation_type_id' => $crop->operation_type_id, 'crop_type_id' => $crop->id,
            'planting_material_type' => 'tuber', 'planting_unit_type' => 'heap', 'initial_planting_units' => 50, 'planting_date' => '2026-01-01'];
        foreach (['production_purpose' => 'meat', 'growth_stage' => 'adult', 'acquisition_price_per_animal' => '10', 'supplier_contact_id' => Str::uuid()->toString()] as $field => $value) {
            $this->postJson(self::URL, $base + [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('production_cycles', 0);
        $this->postJson(self::URL, $base)->assertCreated()->assertJsonPath('data.livestock', null)->assertJsonPath('data.crop.initial_planting_units', 50);
        $this->assertDatabaseCount('population_movements', 0);
    }

    public function test_new_batch_metadata_cannot_be_changed_through_alternative_update_inputs(): void
    {
        $cycle = $this->postJson(self::URL, $this->payload())->assertCreated()->json('data.id');
        foreach (['production_purpose', 'growth_stage', 'acquisition_price_per_animal', 'supplier_contact_id'] as $field) {
            $this->patchJson(self::URL.'/'.$cycle, [$field => null])->assertConflict()->assertJsonPath('code', 'baseline_locked');
        }
        $this->assertSame(50, PopulationMovement::sole()->quantity);
    }
}
