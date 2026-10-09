<?php

namespace Tests\Feature\MasterData;

use App\Enums\Capability;
use App\Enums\OperationCategory;
use App\Enums\TrackingModel;
use App\Models\Breed;
use App\Models\CropType;
use App\Models\CropVariety;
use App\Models\MasterCapability;
use App\Models\OperationType;
use App\Models\ReferenceValue;
use App\Models\Species;
use App\Models\SpeciesCapability;
use App\Services\MasterData\SpeciesCapabilityService;
use Database\Seeders\LivestockBatchReferenceSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class SystemMasterDataTest extends MasterDataTestCase
{
    public function test_initial_catalogue_is_provisioned_by_migration(): void
    {
        $this->assertSame(
            ['poultry', 'cattle', 'goat', 'sheep', 'pig', 'rabbit', 'fishery', 'crops'],
            OperationType::whereIn('code', ['poultry', 'cattle', 'goat', 'sheep', 'pig', 'rabbit', 'fishery', 'crops'])->orderBy('sort_order')->pluck('code')->all(),
        );
        // The Phase 4 species are still present (the livestock catalogue extends them: see LivestockCatalogueTest).
        $this->assertSame(
            ['chicken', 'cattle', 'goat', 'sheep', 'pig', 'rabbit', 'fish'],
            Species::whereIn('code', ['chicken', 'cattle', 'goat', 'sheep', 'pig', 'rabbit', 'fish'])->orderBy('sort_order')->pluck('code')->all(),
        );
        $this->assertSame(
            ['maize', 'cassava', 'yam', 'vegetables', 'fruits'],
            CropType::orderBy('sort_order')->pluck('code')->all(),
        );
        // The user-approved batch catalogue seeds system breeds; crop varieties remain unconfirmed.
        $this->assertSame(array_sum(array_map('count', LivestockBatchReferenceSeeder::BREEDS)), Breed::system()->count());
        $this->assertSame(0, CropVariety::count());
    }

    public function test_seeder_is_idempotent_and_never_overwrites_later_edits(): void
    {
        $counts = fn () => [
            OperationType::count(), Species::count(), CropType::count(), SpeciesCapability::count(), ReferenceValue::count(),
        ];
        $before = $counts();

        // A deliberate later platform edit must survive a re-run.
        Species::where('code', 'chicken')->update(['name' => 'Fowl']);
        SpeciesCapability::whereHas('capability', fn ($q) => $q->where('code', 'supports_incubation'))
            ->update(['reference_config' => json_encode(['incubation_days' => 20])]);

        (new MasterDataSeeder)->run();
        (new MasterDataSeeder)->run();

        $this->assertSame($before, $counts());
        $this->assertSame('Fowl', Species::where('code', 'chicken')->value('name'));
        $this->assertSame(20, $this->species('chicken')->speciesCapabilities()
            ->whereHas('capability', fn ($q) => $q->where('code', 'supports_incubation'))->first()->reference_config['incubation_days']);
    }

    public function test_codes_are_unique_at_database_level(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);

        OperationType::create(['code' => 'poultry', 'name' => 'Duplicate', 'category' => 'livestock', 'tracking_model' => 'population']);
    }

    public function test_livestock_aquaculture_and_crops_are_distinct_and_correctly_linked(): void
    {
        $poultry = OperationType::where('code', 'poultry')->first();
        $fishery = OperationType::where('code', 'fishery')->first();
        $crops = OperationType::where('code', 'crops')->first();

        $this->assertSame(OperationCategory::Livestock, $poultry->category);
        $this->assertSame(TrackingModel::Population, $poultry->tracking_model);
        $this->assertSame(OperationCategory::Aquaculture, $fishery->category);
        $this->assertSame(TrackingModel::Population, $fishery->tracking_model);
        $this->assertSame(OperationCategory::Crop, $crops->category);
        $this->assertSame(TrackingModel::PlantingUnits, $crops->tracking_model);

        $this->assertSame('poultry', $this->species('chicken')->operationType->code);
        $this->assertSame('fishery', $this->species('fish')->operationType->code);
        $this->assertSame(['crops'], CropType::with('operationType')->get()->pluck('operationType.code')->unique()->values()->all());
        $this->assertSame(0, $crops->species()->count());
    }

    public function test_breed_and_variety_cannot_attach_to_the_wrong_kind_of_parent(): void
    {
        // A crop id used as a breed's species (and vice versa) violates the foreign keys.
        $this->expectException(QueryException::class);

        Breed::create(['species_id' => $this->crop('maize')->id, 'name' => 'Nonsense']);
    }

    public function test_capability_registry_and_documented_reference_defaults(): void
    {
        $reference = fn (string $species, Capability $c) => $this->species($species)->speciesCapabilities()
            ->whereHas('capability', fn ($q) => $q->where('code', $c->value))->first()?->reference_config;

        $this->assertSame(['incubation_days' => 21], $reference('chicken', Capability::SupportsIncubation));
        // Cattle keeps its documented default 283 and gains the supplied range (see LivestockCatalogueTest).
        $this->assertSame(283, $reference('cattle', Capability::SupportsPregnancy)['gestation_days']);
        $this->assertSame(21, $reference('chicken', Capability::SupportsIncubation)['incubation_days']);
        $this->assertSame(count(Capability::cases()), MasterCapability::count());
    }

    public function test_planting_material_and_planting_unit_types_are_separate_lists(): void
    {
        $materials = ReferenceValue::where('list', ReferenceValue::PLANTING_MATERIAL_TYPE)->orderBy('sort_order')->pluck('code')->all();
        $units = ReferenceValue::where('list', ReferenceValue::PLANTING_UNIT_TYPE)->orderBy('sort_order')->pluck('code')->all();

        $this->assertSame(['seed', 'seedling', 'stem_cutting', 'tuber', 'sucker', 'other'], $materials);
        $this->assertSame(['heap', 'hole', 'stand'], $units);
        $this->assertEmpty(array_intersect($materials, $units));
    }

    public function test_capability_reference_config_accepts_only_allowed_validated_keys(): void
    {
        $service = app(SpeciesCapabilityService::class);
        $chicken = $this->species('chicken');

        $row = $service->set($chicken, Capability::SupportsIncubation, true, ['incubation_days' => 21]);
        $this->assertSame(['incubation_days' => 21], $row->fresh()->reference_config);

        foreach ([
            [Capability::SupportsIncubation, ['gestation_days' => 283]],          // key belongs to another capability
            [Capability::SupportsIncubation, ['incubation_days' => 21, 'script' => 'x']], // unknown key
            [Capability::SupportsIncubation, ['incubation_days' => 'soon']],      // wrong type
            [Capability::SupportsIncubation, ['incubation_days' => 0]],           // out of range
            [Capability::ProducesEggs, ['anything' => 1]],                        // capability accepts no config
        ] as [$capability, $config]) {
            try {
                $service->set($chicken, $capability, true, $config);
                $this->fail('Invalid metadata was accepted: '.json_encode($config));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        // Reference metadata is configuration only: no operational record exists or is created.
        $this->assertDatabaseCount('operational_records', 0);
    }
}
