<?php

namespace Database\Seeders;

use App\Enums\Capability;
use App\Enums\LivestockGroup;
use App\Enums\OperationCategory;
use App\Models\MasterCapability;
use App\Models\OperationType;
use App\Models\Species;
use App\Models\SpeciesCapability;
use App\Services\MasterData\SpeciesCapabilityService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The product-owner livestock catalogue (21 species in 6 groups) with biological REFERENCE periods.
 * See docs/api/LIVESTOCK-CATALOGUE.md. The values are product decisions, not researched here.
 *
 * Safe on a database that already holds Phase 4 data: existing species/operations are REUSED (same UUIDs), rows are
 * created only when missing, and a later platform edit is never overwritten (a missing group is filled; an existing
 * reference config only gains keys it lacks). Idempotent. Fish / aquaculture rows are never touched.
 */
class LivestockCatalogueSeeder extends Seeder
{
    private const COMMON = [
        Capability::SupportsGroupTracking, Capability::SupportsMortality,
        Capability::SupportsFeedRecords, Capability::SupportsLiveWeight,
    ];

    /**
     * species code => [name, group, operation code (existing or new), species sort, operation name|null (null = exists / shared), operation sort]
     * Poultry species all share the existing `poultry` operation; every other species keeps the one-operation-per-species pattern.
     */
    private function catalogue(): array
    {
        $g = LivestockGroup::class;

        return [
            'chicken' => ['Chicken', $g::Poultry, 'poultry', 10],
            'turkey' => ['Turkey', $g::Poultry, 'poultry', 11],
            'guinea_fowl' => ['Guinea Fowl', $g::Poultry, 'poultry', 12],
            'duck' => ['Duck', $g::Poultry, 'poultry', 13],
            'goose' => ['Goose', $g::Poultry, 'poultry', 14],
            'quail' => ['Quail', $g::Poultry, 'poultry', 15],
            'pigeon' => ['Pigeon', $g::Poultry, 'poultry', 16],
            'ostrich' => ['Ostrich', $g::Poultry, 'poultry', 17],
            'goat' => ['Goat', $g::SmallRuminants, 'goat', 30],
            'sheep' => ['Sheep', $g::SmallRuminants, 'sheep', 40],
            'cattle' => ['Cattle', $g::LargeRuminants, 'cattle', 20],
            'camel' => ['Camel', $g::LargeRuminants, 'camel', 21],
            'water_buffalo' => ['Water Buffalo', $g::LargeRuminants, 'water_buffalo', 22],
            'pig' => ['Pig', $g::NonRuminantMammals, 'pig', 50],
            'rabbit' => ['Rabbit', $g::NonRuminantMammals, 'rabbit', 60],
            'grasscutter' => ['Grasscutter', $g::NonRuminantMammals, 'grasscutter', 61],
            'guinea_pig' => ['Guinea Pig', $g::NonRuminantMammals, 'guinea_pig', 62],
            'horse' => ['Horse', $g::Equines, 'horse', 72],
            'donkey' => ['Donkey', $g::Equines, 'donkey', 73],
            'snail' => ['Snail', $g::MicroLivestock, 'snail', 74],
            'honeybee' => ['Honeybee', $g::MicroLivestock, 'honeybee', 75],
        ];
    }

    /** Operations that do not exist yet (code => [name, sort]); the others are Phase 4 rows reused as they are. */
    private const NEW_OPERATIONS = [
        'camel' => ['Camel', 61], 'water_buffalo' => ['Water Buffalo', 62], 'grasscutter' => ['Grasscutter', 63],
        'guinea_pig' => ['Guinea Pig', 64], 'horse' => ['Horse', 65], 'donkey' => ['Donkey', 66],
        'snail' => ['Snail', 67], 'honeybee' => ['Honeybee', 68],
    ];

    /**
     * Reference periods. Keys follow Capability::configRules(): `<x>_days` is a single value; `_min`/`_max` an inclusive
     * range that is NEVER collapsed to a midpoint (no default is invented for ranges); `approximate` marks "about N".
     * Cattle keeps its Phase 4 documented default of 283 days (inside the supplied 280-285 range).
     */
    private function reproduction(): array
    {
        $inc = fn (array $c) => [Capability::SupportsIncubation, $c];
        $ges = fn (array $c) => [Capability::SupportsPregnancy, $c];

        return [
            'chicken' => $inc(['incubation_days' => 21]),
            'turkey' => $inc(['incubation_days' => 28]),
            'guinea_fowl' => $inc(['incubation_days_min' => 26, 'incubation_days_max' => 28]),
            'duck' => $inc(['incubation_days' => 28]),
            'goose' => $inc(['incubation_days_min' => 28, 'incubation_days_max' => 35]),
            'quail' => $inc(['incubation_days_min' => 16, 'incubation_days_max' => 18]),
            'pigeon' => $inc(['incubation_days_min' => 17, 'incubation_days_max' => 19]),
            'ostrich' => $inc(['incubation_days' => 42]),
            'goat' => $ges(['gestation_days' => 150, 'approximate' => true]),
            'sheep' => $ges(['gestation_days_min' => 147, 'gestation_days_max' => 150, 'approximate' => true]),
            'cattle' => $ges(['gestation_days_min' => 280, 'gestation_days_max' => 285, 'approximate' => true]),
            'camel' => $ges(['gestation_days_min' => 365, 'gestation_days_max' => 400]),
            'water_buffalo' => $ges(['gestation_days_min' => 310, 'gestation_days_max' => 320, 'approximate' => true]),
            'pig' => $ges(['gestation_days' => 114]),
            'rabbit' => $ges(['gestation_days_min' => 30, 'gestation_days_max' => 32]),
            'grasscutter' => $ges(['gestation_days_min' => 150, 'gestation_days_max' => 155, 'approximate' => true]),
            'guinea_pig' => $ges(['gestation_days_min' => 59, 'gestation_days_max' => 72]),
            'horse' => $ges(['gestation_days' => 340, 'approximate' => true]),
            'donkey' => $ges(['gestation_days' => 365]),
            // Micro-livestock: no universal number. Snail varies by species; honeybee development depends on caste.
            'snail' => $inc(['note' => 'Incubation varies significantly by species; no universal period is defined.']),
            'honeybee' => $inc(['incubation_days_min' => 16, 'incubation_days_max' => 24, 'note' => 'Development period depends on caste (16-24 days); no single value applies.']),
        ];
    }

    public function run(): void
    {
        DB::transaction(function () {
            $service = app(SpeciesCapabilityService::class);
            foreach (Capability::cases() as $capability) {
                MasterCapability::firstOrCreate(['code' => $capability->value], ['name' => $capability->label()]);
            }

            $operations = [];
            foreach (self::NEW_OPERATIONS as $code => [$name, $sort]) {
                OperationType::firstOrCreate(['code' => $code], [
                    'name' => $name, 'category' => OperationCategory::Livestock, 'tracking_model' => OperationCategory::Livestock->trackingModel(), 'sort_order' => $sort,
                ]);
            }

            foreach ($this->catalogue() as $code => [$name, $group, $operationCode, $sort]) {
                $operation = $operations[$operationCode] ??= OperationType::where('code', $operationCode)->firstOrFail();
                $species = Species::firstOrCreate(['code' => $code], [
                    'operation_type_id' => $operation->id, 'name' => $name, 'livestock_group' => $group, 'sort_order' => $sort,
                ]);
                if ($species->livestock_group === null) {
                    $species->update(['livestock_group' => $group]); // reused Phase 4 row: group only; id/operation/name untouched
                }

                $wanted = array_map(fn ($c) => [$c, null], self::COMMON);
                if ($code === 'honeybee') {
                    $wanted = array_filter($wanted, fn ($w) => $w[0] !== Capability::SupportsLiveWeight);
                }
                $wanted[] = [Capability::SupportsBreeding, null];
                if ($group === LivestockGroup::Poultry) { // egg production is a biological capability, not a commercial purpose
                    $wanted[] = [Capability::ProducesEggs, null];
                }
                $wanted[] = $this->reproduction()[$code];

                foreach ($wanted as [$capability, $config]) {
                    $this->apply($service, $species, $capability, $config);
                }
            }
        });
    }

    /** Create when missing; otherwise only fill a missing config / missing keys (never overwrite or re-enable). */
    private function apply(SpeciesCapabilityService $service, Species $species, Capability $capability, ?array $config): void
    {
        $registry = MasterCapability::where('code', $capability->value)->firstOrFail();
        $row = SpeciesCapability::where('species_id', $species->id)->where('capability_id', $registry->id)->first();
        if (! $row) {
            $service->set($species, $capability, true, $config);

            return;
        }
        if ($config === null) {
            return;
        }
        $merged = ($row->reference_config ?? []) + $config;
        if ($merged === ($row->reference_config ?? [])) {
            return;
        }
        try {
            $row->update(['reference_config' => $service->validatedConfig($capability, $merged)]);
        } catch (ValidationException) {
            // A later platform edit conflicts with the supplied reference (e.g. a default outside the range): keep the edit.
        }
    }
}
