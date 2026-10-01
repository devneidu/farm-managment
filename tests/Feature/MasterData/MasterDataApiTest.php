<?php

namespace Tests\Feature\MasterData;

use App\Enums\FarmRole;
use App\Models\OperationType;
use App\Models\Species;

class MasterDataApiTest extends MasterDataTestCase
{
    public function test_authentication_is_required(): void
    {
        $this->getJson('/api/v1/master/species')->assertUnauthorized();
        $this->getJson('/api/v1/custom-breeds')->assertUnauthorized();
        $this->getJson('/api/v1/farm/operations')->assertUnauthorized();
    }

    public function test_every_role_can_read_selector_data(): void
    {
        foreach ([FarmRole::Owner, FarmRole::Manager, FarmRole::FarmWorker, FarmRole::Finance] as $role) {
            $user = $role === FarmRole::Owner ? $this->owner : $this->member($role);
            $this->signInAs($user);

            foreach (['/master/farm-operations', '/master/species', '/master/crops', '/master/planting-reference', '/custom-breeds', '/custom-varieties', '/farm/operations'] as $path) {
                $this->getJson('/api/v1'.$path)->assertOk();
            }
        }
    }

    public function test_reading_master_data_is_not_plan_gated(): void
    {
        $this->assertSame('free', $this->farm->subscription->plan->slug);

        $this->signInAs($this->owner)->getJson('/api/v1/master/species')->assertOk();
    }

    public function test_farm_operations_list_exposes_category_and_tracking_model(): void
    {
        $response = $this->signInAs($this->owner)->getJson('/api/v1/master/farm-operations')->assertOk()
            ->assertJsonPath('meta.farm_operations_configured', false);

        $crops = collect($response->json('data'))->firstWhere('code', 'crops');
        $this->assertSame('crop', $crops['category']);
        $this->assertSame('planting_units', $crops['tracking_model']);
        $this->assertTrue($crops['available']);
        $this->assertFalse($crops['selected']);

        $this->getJson('/api/v1/master/farm-operations?category=aquaculture')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'fishery');
        $this->getJson('/api/v1/master/farm-operations?category=nonsense')->assertStatus(422);
    }

    public function test_species_filters_by_operation_and_category(): void
    {
        $this->signInAs($this->owner);

        $this->getJson('/api/v1/master/species?operation=poultry')
            ->assertOk()->assertJsonCount(8, 'data')
            ->assertJsonPath('data.0.code', 'chicken')
            ->assertJsonPath('data.0.operation.category', 'livestock');

        $this->getJson('/api/v1/master/species?category=aquaculture')->assertOk()->assertJsonPath('data.0.code', 'fish');
        $this->getJson('/api/v1/master/species')->assertOk()->assertJsonCount(22, 'data');
        $this->getJson('/api/v1/master/species?operation=crops')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_species_list_carries_enabled_capability_codes(): void
    {
        $data = $this->signInAs($this->owner)->getJson('/api/v1/master/species?operation=poultry')->json('data.0');

        $this->assertContains('supports_incubation', $data['capability_codes']);
        $this->assertContains('produces_eggs', $data['capability_codes']);
        $this->assertNotContains('supports_pregnancy', $data['capability_codes']);
    }

    public function test_capabilities_endpoint_returns_reference_metadata_not_events(): void
    {
        $cattle = $this->species('cattle');

        $response = $this->signInAs($this->owner)->getJson("/api/v1/master/species/{$cattle->id}/capabilities")->assertOk();

        $caps = collect($response->json('data.capabilities'))->keyBy('code');
        $this->assertCount(11, $caps);
        $this->assertTrue($caps['supports_pregnancy']['enabled']);
        $this->assertEquals(['gestation_days' => 283, 'gestation_days_min' => 280, 'gestation_days_max' => 285, 'approximate' => true], $caps['supports_pregnancy']['reference']);
        $this->assertFalse($caps['supports_incubation']['enabled']);
        $this->assertNull($caps['supports_incubation']['reference']);
        $response->assertJsonPath('data.operation.code', 'cattle');

        $this->getJson('/api/v1/master/species/'.fake()->uuid().'/capabilities')->assertNotFound();
    }

    public function test_breeds_by_species_return_system_plus_own_custom_only(): void
    {
        $this->systemBreed('chicken', 'Broiler');
        $this->systemBreed('chicken', 'Retired Line', active: false);
        $this->systemBreed('cattle', 'Zebu');
        $this->customBreed($this->farm, 'chicken', 'Our Hybrid');
        $this->customBreed($this->farm, 'chicken', 'Old Hybrid', active: false);
        [, $other] = $this->otherFarm();
        $this->customBreed($other, 'chicken', 'Secret Cross');

        $chicken = $this->species('chicken');
        $response = $this->signInAs($this->owner)->getJson("/api/v1/master/species/{$chicken->id}/breeds")->assertOk();

        $this->assertSame(['Broiler', 'Our Hybrid'], collect($response->json('data'))->pluck('name')->all());
        $this->assertSame(['system', 'farm'], collect($response->json('data'))->pluck('source')->all());

        // Inactive ones only on request (history display); still never the other farm's
        $all = $this->getJson("/api/v1/master/species/{$chicken->id}/breeds?include_inactive=true")->json('data');
        $this->assertEqualsCanonicalizing(['Broiler', 'Retired Line', 'Our Hybrid', 'Old Hybrid'], collect($all)->pluck('name')->all());
    }

    public function test_farm_b_sees_only_its_own_custom_breeds(): void
    {
        $this->customBreed($this->farm, 'goat', 'Farm A Goat');
        [$ownerB, $farmB] = $this->otherFarm();
        $this->customBreed($farmB, 'goat', 'Farm B Goat');

        $goat = $this->species('goat');
        $names = collect($this->signInAs($ownerB)->getJson("/api/v1/master/species/{$goat->id}/breeds")->json('data'))->pluck('name')->all();

        $this->assertSame(['Farm B Goat'], $names);
    }

    public function test_crops_and_varieties(): void
    {
        $this->systemVariety('maize', 'Sample System Maize');
        $this->customVariety($this->farm, 'maize', 'Own Maize');
        [, $other] = $this->otherFarm();
        $this->customVariety($other, 'maize', 'Other Maize');

        $this->signInAs($this->owner);
        $crops = $this->getJson('/api/v1/master/crops')->assertOk();
        $this->assertSame(['maize', 'cassava', 'yam', 'vegetables', 'fruits'], collect($crops->json('data'))->pluck('code')->all());
        $this->assertSame('planting_units', $crops->json('data.0.operation.tracking_model'));

        $maize = $this->crop('maize');
        $names = collect($this->getJson("/api/v1/master/crops/{$maize->id}/varieties")->assertOk()->json('data'))->pluck('name')->all();
        $this->assertSame(['Sample System Maize', 'Own Maize'], $names);

        // A variety is optional: a crop without any simply has an empty list
        $yam = $this->crop('yam');
        $this->getJson("/api/v1/master/crops/{$yam->id}/varieties")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/master/crops/'.fake()->uuid().'/varieties')->assertNotFound();
    }

    public function test_inactive_species_hidden_by_default_and_available_only_on_request(): void
    {
        Species::where('code', 'rabbit')->update(['is_active' => false]);

        $this->signInAs($this->owner);
        $this->assertNotContains('rabbit', collect($this->getJson('/api/v1/master/species')->json('data'))->pluck('code')->all());
        $this->assertContains('rabbit', collect($this->getJson('/api/v1/master/species?include_inactive=true')->json('data'))->pluck('code')->all());
    }

    public function test_planting_reference_lists_are_separate(): void
    {
        $data = $this->signInAs($this->owner)->getJson('/api/v1/master/planting-reference')->assertOk()->json('data');

        $this->assertContains('tuber', array_column($data['material_types'], 'code'));
        $this->assertContains('heap', array_column($data['unit_types'], 'code'));
        $this->assertNotContains('heap', array_column($data['material_types'], 'code'));
    }

    // ---- optional farm operation selection ------------------------------------------------

    public function test_unconfigured_farm_loses_nothing_and_configured_farm_filters_available(): void
    {
        $this->signInAs($this->owner);
        $this->getJson('/api/v1/farm/operations')->assertOk()->assertJsonPath('data.configured', false)->assertJsonCount(0, 'data.operations');
        $this->getJson('/api/v1/master/species?available=true')->assertOk()->assertJsonCount(22, 'data');

        $poultry = OperationType::where('code', 'poultry')->value('id');
        $crops = OperationType::where('code', 'crops')->value('id');

        $this->putJson('/api/v1/farm/operations', ['operation_ids' => [$poultry, $crops]])
            ->assertOk()->assertJsonPath('data.configured', true)->assertJsonCount(2, 'data.operations');

        // disabled operations are filtered out of "available" selectors, but not removed from the full catalogue
        $available = collect($this->getJson('/api/v1/master/species?available=true')->json('data'))->pluck('code')->all();
        $this->assertSame(['chicken', 'turkey', 'guinea_fowl', 'duck', 'goose', 'quail', 'pigeon', 'ostrich'], $available);
        $this->getJson('/api/v1/master/species')->assertJsonCount(22, 'data');
        $this->assertSame(['maize', 'cassava', 'yam', 'vegetables', 'fruits'], collect($this->getJson('/api/v1/master/crops?available=true')->json('data'))->pluck('code')->all());

        $ops = collect($this->getJson('/api/v1/master/farm-operations')->assertJsonPath('meta.farm_operations_configured', true)->json('data'));
        $this->assertTrue($ops->firstWhere('code', 'poultry')['selected']);
        $this->assertFalse($ops->firstWhere('code', 'cattle')['available']);
        $this->assertSame(['poultry', 'crops'], $this->getJson('/api/v1/master/farm-operations?available=true')->json('data.*.code'));

        // clearing returns to unconfigured
        $this->putJson('/api/v1/farm/operations', ['operation_ids' => []])->assertOk()->assertJsonPath('data.configured', false);
        $this->getJson('/api/v1/master/species?available=true')->assertJsonCount(22, 'data');
    }

    public function test_farm_operations_are_farm_scoped_and_validated(): void
    {
        $poultry = OperationType::where('code', 'poultry')->value('id');
        $this->signInAs($this->owner)->putJson('/api/v1/farm/operations', ['operation_ids' => [$poultry]])->assertOk();

        [$ownerB] = $this->otherFarm();
        $this->signInAs($ownerB)->getJson('/api/v1/farm/operations')->assertJsonPath('data.configured', false);

        $this->signInAs($this->owner);
        $this->putJson('/api/v1/farm/operations', ['operation_ids' => [fake()->uuid()]])->assertStatus(422);
        $this->putJson('/api/v1/farm/operations', ['operation_ids' => [$poultry, $poultry]])->assertStatus(422);
        $this->putJson('/api/v1/farm/operations', [])->assertStatus(422);
        OperationType::where('code', 'pig')->update(['is_active' => false]);
        $this->putJson('/api/v1/farm/operations', ['operation_ids' => [OperationType::where('code', 'pig')->value('id')]])->assertStatus(422);
    }

    public function test_only_farm_update_holders_can_change_operations(): void
    {
        $poultry = OperationType::where('code', 'poultry')->value('id');

        $this->signInAs($this->member(FarmRole::FarmWorker))->putJson('/api/v1/farm/operations', ['operation_ids' => [$poultry]])->assertForbidden();
        $this->signInAs($this->member(FarmRole::Finance))->putJson('/api/v1/farm/operations', ['operation_ids' => [$poultry]])->assertForbidden();
        $this->signInAs($this->member(FarmRole::Manager))->putJson('/api/v1/farm/operations', ['operation_ids' => [$poultry]])->assertOk();
    }
}
