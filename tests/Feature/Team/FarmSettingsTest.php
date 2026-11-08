<?php

namespace Tests\Feature\Team;

use App\Enums\FarmRole;
use App\Events\Access\FarmSettingsUpdated;
use App\Models\AuditLog;
use App\Services\Farm\FarmSettings;
use Illuminate\Support\Facades\Event;

/** The per-farm settings store (farms.settings JSON) and its first key, use_stock_for_usage. */
class FarmSettingsTest extends TeamTestCase
{
    public function test_default_is_off_and_is_shown_everywhere_the_farm_is_read(): void
    {
        $this->assertNull($this->farm->fresh()->getRawOriginal('settings'));
        $this->assertFalse(FarmSettings::get($this->farm->fresh(), 'use_stock_for_usage'));

        $this->signInAs($this->owner)->getJson('/api/v1/farm')->assertOk()->assertJsonPath('data.settings', ['use_stock_for_usage' => false]);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.farm.settings', ['use_stock_for_usage' => false]);
    }

    public function test_owner_and_manager_can_switch_it_and_it_is_audited(): void
    {
        Event::fake([FarmSettingsUpdated::class]);
        $this->signInAs($this->owner)->patchJson('/api/v1/farm', ['settings' => ['use_stock_for_usage' => true]])
            ->assertOk()->assertJsonPath('data.settings.use_stock_for_usage', true)->assertJsonPath('data.name', 'Green Acres');
        Event::assertDispatched(FarmSettingsUpdated::class, fn ($e) => $e->changes === ['settings.use_stock_for_usage' => ['from' => false, 'to' => true]]);
        $this->assertTrue(FarmSettings::get($this->farm->fresh(), 'use_stock_for_usage'));
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.farm.settings.use_stock_for_usage', true);

        $this->signInAs($this->member(FarmRole::Manager))->patchJson('/api/v1/farm', ['settings' => ['use_stock_for_usage' => false]])->assertOk()->assertJsonPath('data.settings.use_stock_for_usage', false);
    }

    public function test_the_change_reaches_the_audit_log_and_unchanged_values_do_not(): void
    {
        $this->signInAs($this->owner)->patchJson('/api/v1/farm', ['settings' => ['use_stock_for_usage' => true]])->assertOk();
        $this->patchJson('/api/v1/farm', ['settings' => ['use_stock_for_usage' => true]])->assertOk(); // no change: no second entry

        $entries = AuditLog::where('farm_id', $this->farm->id)->where('action', 'farm.settings_updated')->get();
        $this->assertCount(1, $entries);
        $this->assertSame(['settings.use_stock_for_usage'], $entries[0]->changes['fields']);
        $this->assertEquals(['from' => false, 'to' => true], $entries[0]->changes['changes']['settings.use_stock_for_usage']);
    }

    public function test_name_and_settings_can_change_together_and_either_alone(): void
    {
        $this->signInAs($this->owner)->patchJson('/api/v1/farm', ['name' => 'Sunrise Farm', 'settings' => ['use_stock_for_usage' => true]])
            ->assertOk()->assertJsonPath('data.name', 'Sunrise Farm')->assertJsonPath('data.settings.use_stock_for_usage', true);
        $this->patchJson('/api/v1/farm', ['name' => 'Renamed Farm'])->assertOk()->assertJsonPath('data.settings.use_stock_for_usage', true); // settings kept
    }

    public function test_workers_finance_and_vets_cannot_change_it(): void
    {
        foreach ([FarmRole::FarmWorker, FarmRole::Finance, FarmRole::Vet] as $role) {
            $this->signInAs($this->member($role))->patchJson('/api/v1/farm', ['settings' => ['use_stock_for_usage' => true]])->assertForbidden()->assertJsonPath('code', 'forbidden');
        }
        $this->assertFalse(FarmSettings::get($this->farm->fresh(), 'use_stock_for_usage'));
    }

    public function test_invalid_values_and_unknown_keys_are_rejected(): void
    {
        $this->signInAs($this->owner);
        $this->patchJson('/api/v1/farm', [])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->patchJson('/api/v1/farm', ['settings' => ['use_stock_for_usage' => 'yes']])->assertStatus(422)->assertJsonValidationErrors('settings.use_stock_for_usage');
        $this->patchJson('/api/v1/farm', ['settings' => ['use_stock_for_usage' => 1]])->assertStatus(422)->assertJsonValidationErrors('settings.use_stock_for_usage');
        $this->patchJson('/api/v1/farm', ['settings' => ['colour' => 'blue']])->assertStatus(422)->assertJsonValidationErrors('settings');
        $this->patchJson('/api/v1/farm', ['settings' => []])->assertStatus(422);
    }

    public function test_a_setting_belongs_to_its_own_farm(): void
    {
        [$otherOwner, $otherFarm] = $this->otherFarm();
        $this->signInAs($this->owner)->patchJson('/api/v1/farm', ['settings' => ['use_stock_for_usage' => true]])->assertOk();

        $this->assertFalse(FarmSettings::get($otherFarm->fresh(), 'use_stock_for_usage'));
        $this->signInAs($otherOwner)->getJson('/api/v1/farm')->assertJsonPath('data.settings.use_stock_for_usage', false);
    }

    /** Role matrix for the "+ Add to store" popup: exactly the roles that hold inventory.add can add stock. */
    public function test_can_add_stock_flag_per_role(): void
    {
        $expected = ['owner' => true, 'manager' => true, 'farm_worker' => true, 'finance' => false, 'vet' => false];
        foreach (FarmRole::cases() as $role) {
            $user = $role === FarmRole::Owner ? $this->owner : $this->member($role);
            $this->signInAs($user);
            $this->getJson('/api/v1/farm')->assertOk()->assertJsonPath('data.membership.can_add_stock', $expected[$role->value]);
            $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.farm.can_add_stock', $expected[$role->value]);
            // The flag is exactly what the stock-in endpoint enforces.
            $status = $this->postJson('/api/v1/inventory/stock-in', [])->status();
            $this->assertSame($expected[$role->value], $status !== 403, $role->value);
        }
    }
}
