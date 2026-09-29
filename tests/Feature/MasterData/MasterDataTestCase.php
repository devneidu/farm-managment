<?php

namespace Tests\Feature\MasterData;

use App\Models\Breed;
use App\Models\CropType;
use App\Models\CropVariety;
use App\Models\Farm;
use App\Models\Species;
use Tests\Feature\Team\TeamTestCase;

/** Master data is provisioned by migration, so every test starts with the system catalogue in place. */
abstract class MasterDataTestCase extends TeamTestCase
{
    protected function species(string $code): Species
    {
        return Species::where('code', $code)->firstOrFail();
    }

    protected function crop(string $code): CropType
    {
        return CropType::where('code', $code)->firstOrFail();
    }

    protected function systemBreed(string $speciesCode, string $name, bool $active = true): Breed
    {
        return Breed::create([
            'species_id' => $this->species($speciesCode)->id, 'farm_id' => null,
            'code' => str($name)->slug('_')->toString(), 'name' => $name, 'is_active' => $active,
        ]);
    }

    protected function systemVariety(string $cropCode, string $name): CropVariety
    {
        return CropVariety::create([
            'crop_type_id' => $this->crop($cropCode)->id, 'farm_id' => null,
            'code' => str($name)->slug('_')->toString(), 'name' => $name,
        ]);
    }

    protected function customBreed(Farm $farm, string $speciesCode, string $name, bool $active = true): Breed
    {
        return Breed::create(['species_id' => $this->species($speciesCode)->id, 'farm_id' => $farm->id, 'name' => $name, 'is_active' => $active]);
    }

    protected function customVariety(Farm $farm, string $cropCode, string $name, bool $active = true): CropVariety
    {
        return CropVariety::create(['crop_type_id' => $this->crop($cropCode)->id, 'farm_id' => $farm->id, 'name' => $name, 'is_active' => $active]);
    }
}
