<?php

namespace Tests\Feature\Measurement;

use App\Models\MeasurementDimension;
use App\Models\Unit;
use App\Services\Measurement\MeasurementConverter;
use App\Support\Measurement\Decimal;
use App\Support\Measurement\MeasurementException;
use Database\Seeders\MeasurementSeeder;
use LogicException;

class StandardConversionTest extends MeasurementTestCase
{
    private function convert(string|int $value, string $from, string $to): string
    {
        return app(MeasurementConverter::class)->convert($this->qty($value, $from), $this->spec($to))->value;
    }

    private function assertRejected(string $code, callable $work): MeasurementException
    {
        try {
            $work();
        } catch (MeasurementException $e) {
            $this->assertSame($code, $e->errorCode);

            return $e;
        }
        $this->fail("Expected [{$code}] but nothing was thrown.");
    }

    public function test_dimensions_and_units_are_provisioned_with_stable_unique_codes(): void
    {
        $this->assertEqualsCanonicalizing(['weight', 'volume', 'area', 'count', 'temperature', 'package'], MeasurementDimension::pluck('code')->all());

        $this->assertEqualsCanonicalizing(['mg', 'g', 'kg', 'tonne', 'lb'], $this->codes('weight'));
        $this->assertEqualsCanonicalizing(['ml', 'cl', 'l'], $this->codes('volume'));
        $this->assertEqualsCanonicalizing(['sq_m', 'hectare', 'acre'], $this->codes('area'));
        $this->assertEqualsCanonicalizing(['celsius', 'fahrenheit'], $this->codes('temperature'));
        $this->assertEqualsCanonicalizing(['piece', 'egg', 'head', 'planting_unit'], $this->codes('count'));
        $this->assertEqualsCanonicalizing(['bag', 'sack', 'crate', 'tray', 'carton', 'bottle'], $this->codes('package'));

        $this->assertSame(Unit::count(), Unit::distinct()->count('code'));
    }

    private function codes(string $dimension): array
    {
        return Unit::whereHas('dimension', fn ($q) => $q->where('code', $dimension))->pluck('code')->all();
    }

    public function test_every_convertible_family_has_exactly_one_canonical_unit_and_packages_have_no_factor(): void
    {
        foreach (Unit::whereNotNull('family')->get()->groupBy('family') as $family => $units) {
            $canonical = $units->where('is_canonical', true);
            $this->assertCount(1, $canonical, "family {$family}");
            $this->assertSame('1', Decimal::trim((string) $canonical->first()->to_canonical_factor));
        }

        foreach (Unit::whereHas('dimension', fn ($q) => $q->where('code', 'package'))->get() as $package) {
            $this->assertNull($package->family, $package->code);
            $this->assertNull($package->to_canonical_factor, $package->code);
            $this->assertFalse($package->is_canonical);
        }
    }

    public function test_seeder_is_idempotent_and_never_overwrites_admin_edits(): void
    {
        Unit::where('code', 'kg')->update(['name' => 'Kilo (edited)', 'is_active' => false]);
        $before = Unit::count();

        (new MeasurementSeeder)->run();

        $this->assertSame($before, Unit::count());
        $kg = $this->unit('kg');
        $this->assertSame('Kilo (edited)', $kg->name);
        $this->assertFalse($kg->is_active);
    }

    public function test_system_units_cannot_be_redefined_or_deleted(): void
    {
        $kg = $this->unit('kg');

        $this->expectException(LogicException::class);
        $kg->update(['to_canonical_factor' => '999']);
    }

    public function test_system_units_cannot_be_deleted(): void
    {
        $this->expectException(LogicException::class);
        $this->unit('hectare')->delete();
    }

    public function test_system_units_can_still_be_deactivated_and_relabelled(): void
    {
        $this->unit('lb')->update(['is_active' => false, 'name' => 'Pound (mass)']);

        $this->assertFalse($this->unit('lb')->is_active);
    }

    public function test_mass_volume_and_area_conversions_are_exact_in_both_directions(): void
    {
        $this->assertSame('1', $this->convert(1000, 'g', 'kg'));
        $this->assertSame('1000', $this->convert(1, 'kg', 'g'));
        $this->assertSame('2.5', $this->convert(2500, 'kg', 'tonne'));
        $this->assertSame('1', $this->convert(1000, 'kg', 'tonne'));
        $this->assertSame('1000000', $this->convert(1, 'tonne', 'g'));
        $this->assertSame('1', $this->convert(1000, 'ml', 'l'));
        $this->assertSame('0.25', $this->convert(250, 'ml', 'l'));
        $this->assertSame('100', $this->convert(10, 'cl', 'ml'));
        $this->assertSame('1', $this->convert(10000, 'sq_m', 'hectare'));
        $this->assertSame('10000', $this->convert(1, 'hectare', 'sq_m'));
        $this->assertSame('2.5', $this->convert(25000, 'sq_m', 'hectare'));
    }

    public function test_acre_and_pound_use_the_international_definitions(): void
    {
        $this->assertSame('4046.856422', $this->convert(1, 'acre', 'sq_m')); // 4046.8564224 rounded to 6 decimals
        $this->assertSame('0.404686', $this->convert(1, 'acre', 'hectare'));
        $this->assertSame('453.59237', $this->convert(1, 'lb', 'g'));
        $this->assertSame('0.45359237', Decimal::div('453.59237', '1000')); // exact kg value of one pound
    }

    public function test_no_floating_point_drift(): void
    {
        $this->assertSame('0.3', Decimal::add('0.1', '0.2'));

        $converter = app(MeasurementConverter::class);
        $sum = $converter->normalize([$this->qty('0.1', 'kg'), $this->qty('200', 'g')], [], $this->spec('kg'));
        $this->assertSame('0.3', $sum->total->value);
        $this->assertSame('300', $sum->normalized->value);

        $mixed = $converter->normalize([$this->qty('0.1', 'kg'), $this->qty('0.2', 'tonne')]);
        $this->assertSame('200100', $mixed->normalized->value);
    }

    public function test_same_unit_and_round_trip(): void
    {
        $this->assertSame('12.345', $this->convert('12.345', 'kg', 'kg'));
        $this->assertSame('1', $this->convert($this->convert(1, 'tonne', 'kg'), 'kg', 'tonne'));
        $this->assertSame('7.5', $this->convert($this->convert('7.5', 'kg', 'g'), 'g', 'kg'));
    }

    public function test_incompatible_dimensions_are_rejected_with_details(): void
    {
        foreach ([['kg', 'l'], ['hectare', 'kg'], ['celsius', 'kg'], ['ml', 'sq_m'], ['piece', 'kg'], ['kg', 'piece'], ['fahrenheit', 'l']] as [$from, $to]) {
            $e = $this->assertRejected('incompatible_units', fn () => $this->convert(1, $from, $to));
            $this->assertSame($from, $e->details['from_unit']);
            $this->assertSame($to, $e->details['to_unit']);
        }
    }

    public function test_packages_do_not_convert_without_context_and_have_no_universal_size(): void
    {
        foreach ([['bag', 'kg'], ['crate', 'piece'], ['tray', 'egg'], ['kg', 'bag']] as [$from, $to]) {
            $this->assertRejected('incompatible_units', fn () => $this->convert(1, $from, $to));
        }
    }

    public function test_count_units_are_semantically_separate(): void
    {
        $this->assertRejected('incompatible_units', fn () => $this->convert(50, 'head', 'egg'));
        $this->assertRejected('incompatible_units', fn () => $this->convert(50, 'head', 'piece'));
        $this->assertRejected('incompatible_units', fn () => $this->convert(30, 'planting_unit', 'piece'));
        $this->assertSame('50', $this->convert(50, 'head', 'head'));
    }

    public function test_discrete_units_reject_fractions(): void
    {
        $e = $this->assertRejected('invalid_quantity', fn () => $this->qty('2.5', 'head'));
        $this->assertSame('fraction_not_allowed', $e->details['reason']);
        $this->assertRejected('invalid_quantity', fn () => $this->qty('0.5', 'egg'));
        $this->assertSame('30', $this->qty(30, 'egg')->value);
    }

    public function test_quantity_validation_is_strict(): void
    {
        foreach (['abc', '', '1e5', '1,5', '1.1234567', '1234567890123', ' 5', '--1', '.5', null, [], true] as $bad) {
            $this->assertRejected('invalid_quantity', fn () => $this->qty($bad, 'kg'));
        }
        $this->assertRejected('invalid_quantity', fn () => $this->qty('-1', 'kg'));
        $this->assertSame('123456789012.123456', $this->qty('123456789012.123456', 'kg')->value);
        $this->assertSame('0.1', $this->qty(0.1, 'kg')->value); // JSON floats are accepted through their shortest text
    }

    public function test_temperature_converts_with_an_offset_not_a_factor(): void
    {
        $this->assertSame('212', $this->convert(100, 'celsius', 'fahrenheit'));
        $this->assertSame('100', $this->convert(212, 'fahrenheit', 'celsius'));
        $this->assertSame('32', $this->convert(0, 'celsius', 'fahrenheit'));
        $this->assertSame('0', $this->convert(32, 'fahrenheit', 'celsius'));
        $this->assertSame('-40', $this->convert(-40, 'celsius', 'fahrenheit'));
        $this->assertSame('-40', $this->convert(-40, 'fahrenheit', 'celsius'));
        $this->assertSame('37', $this->convert('98.6', 'fahrenheit', 'celsius'));
        $this->assertSame('98.6', $this->convert(37, 'celsius', 'fahrenheit'));
        $this->assertSame('-17.777778', $this->convert(0, 'fahrenheit', 'celsius')); // rounded half away from zero at 6 dp
    }

    public function test_temperatures_cannot_be_summed_or_mixed_with_other_dimensions(): void
    {
        $this->assertRejected('invalid_quantity', fn () => app(MeasurementConverter::class)->normalize([$this->qty(20, 'celsius'), $this->qty(5, 'fahrenheit')]));
        $this->assertRejected('incompatible_units', fn () => app(MeasurementConverter::class)->normalize([$this->qty(20, 'celsius'), $this->qty(5, 'kg')]));
    }

    public function test_rounding_is_half_away_from_zero_at_six_decimals(): void
    {
        $this->assertSame('0.333333', Decimal::round(Decimal::div('1', '3')));
        $this->assertSame('0.666667', Decimal::round(Decimal::div('2', '3')));
        $this->assertSame('-0.666667', Decimal::round('-0.6666666666'));
        $this->assertSame('1.000001', Decimal::round('1.0000005'));
    }

    public function test_too_large_results_are_rejected(): void
    {
        $this->assertRejected('invalid_quantity', fn () => app(MeasurementConverter::class)->normalize([$this->qty('999999999999', 'tonne')], [], $this->spec('mg')));
    }
}
