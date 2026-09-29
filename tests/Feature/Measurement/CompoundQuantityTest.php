<?php

namespace Tests\Feature\Measurement;

use App\Enums\ConversionContextType;
use App\Models\CropType;
use App\Models\MeasurementContext;
use App\Services\Measurement\MeasurementConverter;
use App\Services\Measurement\PackageConversionService;
use App\Services\Measurement\QuantityNormalizer;
use App\Support\Measurement\ConversionContext;
use App\Support\Measurement\MeasurementException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompoundQuantityTest extends MeasurementTestCase
{
    private function normalizer(): QuantityNormalizer
    {
        return app(QuantityNormalizer::class);
    }

    private function rejects(string $code, callable $work): MeasurementException
    {
        try {
            $work();
        } catch (MeasurementException $e) {
            $this->assertSame($code, $e->errorCode);

            return $e;
        }
        $this->fail("Expected [{$code}] but nothing was thrown.");
    }

    public function test_three_crates_and_fourteen_pieces_normalize_to_104_pieces_preserving_the_entry(): void
    {
        $this->conversion($this->farm, 'Eggs', 'crate', 'piece', 30);

        $result = $this->normalizer()->normalize($this->farm, $this->parts([[3, 'crate'], [14, 'piece']]), $this->context('Eggs'));

        $this->assertSame(['quantity' => '104', 'unit' => 'piece'], $result->total->toArray());
        $this->assertSame(['quantity' => '104', 'unit' => 'piece'], $result->normalized->toArray()); // piece is its own canonical unit
        $this->assertSame([['quantity' => '3', 'unit' => 'crate'], ['quantity' => '14', 'unit' => 'piece']], array_map(fn ($q) => $q->toArray(), $result->entered));
        $this->assertSame('crate', $result->snapshot['entered'][0]['unit']['code']);
        $this->assertSame('30', $result->snapshot['packages'][0]['per_package']);
    }

    public function test_twelve_bags_and_eighteen_kg_need_a_configured_bag_weight(): void
    {
        $this->measurementContext($this->farm, 'Maize'); // a real context, but no bag definition yet
        $this->rejects('conversion_not_configured', fn () => $this->normalizer()->normalize($this->farm, $this->parts([[12, 'bag'], [18, 'kg']]), $this->context('Maize')));

        $this->conversion($this->farm, 'Maize', 'bag', 'kg', 50);
        $result = $this->normalizer()->normalize($this->farm, $this->parts([[12, 'bag'], [18, 'kg']]), $this->context('Maize'));

        $this->assertSame(['quantity' => '618', 'unit' => 'kg'], $result->total->toArray());
        $this->assertSame(['quantity' => '618000', 'unit' => 'g'], $result->normalized->toArray());
    }

    public function test_result_unit_can_be_chosen(): void
    {
        $this->conversion($this->farm, 'Maize', 'bag', 'kg', 50);

        $result = $this->normalizer()->normalize($this->farm, $this->parts([[12, 'bag'], [18, 'kg']]), $this->context('Maize'), 'tonne');

        $this->assertSame(['quantity' => '0.618', 'unit' => 'tonne'], $result->total->toArray());
    }

    public function test_feed_bag_and_maize_bag_coexist_and_never_leak_into_each_other(): void
    {
        $this->conversion($this->farm, 'Feed Grower Mash', 'bag', 'kg', 25);
        $this->conversion($this->farm, 'Maize', 'bag', 'kg', 50);

        $feed = $this->normalizer()->normalize($this->farm, $this->parts([[2, 'bag']]), $this->context('Feed Grower Mash'));
        $maize = $this->normalizer()->normalize($this->farm, $this->parts([[2, 'bag']]), $this->context('Maize'));

        $this->assertSame('50', $feed->total->value);
        $this->assertSame('100', $maize->total->value);
    }

    public function test_a_package_without_context_is_never_guessed(): void
    {
        $e = $this->rejects('conversion_context_required', fn () => $this->normalizer()->normalize($this->farm, $this->parts([[2, 'bag']])));
        $this->assertSame([], $e->details['candidates']);

        $this->conversion($this->farm, 'Maize', 'bag', 'kg', 50);
        $this->rejects('conversion_context_required', fn () => $this->normalizer()->normalize($this->farm, $this->parts([[2, 'bag']])));

        $this->conversion($this->farm, 'Feed', 'bag', 'kg', 25);
        $e = $this->rejects('ambiguous_conversion', fn () => $this->normalizer()->normalize($this->farm, $this->parts([[2, 'bag']])));
        $this->assertCount(2, $e->details['candidates']);
        $this->assertSame(['Feed', 'Maize'], array_column($e->details['candidates'], 'label'));
    }

    public function test_a_context_only_supplies_the_packages_it_defines(): void
    {
        $this->conversion($this->farm, 'Eggs', 'crate', 'piece', 30);

        $e = $this->rejects('conversion_not_configured', fn () => $this->normalizer()->normalize($this->farm, $this->parts([[1, 'bag']]), $this->context('Eggs')));
        $this->assertSame('bag', $e->details['unit']);
        // a real context that has no definitions at all
        $this->measurementContext($this->farm, 'Nothing configured');
        $this->rejects('conversion_not_configured', fn () => $this->normalizer()->normalize($this->farm, $this->parts([[1, 'crate']]), $this->context('Nothing configured')));
    }

    public function test_another_farms_conversion_and_context_are_never_applied(): void
    {
        [, $other] = $this->otherFarm();
        $this->conversion($other, 'Eggs', 'crate', 'piece', 12);
        $foreignEggs = $this->context('Eggs', $other);

        // farm B's context id cannot be used by farm A, even though its conversion exists
        $this->assertContextRejected(fn () => $this->normalizer()->normalize($this->farm, $this->parts([[1, 'crate']]), $foreignEggs));

        $this->conversion($this->farm, 'Eggs', 'crate', 'piece', 30);
        $this->assertSame('30', $this->normalizer()->normalize($this->farm, $this->parts([[1, 'crate']]), $this->context('Eggs'))->total->value);
        $this->assertSame('12', $this->normalizer()->normalize($other, $this->parts([[1, 'crate']]), $foreignEggs)->total->value);
        $this->assertContextRejected(fn () => $this->normalizer()->normalize($other, $this->parts([[1, 'crate']]), $this->context('Eggs')));
    }

    // ---- context identity ----------------------------------------------------------------------------------------

    private function assertContextRejected(callable $work, string $field = 'context.id'): void
    {
        try {
            $work();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());

            return;
        }
        $this->fail('Expected the context to be rejected.');
    }

    public function test_a_context_is_identified_by_its_id_not_by_its_name(): void
    {
        $conversion = $this->conversion($this->farm, 'Feed Grower Mash', 'bag', 'kg', 25);
        $context = $this->context('Feed Grower Mash');
        $this->assertSame($conversion->context_id, $context->id);
        $this->assertTrue(Str::isUuid($context->id));

        // a context that merely has the same name in the request text is not a context: only ids resolve
        $this->assertContextRejected(fn () => $this->normalizer()->normalize($this->farm, $this->parts([[1, 'bag']]), new ConversionContext(ConversionContextType::Custom, 'feed-grower-mash')));
        $this->assertContextRejected(fn () => $this->normalizer()->normalize($this->farm, $this->parts([[1, 'bag']]), new ConversionContext(ConversionContextType::Custom, 'Feed Grower Mash')));
        $this->assertContextRejected(fn () => $this->normalizer()->normalize($this->farm, $this->parts([[1, 'bag']]), new ConversionContext(ConversionContextType::Custom, (string) Str::uuid())));
    }

    public function test_renaming_a_context_keeps_its_conversions_and_identity_but_not_the_old_snapshot_label(): void
    {
        $this->conversion($this->farm, 'Feed Grower Mash', 'bag', 'kg', 25);
        $context = $this->context('Feed Grower Mash');
        $before = $this->normalizer()->normalize($this->farm, $this->parts([[2, 'bag']]), $context);

        MeasurementContext::findOrFail($context->id)->update(['name' => 'Grower Mash 25kg']);

        // same id still resolves, same conversion applies, the new name is read from the context
        $after = $this->normalizer()->normalize($this->farm, $this->parts([[2, 'bag']]), $context);
        $this->assertSame('50', $after->total->value);
        $this->assertSame($context->id, $after->snapshot['packages'][0]['context']['id']);
        $this->assertSame('Grower Mash 25kg', $after->snapshot['packages'][0]['context']['label']);

        // the earlier snapshot keeps the name it was taken under and still replays offline
        $this->assertSame('Feed Grower Mash', $before->snapshot['packages'][0]['context']['label']);
        $replayed = app(MeasurementConverter::class)->replay(json_decode(json_encode($before->snapshot), true));
        $this->assertSame('50', $replayed->total->value);
        $this->assertSame($before->snapshot, $replayed->snapshot);
    }

    public function test_inactive_and_unknown_crop_contexts_are_rejected(): void
    {
        $maize = CropType::where('code', 'maize')->firstOrFail();
        $resolved = app(PackageConversionService::class)->resolveContext($this->farm, ConversionContextType::CropType, $maize->id);
        $this->assertSame($maize->name, $resolved->label);

        $this->assertContextRejected(fn () => $this->normalizer()->normalize($this->farm, $this->parts([[1, 'bag']]), new ConversionContext(ConversionContextType::CropType, (string) Str::uuid())));

        CropType::whereKey($maize->id)->update(['is_active' => false]);
        $this->assertContextRejected(fn () => $this->normalizer()->normalize($this->farm, $this->parts([[1, 'bag']]), new ConversionContext(ConversionContextType::CropType, $maize->id)));
    }

    public function test_a_deactivated_context_stops_resolving_for_new_entries(): void
    {
        $this->conversion($this->farm, 'Eggs', 'crate', 'piece', 30);
        $context = $this->context('Eggs');
        MeasurementContext::findOrFail($context->id)->update(['is_active' => false]);

        $this->assertContextRejected(fn () => $this->normalizer()->normalize($this->farm, $this->parts([[1, 'crate']]), $context));
    }

    public function test_inactive_conversion_does_not_resolve_for_new_entries(): void
    {
        $this->conversion($this->farm, 'Eggs', 'crate', 'piece', 30, active: false);

        $e = $this->rejects('conversion_not_configured', fn () => $this->normalizer()->normalize($this->farm, $this->parts([[1, 'crate']]), $this->context('Eggs')));
        $this->assertTrue($e->details['inactive']);
    }

    public function test_mixed_incompatible_compound_quantities_are_rejected(): void
    {
        $this->conversion($this->farm, 'Eggs', 'crate', 'piece', 30);

        $this->rejects('incompatible_units', fn () => $this->normalizer()->normalize($this->farm, $this->parts([[3, 'crate'], [2, 'kg']]), $this->context('Eggs')));
        $this->rejects('incompatible_units', fn () => $this->normalizer()->normalize($this->farm, $this->parts([[5, 'kg'], [2, 'l']])));
        $this->rejects('incompatible_units', fn () => $this->normalizer()->normalize($this->farm, $this->parts([[3, 'crate'], [2, 'egg']]), $this->context('Eggs'))); // pieces are not eggs
    }

    public function test_result_unit_must_be_compatible_and_units_may_not_repeat(): void
    {
        $this->rejects('incompatible_units', fn () => $this->normalizer()->normalize($this->farm, $this->parts([[5, 'kg']]), null, 'l'));
        $this->rejects('incompatible_units', fn () => $this->normalizer()->normalize($this->farm, $this->parts([[5, 'kg']]), null, 'bag'));
        $this->rejects('invalid_quantity', fn () => $this->normalizer()->normalize($this->farm, $this->parts([[5, 'kg'], [1, 'kg']])));
    }

    public function test_fractional_packages_are_fine_unless_they_produce_a_fractional_count(): void
    {
        $this->conversion($this->farm, 'Feed', 'bag', 'kg', 25);
        $this->conversion($this->farm, 'Eggs', 'crate', 'piece', 30);

        $this->assertSame('12.5', $this->normalizer()->normalize($this->farm, $this->parts([['0.5', 'bag']]), $this->context('Feed'))->total->value);
        $this->assertSame('15', $this->normalizer()->normalize($this->farm, $this->parts([['0.5', 'crate']]), $this->context('Eggs'))->total->value);
        $e = $this->rejects('invalid_quantity', fn () => $this->normalizer()->normalize($this->farm, $this->parts([['0.55', 'crate']]), $this->context('Eggs')));
        $this->assertSame('fraction_not_allowed', $e->details['reason']);
    }

    public function test_domain_fields_can_require_dimensions(): void
    {
        $this->conversion($this->farm, 'Feed', 'bag', 'kg', 25);

        // water requires volume: kg (or a bag that resolves to kg) is refused
        $this->rejects('unit_dimension_mismatch', fn () => $this->normalizer()->normalize($this->farm, $this->parts([[5, 'kg']]), null, null, ['volume']));
        $this->rejects('unit_dimension_mismatch', fn () => $this->normalizer()->normalize($this->farm, $this->parts([[1, 'bag']]), $this->context('Feed'), null, ['volume']));
        $this->assertSame('5', $this->normalizer()->normalize($this->farm, $this->parts([[5, 'l']]), null, null, ['volume'])->total->value);
        // a medicine dose may be weight or volume
        $this->assertSame('5', $this->normalizer()->normalize($this->farm, $this->parts([[5, 'ml']]), null, null, ['weight', 'volume'])->total->value);
    }

    public function test_unknown_and_inactive_units_are_rejected_for_new_entries(): void
    {
        $this->rejects('unknown_unit', fn () => $this->normalizer()->normalize($this->farm, $this->parts([[5, 'furlong']])));

        $this->unit('lb')->update(['is_active' => false]);
        $this->rejects('unit_not_selectable', fn () => $this->normalizer()->normalize($this->farm, $this->parts([[5, 'lb']])));
    }

    public function test_planting_units_do_not_imply_planting_material(): void
    {
        $this->rejects('incompatible_units', fn () => $this->normalizer()->normalize($this->farm, $this->parts([[50, 'planting_unit']]), null, 'kg'));
        $this->rejects('incompatible_units', fn () => $this->normalizer()->normalize($this->farm, $this->parts([[50, 'planting_unit']]), null, 'piece'));
        $this->assertSame('50', $this->normalizer()->normalize($this->farm, $this->parts([[50, 'planting_unit']]))->total->value);
    }

    public function test_snapshot_is_unaffected_by_later_configuration_changes_and_replays_offline(): void
    {
        $conversion = $this->conversion($this->farm, 'Eggs', 'crate', 'piece', 30);
        $before = $this->normalizer()->normalize($this->farm, $this->parts([[3, 'crate'], [14, 'piece']]), $this->context('Eggs'));

        // the farm later changes its mind, then switches the definition off, and the platform relabels the unit
        $conversion->update(['quantity_per_package' => '12', 'version' => 2]);
        $conversion->update(['is_active' => false]);
        $this->unit('crate')->update(['name' => 'Egg crate']);

        $this->rejects('conversion_not_configured', fn () => $this->normalizer()->normalize($this->farm, $this->parts([[3, 'crate'], [14, 'piece']]), $this->context('Eggs')));

        // the stored snapshot still says what happened and reproduces the recorded numbers with no database rows
        $replayed = app(MeasurementConverter::class)->replay(json_decode(json_encode($before->snapshot), true));
        $this->assertSame('104', $replayed->total->value);
        $this->assertSame($before->snapshot, $replayed->snapshot);
        $this->assertSame(1, $before->snapshot['packages'][0]['version']);
        $this->assertSame($conversion->id, $before->snapshot['packages'][0]['conversion_id']);
        $this->assertSame('Eggs', $before->snapshot['packages'][0]['context']['label']);
        $this->assertSame($this->context('Eggs')->id, $before->snapshot['packages'][0]['context']['id']);
    }

    public function test_editing_one_context_does_not_change_another(): void
    {
        $feed = $this->conversion($this->farm, 'Feed', 'bag', 'kg', 25);
        $this->conversion($this->farm, 'Maize', 'bag', 'kg', 50);

        $feed->update(['quantity_per_package' => '20']);

        $this->assertSame('40', $this->normalizer()->normalize($this->farm, $this->parts([[2, 'bag']]), $this->context('Feed'))->total->value);
        $this->assertSame('100', $this->normalizer()->normalize($this->farm, $this->parts([[2, 'bag']]), $this->context('Maize'))->total->value);
    }
}
