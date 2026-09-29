<?php

namespace Database\Seeders;

use App\Enums\ConversionStrategy;
use App\Models\MeasurementDimension;
use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Standard measurement reference data. Conservative on purpose: only units the product documentation names.
 * Package units (bag, crate, ...) are seeded WITHOUT any quantity - what a bag holds is a farm/context decision.
 *
 * INSERT-ONLY and keyed by stable `code`: existing rows (and later platform-admin edits) are never touched, so
 * re-running is safe. Provisioned by a migration; run `php artisan db:seed --class=MeasurementSeeder` to add missing rows.
 */
class MeasurementSeeder extends Seeder
{
    /** code => [name, supports_preference] (order = sort order) */
    private const DIMENSIONS = [
        'weight' => ['Weight', true],
        'volume' => ['Volume', true],
        'area' => ['Area', true],
        'count' => ['Count', false],
        'temperature' => ['Temperature', true],
        'package' => ['Package', false],
    ];

    /**
     * dimension => list of [code, name, symbol, factor to the canonical unit (string), decimal places, canonical?]
     * Factors are exact by definition (1 lb = 0.45359237 kg, 1 international acre = 4046.8564224 m2).
     * The dimension code doubles as the conversion family.
     */
    private const LINEAR = [
        'weight' => [
            ['mg', 'Milligram', 'mg', '0.001', 2, false],
            ['g', 'Gram', 'g', '1', 2, true],
            ['kg', 'Kilogram', 'kg', '1000', 3, false],
            ['tonne', 'Tonne', 't', '1000000', 3, false],
            ['lb', 'Pound', 'lb', '453.59237', 2, false],
        ],
        'volume' => [
            ['ml', 'Millilitre', 'ml', '1', 1, true],
            ['cl', 'Centilitre', 'cl', '10', 1, false],
            ['l', 'Litre', 'L', '1000', 2, false],
        ],
        'area' => [
            ['sq_m', 'Square metre', 'm²', '1', 2, true],
            ['hectare', 'Hectare', 'ha', '10000', 4, false],
            ['acre', 'Acre', 'ac', '4046.8564224', 4, false],
        ],
    ];

    /** Count units never convert into each other: each meaning is its own family (50 heads is not 50 eggs). */
    private const COUNT = [
        ['piece', 'Piece', 'pc'],
        ['egg', 'Egg', 'egg'],
        ['head', 'Head', 'head'],
        ['planting_unit', 'Planting unit', 'unit'],
    ];

    private const PACKAGES = [
        ['bag', 'Bag'], ['sack', 'Sack'], ['crate', 'Crate'], ['tray', 'Tray'], ['carton', 'Carton'], ['bottle', 'Bottle'],
    ];

    public function run(): void
    {
        $dimensions = [];
        $i = 0;
        foreach (self::DIMENSIONS as $code => [$name, $preference]) {
            $dimensions[$code] = MeasurementDimension::firstOrCreate(['code' => $code], [
                'name' => $name, 'supports_preference' => $preference, 'sort_order' => ++$i, 'is_active' => true,
            ]);
        }

        foreach (self::LINEAR as $dimension => $units) {
            foreach ($units as $n => [$code, $name, $symbol, $factor, $places, $canonical]) {
                $this->unit($dimensions[$dimension], $code, [
                    'name' => $name, 'symbol' => $symbol, 'family' => $dimension, 'is_canonical' => $canonical,
                    'conversion_strategy' => ConversionStrategy::Linear, 'to_canonical_factor' => $factor,
                    'decimal_places' => $places, 'integer_only' => false, 'sort_order' => $n + 1,
                ]);
            }
        }

        $this->unit($dimensions['temperature'], 'celsius', [
            'name' => 'Degree Celsius', 'symbol' => '°C', 'family' => 'temperature', 'is_canonical' => true,
            'conversion_strategy' => ConversionStrategy::Linear, 'to_canonical_factor' => '1', 'decimal_places' => 1,
            'integer_only' => false, 'sort_order' => 1,
        ]);
        $this->unit($dimensions['temperature'], 'fahrenheit', [
            'name' => 'Degree Fahrenheit', 'symbol' => '°F', 'family' => 'temperature', 'is_canonical' => false,
            'conversion_strategy' => ConversionStrategy::Fahrenheit, 'to_canonical_factor' => null, 'decimal_places' => 1,
            'integer_only' => false, 'sort_order' => 2,
        ]);

        foreach (self::COUNT as $n => [$code, $name, $symbol]) {
            $this->unit($dimensions['count'], $code, [
                'name' => $name, 'symbol' => $symbol, 'family' => $code, 'is_canonical' => true,
                'conversion_strategy' => ConversionStrategy::Linear, 'to_canonical_factor' => '1',
                'decimal_places' => 0, 'integer_only' => true, 'sort_order' => $n + 1,
            ]);
        }

        foreach (self::PACKAGES as $n => [$code, $name]) {
            $this->unit($dimensions['package'], $code, [
                'name' => $name, 'symbol' => $code, 'family' => null, 'is_canonical' => false,
                'conversion_strategy' => ConversionStrategy::None, 'to_canonical_factor' => null,
                'decimal_places' => 2, 'integer_only' => false, 'sort_order' => $n + 1,
            ]);
        }
    }

    private function unit(MeasurementDimension $dimension, string $code, array $attributes): void
    {
        Unit::firstOrCreate(['code' => $code], $attributes + ['dimension_id' => $dimension->id, 'is_system' => true, 'is_active' => true]);
    }
}
