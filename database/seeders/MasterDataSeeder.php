<?php

namespace Database\Seeders;

use App\Enums\Capability;
use App\Enums\OperationCategory;
use App\Models\CropType;
use App\Models\MasterCapability;
use App\Models\OperationType;
use App\Models\ReferenceValue;
use App\Models\Species;
use App\Models\SpeciesCapability;
use App\Services\MasterData\SpeciesCapabilityService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Initial platform agricultural master data. Only what the product documentation confirms is seeded:
 * the operations of the farm-setup matrix, one species per livestock operation, the crop examples, the capability
 * registry, and the two documented biological reference values (chicken incubation 21 d, cattle gestation 283 d).
 * NO breeds, varieties, other durations or agronomic values are seeded (unconfirmed - enrich later).
 *
 * INSERT-ONLY and keyed by stable `code`: existing rows (and any later platform-admin edits) are never touched, so
 * re-running is safe. Provisioned automatically by a migration; run
 * `php artisan db:seed --class=MasterDataSeeder` only to add rows that are missing.
 */
class MasterDataSeeder extends Seeder
{
    /** code => [name, category] (order = sort order) */
    private const OPERATIONS = [
        'poultry' => ['Poultry', OperationCategory::Livestock],
        'cattle' => ['Cattle', OperationCategory::Livestock],
        'goat' => ['Goat', OperationCategory::Livestock],
        'sheep' => ['Sheep', OperationCategory::Livestock],
        'pig' => ['Pig', OperationCategory::Livestock],
        'rabbit' => ['Rabbit', OperationCategory::Livestock],
        'fishery' => ['Fishery', OperationCategory::Aquaculture],
        'crops' => ['Crops', OperationCategory::Crop],
    ];

    /** species code => [name, operation code] */
    private const SPECIES = [
        'chicken' => ['Chicken', 'poultry'],
        'cattle' => ['Cattle', 'cattle'],
        'goat' => ['Goat', 'goat'],
        'sheep' => ['Sheep', 'sheep'],
        'pig' => ['Pig', 'pig'],
        'rabbit' => ['Rabbit', 'rabbit'],
        'fish' => ['Fish', 'fishery'],
    ];

    /** crop code => name (all under the `crops` operation) */
    private const CROPS = [
        'maize' => 'Maize',
        'cassava' => 'Cassava',
        'yam' => 'Yam',
        'vegetables' => 'Vegetables',
        'fruits' => 'Fruits',
    ];

    private const PLANTING_MATERIAL_TYPES = [
        'seed' => 'Seed', 'seedling' => 'Seedling', 'stem_cutting' => 'Stem cutting',
        'tuber' => 'Tuber', 'sucker' => 'Sucker', 'other' => 'Other',
    ];

    private const PLANTING_UNIT_TYPES = ['heap' => 'Heap', 'hole' => 'Hole', 'stand' => 'Stand'];

    /** Capabilities every livestock/fish species has (batch head-count tracking; feeding, mortality, weight records). */
    private const COMMON_LIVESTOCK = [
        Capability::SupportsGroupTracking, Capability::SupportsMortality,
        Capability::SupportsFeedRecords, Capability::SupportsLiveWeight,
    ];

    /** species code => list of [Capability, ?reference_config] beyond the common set */
    private function speciesCapabilities(): array
    {
        return [
            'chicken' => [
                [Capability::ProducesEggs, null],
                [Capability::SupportsIncubation, ['incubation_days' => 21]],
                [Capability::SupportsBreeding, null],
            ],
            'cattle' => [
                [Capability::ProducesMilk, null],
                [Capability::SupportsPregnancy, ['gestation_days' => 283]],
                [Capability::SupportsBreeding, null],
            ],
            'goat' => [
                [Capability::ProducesMilk, null],
                [Capability::SupportsPregnancy, null], // gestation reference intentionally not seeded (unconfirmed)
                [Capability::SupportsBreeding, null],
            ],
            'sheep' => [
                [Capability::ProducesMilk, null],
            ],
            'fish' => [
                [Capability::SupportsHarvest, null],
            ],
        ];
    }

    public function run(): void
    {
        DB::transaction(function () {
            $sort = 0;
            $operations = [];
            foreach (self::OPERATIONS as $code => [$name, $category]) {
                $operations[$code] = OperationType::firstOrCreate(['code' => $code], [
                    'name' => $name, 'category' => $category, 'tracking_model' => $category->trackingModel(),
                    'sort_order' => (++$sort) * 10,
                ]);
            }

            $sort = 0;
            $species = [];
            foreach (self::SPECIES as $code => [$name, $operation]) {
                $species[$code] = Species::firstOrCreate(['code' => $code], [
                    'operation_type_id' => $operations[$operation]->id, 'name' => $name, 'sort_order' => (++$sort) * 10,
                ]);
            }

            $sort = 0;
            foreach (self::CROPS as $code => $name) {
                CropType::firstOrCreate(['code' => $code], [
                    'operation_type_id' => $operations['crops']->id, 'name' => $name, 'sort_order' => (++$sort) * 10,
                ]);
            }

            foreach (Capability::cases() as $capability) {
                MasterCapability::firstOrCreate(['code' => $capability->value], ['name' => $capability->label()]);
            }

            $service = app(SpeciesCapabilityService::class);
            foreach ($species as $code => $model) {
                $wanted = array_map(fn ($c) => [$c, null], self::COMMON_LIVESTOCK);
                $wanted = array_merge($wanted, $this->speciesCapabilities()[$code] ?? []);

                foreach ($wanted as [$capability, $config]) {
                    $registry = MasterCapability::where('code', $capability->value)->firstOrFail();

                    // Insert-only: an existing pair (possibly edited later) is left alone.
                    if (! SpeciesCapability::where('species_id', $model->id)->where('capability_id', $registry->id)->exists()) {
                        $service->set($model, $capability, true, $config);
                    }
                }
            }

            $this->referenceList(ReferenceValue::PLANTING_MATERIAL_TYPE, self::PLANTING_MATERIAL_TYPES);
            $this->referenceList(ReferenceValue::PLANTING_UNIT_TYPE, self::PLANTING_UNIT_TYPES);
        });
    }

    private function referenceList(string $list, array $values): void
    {
        $sort = 0;
        foreach ($values as $code => $name) {
            ReferenceValue::firstOrCreate(['list' => $list, 'code' => $code], ['name' => $name, 'sort_order' => (++$sort) * 10]);
        }
    }
}
