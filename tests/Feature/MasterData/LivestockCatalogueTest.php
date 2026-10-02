<?php

namespace Tests\Feature\MasterData;

use App\Enums\Capability;
use App\Enums\LivestockGroup;
use App\Models\Species;
use App\Models\SpeciesCapability;
use App\Services\MasterData\SpeciesCapabilityService;
use Database\Seeders\LivestockCatalogueSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The product-owner livestock catalogue: 21 species, 6 groups, capabilities and biological reference periods. */
class LivestockCatalogueTest extends MasterDataTestCase
{
    private const GROUPS = [
        'poultry' => ['chicken', 'turkey', 'guinea_fowl', 'duck', 'goose', 'quail', 'pigeon', 'ostrich'],
        'small_ruminants' => ['goat', 'sheep'],
        'large_ruminants' => ['cattle', 'camel', 'water_buffalo'],
        'non_ruminant_mammals' => ['pig', 'rabbit', 'grasscutter', 'guinea_pig'],
        'equines' => ['horse', 'donkey'],
        'micro_livestock' => ['snail', 'honeybee'],
    ];

    private function config(string $species, Capability $c): ?array
    {
        return $this->row($species, $c)?->reference_config;
    }

    private function row(string $species, Capability $c): ?SpeciesCapability
    {
        return $this->species($species)->speciesCapabilities()->whereHas('capability', fn ($q) => $q->where('code', $c->value))->first();
    }

    private function has(string $species, Capability $c): bool
    {
        return (bool) $this->row($species, $c)?->enabled;
    }

    public function test_all_21_livestock_entries_exist_in_the_six_groups(): void
    {
        $this->assertCount(6, LivestockGroup::cases());
        $this->assertSame(21, Species::whereNotNull('livestock_group')->count());
        foreach (self::GROUPS as $group => $codes) {
            $this->assertEqualsCanonicalizing($codes, Species::where('livestock_group', $group)->pluck('code')->all(), $group);
        }
        // Every livestock species has exactly one livestock operation; poultry shares one operation.
        foreach (Species::whereNotNull('livestock_group')->with('operationType')->get() as $species) {
            $this->assertSame('livestock', $species->operationType->category->value, $species->code);
        }
        $this->assertSame(8, Species::where('livestock_group', 'poultry')->whereHas('operationType', fn ($q) => $q->where('code', 'poultry'))->count());

        // Selector API: group filter, group object, 22 species including fish.
        $this->signInAs($this->owner);
        $this->getJson('/api/v1/master/species?group=equines')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.livestock_group.code', 'equines')->assertJsonPath('data.0.livestock_group.name', 'Equines');
        $this->getJson('/api/v1/master/species?group=nonsense')->assertStatus(422);
        $fish = collect($this->getJson('/api/v1/master/species?category=aquaculture')->json('data'))->first();
        $this->assertSame('fish', $fish['code']);
        $this->assertNull($fish['livestock_group']);
    }

    public function test_existing_species_are_reused_and_fish_is_untouched(): void
    {
        // The catalogue ran through the migration after Phase 4 seeded; ids must not change on a re-run either.
        $before = Species::whereIn('code', ['chicken', 'cattle', 'goat', 'sheep', 'pig', 'rabbit', 'fish'])->pluck('id', 'code')->all();
        $fish = $this->species('fish');
        $fishCaps = $fish->speciesCapabilities()->pluck('enabled', 'capability_id')->all();
        $fishUpdated = $fish->updated_at;

        (new LivestockCatalogueSeeder)->run();

        $this->assertSame($before, Species::whereIn('code', array_keys($before))->pluck('id', 'code')->all());
        $this->assertSame('fishery', $fish->fresh()->operationType->code);
        $this->assertNull($fish->fresh()->livestock_group);
        $this->assertEquals($fishUpdated, $fish->fresh()->updated_at);
        $this->assertSame($fishCaps, $fish->speciesCapabilities()->pluck('enabled', 'capability_id')->all());
        $this->assertTrue($this->has('fish', Capability::SupportsHarvest));
        $this->assertFalse($this->has('fish', Capability::SupportsPregnancy));
        $this->assertFalse($this->has('fish', Capability::SupportsIncubation));
    }

    public function test_reproductive_capabilities_match_the_biology(): void
    {
        foreach (self::GROUPS['poultry'] as $code) {
            $this->assertTrue($this->has($code, Capability::SupportsBreeding), $code);
            $this->assertTrue($this->has($code, Capability::SupportsIncubation), $code);
            $this->assertFalse($this->has($code, Capability::SupportsPregnancy), $code.' must not get pregnancy');
        }
        foreach ([...self::GROUPS['small_ruminants'], ...self::GROUPS['large_ruminants'], ...self::GROUPS['non_ruminant_mammals'], ...self::GROUPS['equines']] as $code) {
            $this->assertTrue($this->has($code, Capability::SupportsBreeding), $code);
            $this->assertTrue($this->has($code, Capability::SupportsPregnancy), $code);
            $this->assertFalse($this->has($code, Capability::SupportsIncubation), $code.' must not get incubation');
            $this->assertFalse($this->has($code, Capability::ProducesEggs), $code);
        }
        // Every species that carries a reference period can use the matching reproductive capability.
        foreach (SpeciesCapability::whereNotNull('reference_config')->with('capability', 'species')->get() as $row) {
            $this->assertTrue($row->enabled, $row->species->code.' '.$row->capability->code);
        }
        foreach (['chicken', 'turkey', 'guinea_fowl', 'duck', 'goose', 'quail', 'pigeon', 'ostrich'] as $code) {
            $this->assertTrue($this->has($code, Capability::ProducesEggs), $code);
        }
        $this->assertTrue($this->has('pigeon', Capability::SupportsBreeding));
        $this->assertTrue($this->has('pigeon', Capability::SupportsIncubation));
        $this->assertFalse($this->has('pigeon', Capability::SupportsPregnancy));
        // Micro-livestock: no mammalian pregnancy, no egg production, bees have no live-weight records.
        foreach (['snail', 'honeybee'] as $code) {
            $this->assertFalse($this->has($code, Capability::SupportsPregnancy), $code);
            $this->assertFalse($this->has($code, Capability::ProducesEggs), $code);
            $this->assertTrue($this->has($code, Capability::SupportsBreeding), $code);
            $this->assertTrue($this->has($code, Capability::SupportsIncubation), $code);
            $this->assertTrue($this->has($code, Capability::SupportsMortality), $code);
        }
        $this->assertFalse($this->has('honeybee', Capability::SupportsLiveWeight));
        $this->assertTrue($this->has('snail', Capability::SupportsLiveWeight));
        foreach (array_merge(...array_values(self::GROUPS)) as $code) {
            $this->assertTrue($this->has($code, Capability::SupportsGroupTracking), $code);
            $this->assertFalse($this->has($code, Capability::SupportsIndividualTracking), $code);
            $this->assertFalse($this->has($code, Capability::ProducesMilk), $code, 'milk is not decided by the catalogue');
        }
    }

    public function test_supplied_exact_and_range_references_are_stored_without_midpoints(): void
    {
        $inc = Capability::SupportsIncubation;
        $ges = Capability::SupportsPregnancy;

        // Exact values: a single default, no range.
        foreach (['chicken' => 21, 'turkey' => 28, 'duck' => 28, 'ostrich' => 42] as $code => $days) {
            $this->assertEquals(['incubation_days' => $days], $this->config($code, $inc), $code);
        }
        foreach (['pig' => 114, 'donkey' => 365] as $code => $days) {
            $this->assertEquals(['gestation_days' => $days], $this->config($code, $ges), $code);
        }
        // Approximate single values.
        $this->assertEquals(['gestation_days' => 150, 'approximate' => true], $this->config('goat', $ges));
        $this->assertEquals(['gestation_days' => 340, 'approximate' => true], $this->config('horse', $ges));

        // Ranges: min and max only; no default invented (Phase 11 decides that policy).
        foreach (['guinea_fowl' => [26, 28], 'goose' => [28, 35], 'quail' => [16, 18], 'pigeon' => [17, 19]] as $code => [$min, $max]) {
            $this->assertEquals(['incubation_days_min' => $min, 'incubation_days_max' => $max], $this->config($code, $inc), $code);
        }
        $ranges = [
            'sheep' => [147, 150, true], 'camel' => [365, 400, false], 'water_buffalo' => [310, 320, true],
            'rabbit' => [30, 32, false], 'grasscutter' => [150, 155, true], 'guinea_pig' => [59, 72, false],
        ];
        foreach ($ranges as $code => [$min, $max, $approx]) {
            $expected = ['gestation_days_min' => $min, 'gestation_days_max' => $max] + ($approx ? ['approximate' => true] : []);
            $config = $this->config($code, $ges);
            $this->assertEquals($expected, $config, $code);
            $this->assertArrayNotHasKey('gestation_days', $config, $code.' range must not gain a midpoint default');
        }
        // Cattle: the Phase 4 documented default 283 is kept and the supplied 280-285 range is added.
        $this->assertEquals(['gestation_days' => 283, 'gestation_days_min' => 280, 'gestation_days_max' => 285, 'approximate' => true], $this->config('cattle', $ges));
    }

    public function test_snail_and_honeybee_are_not_collapsed_to_a_universal_number(): void
    {
        $snail = $this->config('snail', Capability::SupportsIncubation);
        $this->assertSame(['note'], array_keys($snail));
        $this->assertStringContainsString('varies significantly by species', $snail['note']);

        $bee = $this->config('honeybee', Capability::SupportsIncubation);
        $this->assertSame(16, $bee['incubation_days_min']);
        $this->assertSame(24, $bee['incubation_days_max']);
        $this->assertArrayNotHasKey('incubation_days', $bee, 'no 20-day midpoint');
        $this->assertStringContainsString('caste', $bee['note']);
        $this->assertFalse($bee['automatic_expectation'], 'explicit machine-readable flag, not the note');
        $this->assertArrayNotHasKey('automatic_expectation', $snail);

        // The API exposes the same range and caveat.
        $this->signInAs($this->owner);
        $caps = collect($this->getJson('/api/v1/master/species/'.$this->species('honeybee')->id.'/capabilities')->json('data.capabilities'))->keyBy('code');
        $this->assertSame(16, $caps['supports_incubation']['reference']['incubation_days_min']);
        $this->assertSame(24, $caps['supports_incubation']['reference']['incubation_days_max']);
    }

    public function test_reference_config_validation_rejects_bad_ranges(): void
    {
        $service = app(SpeciesCapabilityService::class);
        $quail = $this->species('quail');
        foreach ([
            ['incubation_days_min' => 16],                                    // range halves only come together
            ['incubation_days_max' => 18],
            ['incubation_days_min' => 18, 'incubation_days_max' => 16],       // max below min
            ['incubation_days' => 30, 'incubation_days_min' => 16, 'incubation_days_max' => 18], // default outside range
            ['incubation_days_min' => 'a', 'incubation_days_max' => 18],
            ['approximate' => 'maybe'],
            ['note' => str_repeat('x', 256)],
            ['gestation_days' => 100],                                        // other capability's key
        ] as $config) {
            try {
                $service->set($quail, Capability::SupportsIncubation, true, $config);
                $this->fail('Accepted '.json_encode($config));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $service->set($quail, Capability::SupportsIncubation, true, ['incubation_days' => 17, 'incubation_days_min' => 16, 'incubation_days_max' => 18]);
        $this->assertSame(17, $this->config('quail', Capability::SupportsIncubation)['incubation_days']);
    }

    public function test_correction_is_idempotent_and_never_overwrites_platform_edits(): void
    {
        $count = fn () => [Species::count(), SpeciesCapability::count(), DB::table('operation_types')->count()];
        $before = $count();

        Species::where('code', 'goose')->update(['livestock_group' => 'equines', 'name' => 'Geese']);
        SpeciesCapability::whereKey($this->row('duck', Capability::SupportsIncubation)->id)->update(['reference_config' => json_encode(['incubation_days' => 27])]);
        $this->row('quail', Capability::SupportsBreeding)->update(['enabled' => false]);

        (new LivestockCatalogueSeeder)->run();
        (new LivestockCatalogueSeeder)->run();

        $this->assertSame($before, $count());
        $this->assertSame('equines', $this->species('goose')->livestock_group->value, 'an existing group is left alone');
        $this->assertSame('Geese', $this->species('goose')->name);
        $this->assertSame(27, $this->config('duck', Capability::SupportsIncubation)['incubation_days'], 'edited default survives');
        $this->assertFalse($this->has('quail', Capability::SupportsBreeding), 'a disabled capability is not re-enabled');
    }

    public function test_a_legacy_phase_4_database_is_upgraded_in_place(): void
    {
        // Simulate the pre-correction state: no group, no new species, the original capability rows.
        $chicken = $this->species('chicken');
        $cattle = $this->species('cattle');
        $ids = [$chicken->id, $cattle->id];
        Species::query()->update(['livestock_group' => null]);
        $this->row('cattle', Capability::SupportsPregnancy)->update(['reference_config' => ['gestation_days' => 283]]);
        $this->row('sheep', Capability::SupportsPregnancy)?->delete();
        $this->row('sheep', Capability::SupportsBreeding)?->delete();

        (new LivestockCatalogueSeeder)->run();

        $this->assertSame($ids, [$this->species('chicken')->id, $this->species('cattle')->id]);
        $this->assertSame('poultry', $this->species('chicken')->livestock_group->value);
        $this->assertSame('large_ruminants', $this->species('cattle')->livestock_group->value);
        $this->assertSame(283, $this->config('cattle', Capability::SupportsPregnancy)['gestation_days']);
        $this->assertSame(280, $this->config('cattle', Capability::SupportsPregnancy)['gestation_days_min']);
        $this->assertTrue($this->has('sheep', Capability::SupportsPregnancy));
        $this->assertSame(0, Species::whereNotNull('livestock_group')->count() - 21);
    }

    public function test_existing_production_cycles_and_records_keep_working_with_the_catalogue(): void
    {
        // A cycle created against a Phase 4 species/operation stays valid, and a new species is selectable.
        $this->signInAs($this->owner);
        foreach ([['turkey', 'poultry'], ['horse', 'horse'], ['honeybee', 'honeybee']] as [$species, $operation]) {
            $this->postJson('/api/v1/production-cycles', [
                'kind' => 'livestock', 'name' => ucfirst($species).' group', 'operation_type_id' => $this->species($species)->operationType->id,
                'species_id' => $this->species($species)->id, 'initial_population' => 10, 'start_date' => '2026-09-01',
            ])->assertCreated()->assertJsonPath('data.livestock.species.code', $species);
            $this->assertSame($operation, $this->species($species)->operationType->code);
        }
    }
}
