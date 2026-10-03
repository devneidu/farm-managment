<?php

namespace Tests\Feature\Localization;

use App\Enums\FarmRole;
use App\Models\User;
use App\Services\Localization\LocaleCatalogue;
use Carbon\CarbonImmutable;
use Tests\Feature\Reports\ReportsFixtures;
use Tests\Feature\Team\TeamTestCase;

class LocalizationTest extends TeamTestCase
{
    use ReportsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->onPlan('farm-business');
    }

    /** Simulates a reviewed Hausa pack: enabled in the registry, with one translated key. */
    private function enableHausa(): void
    {
        config(['localization.locales.ha.enabled' => true, 'localization.locales.ha.status' => 'launched']);
        $translator = app('translator');
        $translator->addLines(['ui.common.save' => 'Ajiye'], 'ha');
    }

    private function hausaFileBundle(): void
    {
        // The catalogue reads lang/{locale}/ui.php; write a tiny pack for the duration of the test.
        $dir = lang_path('ha');
        @mkdir($dir);
        file_put_contents($dir.'/ui.php', "<?php\nreturn ['common' => ['save' => 'Ajiye', 'cancel' => ''], 'nav' => ['tasks' => 'Ayyuka']];\n");
        $this->beforeApplicationDestroyed(function () use ($dir) {
            @unlink($dir.'/ui.php');
            @rmdir($dir);
        });
    }

    // ------------------------------------------------------------------ defaults and registry

    public function test_nigeria_first_defaults_are_unchanged_and_onboarding_needs_no_language_choice(): void
    {
        $this->assertSame('Africa/Lagos', $this->farm->timezone);
        $this->assertSame('NGN', $this->farm->currency);
        $this->assertSame('en', $this->farm->locale);
        $this->assertSame('en', config('localization.default'));
        $this->assertNull($this->owner->fresh()->locale, 'no explicit language is stored at onboarding');
    }

    public function test_locales_listing_is_public_english_launched_and_nigerian_packs_unavailable(): void
    {
        $data = $this->getJson('/api/v1/locales')->assertOk()->json('data');

        $this->assertSame('en', $data['default']);
        $this->assertSame('en', $data['fallback']);
        $by = collect($data['locales'])->keyBy('code');
        $this->assertTrue($by['en']['available']);
        $this->assertSame('launched', $by['en']['status']);
        foreach (['ha', 'yo', 'ig', 'pcm'] as $code) {
            $this->assertFalse($by[$code]['available'], $code);
            $this->assertSame('pending_terminology_review', $by[$code]['status']);
        }
        $this->assertSame(['decimal_separator' => '.', 'thousands_separator' => null, 'input_mode' => 'decimal'], $data['number_input']);
    }

    // ------------------------------------------------------------------ translation bundles

    public function test_english_bundle_is_complete_stable_and_has_accessibility_keys(): void
    {
        $a = $this->getJson('/api/v1/translations/en')->assertOk()->json('data');
        $b = $this->getJson('/api/v1/translations/en')->assertOk()->json('data');

        $this->assertTrue($a['complete']);
        $this->assertSame([], $a['fallback_keys']);
        $this->assertSame($a['version'], $b['version']);
        $this->assertSame('ltr', $a['direction']);
        $this->assertSame('Save', $a['messages']['common.save']);
        foreach (['a11y.skip_to_content', 'a11y.error_prefix', 'form.required', 'form.decimal_hint', 'task_state.overdue'] as $key) {
            $this->assertArrayHasKey($key, $a['messages']);
        }
        // Task-state labels line up with the stable machine codes returned by the task API.
        foreach (['upcoming', 'due_today', 'overdue', 'completed', 'cancelled'] as $state) {
            $this->assertArrayHasKey('task_state.'.$state, $a['messages']);
        }
    }

    public function test_unavailable_or_unknown_locale_bundle_is_a_404_with_a_stable_code(): void
    {
        foreach (['ha', 'xx', 'EN-NG'] as $code) {
            $this->getJson('/api/v1/translations/'.$code)->assertNotFound()->assertJsonPath('code', 'locale_unavailable');
        }
    }

    public function test_enabled_pack_falls_back_to_english_per_key_and_reports_it(): void
    {
        $this->enableHausa();
        $this->hausaFileBundle();

        $bundle = $this->getJson('/api/v1/translations/ha')->assertOk()->json('data');

        $this->assertSame('Ajiye', $bundle['messages']['common.save']);
        $this->assertSame('Ayyuka', $bundle['messages']['nav.tasks']);
        $this->assertSame('Cancel', $bundle['messages']['common.cancel'], 'empty translation falls back');
        $this->assertSame('Dashboard', $bundle['messages']['nav.dashboard'], 'missing key falls back');
        $this->assertFalse($bundle['complete']);
        $this->assertContains('nav.dashboard', $bundle['fallback_keys']);
        $this->assertContains('common.cancel', $bundle['fallback_keys']);
        $this->assertNotContains('common.save', $bundle['fallback_keys']);
        $this->assertSame(array_keys($this->getJson('/api/v1/translations/en')->json('data.messages')), array_keys($bundle['messages']), 'same keys as English: never a missing one');
    }

    public function test_server_side_lookup_never_blank_and_unknown_key_returns_the_key(): void
    {
        $this->enableHausa();
        $this->hausaFileBundle();
        $c = app(LocaleCatalogue::class);

        $this->assertSame('Ajiye', $c->translate('common.save', 'ha'));
        $this->assertSame('Dashboard', $c->translate('nav.dashboard', 'ha'));
        $this->assertSame('totally.unknown.key', $c->translate('totally.unknown.key', 'ha'));
        $this->assertSame('Save', $c->translate('common.save', 'zz'), 'unregistered locale uses the fallback');
    }

    // ------------------------------------------------------------------ preferences

    public function test_preferences_require_authentication(): void
    {
        $this->getJson('/api/v1/me/preferences')->assertUnauthorized();
        $this->patchJson('/api/v1/me/preferences', ['locale' => 'en'])->assertUnauthorized();
    }

    public function test_user_can_read_set_and_clear_locale_and_it_is_exposed_additively(): void
    {
        $this->signInAs($this->owner);

        $this->getJson('/api/v1/me/preferences')->assertOk()
            ->assertJsonPath('data.locale', null)
            ->assertJsonPath('data.effective_locale', 'en')
            ->assertJsonPath('data.farm_locale', 'en')
            ->assertJsonPath('data.available_locales', ['en']);

        $this->patchJson('/api/v1/me/preferences', ['locale' => ' EN '])->assertOk()->assertJsonPath('data.locale', 'en');
        $this->assertSame('en', $this->owner->fresh()->locale);
        $this->getJson('/api/v1/account')->assertOk()->assertJsonPath('data.locale', 'en');
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.locale', 'en');

        $this->patchJson('/api/v1/me/preferences', [])->assertOk()->assertJsonPath('data.locale', 'en');
        $this->patchJson('/api/v1/me/preferences', ['locale' => null])->assertOk()->assertJsonPath('data.locale', null);
        $this->assertNull($this->owner->fresh()->locale);
    }

    public function test_only_available_locales_are_accepted(): void
    {
        $this->signInAs($this->owner);

        foreach (['ha', 'yo', 'ig', 'pcm', 'fr', 'english', ['en']] as $bad) {
            $this->patchJson('/api/v1/me/preferences', ['locale' => $bad])->assertUnprocessable()->assertJsonValidationErrors('locale');
        }
        $this->assertNull($this->owner->fresh()->locale);

        $this->enableHausa();
        $this->patchJson('/api/v1/me/preferences', ['locale' => 'ha'])->assertOk()->assertJsonPath('data.locale', 'ha');
    }

    public function test_preferences_are_per_user_and_cannot_touch_other_users_or_farms(): void
    {
        $this->enableHausa();
        $worker = $this->member(FarmRole::FarmWorker);
        $this->signInAs($this->owner);
        $this->patchJson('/api/v1/me/preferences', ['locale' => 'ha', 'user_id' => $worker->id, 'farm_id' => $this->farm->id, 'farm_locale' => 'yo'])->assertOk();

        $this->assertNull($worker->fresh()->locale);
        $this->assertSame('en', $this->farm->fresh()->locale, 'farm locale is not client-writable');

        [$otherOwner, $otherFarm] = $this->otherFarm();
        $this->assertNull($otherOwner->fresh()->locale);
        $this->assertSame('en', $otherFarm->fresh()->locale);
    }

    // ------------------------------------------------------------------ request locale resolution

    public function test_accept_language_and_saved_choice_resolve_only_to_available_locales(): void
    {
        $this->signInAs($this->owner);

        $this->withHeader('Accept-Language', 'ha, en;q=0.5')->getJson('/api/v1/me/preferences')
            ->assertJsonPath('data.effective_locale', 'en')->assertHeader('Content-Language', 'en');

        $this->enableHausa();
        $this->withHeader('Accept-Language', 'fr;q=0.9, ha-NG;q=0.8, en;q=0.1')->getJson('/api/v1/me/preferences')
            ->assertJsonPath('data.effective_locale', 'ha')->assertHeader('Content-Language', 'ha');

        $this->owner->forceFill(['locale' => 'en'])->save();
        $this->withHeader('Accept-Language', 'ha')->getJson('/api/v1/me/preferences')
            ->assertJsonPath('data.effective_locale', 'en', 'saved choice beats Accept-Language');

        $this->owner->forceFill(['locale' => 'yo'])->save(); // registered but not available: ignored
        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/me/preferences')->assertJsonPath('data.effective_locale', 'en');
    }

    // ------------------------------------------------------------------ data is never locale-dependent

    public function test_money_dates_and_error_codes_are_identical_in_every_locale(): void
    {
        $this->bootClock();
        $this->signInAs($this->owner);
        $this->invoicedSale('3000.50');
        $this->enableHausa();
        $this->owner->forceFill(['locale' => null])->save();

        $en = $this->getJson('/api/v1/sales')->assertOk()->json('data');
        $ha = $this->withHeader('Accept-Language', 'ha')->getJson('/api/v1/sales')->assertOk()->json('data');

        $this->assertSame($en, $ha);
        $this->assertStringContainsString('3000.50', json_encode($en));
        $this->assertMatchesRegularExpression('/"recorded_at":"\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d+)?Z"/', json_encode($en), 'timestamps stay UTC');

        $a = $this->postJson('/api/v1/sales', [])->assertUnprocessable()->json();
        $b = $this->withHeader('Accept-Language', 'ha')->postJson('/api/v1/sales', [])->assertUnprocessable()->json();
        $this->assertSame(array_keys($a), array_keys($b));
        $this->assertSame($a['code'] ?? null, $b['code'] ?? null);
    }

    public function test_lagos_day_boundary_is_unaffected_by_locale(): void
    {
        // 23:30 UTC on the 14th is 00:30 on the 15th in Lagos.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 23:30:00', 'UTC'));
        $this->signInAs($this->owner);
        $this->enableHausa();

        $en = $this->getJson('/api/v1/dashboard')->assertOk()->json('data');
        $ha = $this->withHeader('Accept-Language', 'ha')->getJson('/api/v1/dashboard')->assertOk()->json('data');

        $this->assertSame($en, $ha);
        $this->assertStringContainsString('2026-10-15', json_encode($en), 'farm-local (Lagos) date, not the UTC date');
    }

    public function test_authoritative_numbers_are_not_reinterpreted_for_decimal_commas(): void
    {
        $this->signInAs($this->owner);
        $this->enableHausa();

        foreach (['en', 'ha'] as $lang) {
            $this->withHeader('Accept-Language', $lang)->postJson('/api/v1/measurements/normalize', ['components' => [['quantity' => '12,5', 'unit' => 'kg']]])
                ->assertStatus(422)->assertJsonPath('code', 'invalid_quantity');
        }
        $ok = $this->withHeader('Accept-Language', 'ha')->postJson('/api/v1/measurements/normalize', ['components' => [['quantity' => '12.50', 'unit' => 'kg']]])->assertOk()->json('data');
        $this->assertStringContainsString('12.5', json_encode($ok));
    }

    public function test_locale_responses_set_vary_and_content_language_headers(): void
    {
        $this->getJson('/api/v1/locales')->assertHeader('Content-Language', 'en')->assertHeader('Vary', 'Accept-Language, Origin');
    }

    public function test_farm_isolation_other_users_cannot_see_my_locale_via_account(): void
    {
        $this->owner->forceFill(['locale' => 'en'])->save();
        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);

        $this->getJson('/api/v1/account')->assertOk()->assertJsonPath('data.id', $otherOwner->id)->assertJsonPath('data.locale', null);
        $this->assertInstanceOf(User::class, $otherOwner);
    }
}
