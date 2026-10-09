<?php

namespace App\Services\Marketplace;

use App\Enums\OperationCategory;
use App\Models\CropType;
use App\Models\Species;
use App\Models\Unit;
use Illuminate\Support\Collection;

/**
 * The single definition of what can be listed and in which units. It adds no taxonomy of its own: livestock, fish and crops point at the
 * existing species / crop_types master data, and the allowed units are existing system units. The frontend reads this through
 * GET /marketplace/product-options instead of hard-coding the rules.
 */
class MarketplaceProductCatalogue
{
    public const LIVESTOCK = 'livestock';

    public const FISH = 'fish';

    public const CROP = 'crop_produce';

    public const EGGS = 'eggs';

    public const MILK = 'milk';

    public const FEED = 'feed';

    public const OTHER = 'other';

    /** kind => [label, selling units]. Order = picker order. */
    public const KINDS = [
        self::LIVESTOCK => ['Livestock', ['head']],
        self::FISH => ['Fish', ['kg', 'head']],
        self::CROP => ['Crops and produce', ['kg', 'tonne', 'piece', 'bag', 'sack', 'basket', 'crate', 'tuber', 'bunch']],
        self::EGGS => ['Eggs', ['egg', 'tray', 'crate', 'carton']],
        self::MILK => ['Milk', ['l', 'bottle']],
        self::FEED => ['Feed', ['kg', 'tonne', 'bag', 'sack']],
        self::OTHER => ['Other agricultural products', ['kg', 'g', 'l', 'piece', 'bag', 'sack', 'crate', 'tray', 'carton', 'bottle', 'basket', 'bunch', 'tuber']],
    ];

    /** Kinds whose identity comes from master data (or, failing that, a custom name). */
    public const MASTER_DATA_KINDS = [self::LIVESTOCK, self::FISH, self::CROP];

    /** Kinds that may be linked to the shop farm's inventory (livestock and fish are populations, not stock). */
    public const INVENTORY_KINDS = [self::CROP, self::EGGS, self::MILK, self::FEED, self::OTHER];

    /** Containers: the seller must state what one holds. Tuber and bunch are natural counting units and need no statement. */
    public const CONTAINER_UNITS = ['bag', 'sack', 'crate', 'tray', 'carton', 'bottle', 'basket'];

    /** Units a container's content may be declared in (never another container). */
    public const CONTENT_UNITS = ['kg', 'g', 'tonne', 'l', 'ml', 'piece', 'egg', 'head'];

    public const FULFILMENT = ['pickup' => 'Pickup only', 'seller_delivery' => 'Seller-arranged delivery only', 'both' => 'Pickup or seller-arranged delivery'];

    public const DISPATCH = ['same_day' => 'Same day', '1_2_days' => '1-2 days', '3_5_days' => '3-5 days', 'within_week' => 'Within a week', 'to_be_agreed' => 'To be agreed'];

    public const DELIVERY_CHARGE = ['included' => 'Delivery included in the price', 'agreed_separately' => 'Delivery charge agreed separately'];

    /** @return list<string> */
    public static function kinds(): array
    {
        return array_keys(self::KINDS);
    }

    /** @return list<string> */
    public static function unitsFor(string $kind): array
    {
        return self::KINDS[$kind][1];
    }

    public static function isContainer(string $unitCode): bool
    {
        return in_array($unitCode, self::CONTAINER_UNITS, true);
    }

    public static function offersDelivery(string $fulfilment): bool
    {
        return in_array($fulfilment, ['seller_delivery', 'both'], true);
    }

    public static function offersPickup(string $fulfilment): bool
    {
        return in_array($fulfilment, ['pickup', 'both'], true);
    }

    /** Whether a species belongs under a product kind (fish = aquaculture species, livestock = the other livestock species). */
    public static function speciesFits(Species $species, string $kind): bool
    {
        $aquatic = $species->operationType?->category === OperationCategory::Aquaculture;

        return match ($kind) {
            self::FISH => $aquatic,
            self::LIVESTOCK => ! $aquatic,
            default => true,   // eggs / milk may name any producing species; crops and feed never carry one (validated elsewhere)
        };
    }

    /** @return array<string, mixed> everything the product picker needs, in one authenticated, farm-less call */
    public function options(): array
    {
        $units = Unit::query()->with('dimension')->active()->whereIn('code', array_unique(array_merge(...array_values(array_map(fn ($k) => $k[1], self::KINDS)))))->get()->keyBy('code');
        $species = Species::query()->with('operationType')->active()->ordered()->get();
        $crops = CropType::query()->active()->ordered()->get();

        $kinds = [];
        foreach (self::KINDS as $code => [$label, $codes]) {
            $kinds[] = [
                'code' => $code, 'label' => $label,
                'units' => collect($codes)->map(fn (string $c) => $units->has($c) ? $this->unit($units[$c]) : null)->filter()->values()->all(),
                'products' => $this->products($code, $species, $crops),
                'allows_custom_name' => true, 'custom_name_required' => $code === self::OTHER,
                'inventory_linkable' => in_array($code, self::INVENTORY_KINDS, true),
            ];
        }

        return [
            'kinds' => $kinds,
            'fulfilment' => $this->vocabulary(self::FULFILMENT),
            'dispatch_estimates' => $this->vocabulary(self::DISPATCH),
            'delivery_charges' => $this->vocabulary(self::DELIVERY_CHARGE),
            'package_content_units' => array_values(array_filter(array_map(fn (string $c) => ($u = Unit::with('dimension')->where('code', $c)->first()) ? $this->unit($u) : null, self::CONTENT_UNITS))),
            'currency' => 'NGN',
            'limits' => [
                'max_images_per_listing' => (int) config('marketplace.images.max_per_listing'),
                'max_image_kilobytes' => (int) config('marketplace.images.max_kilobytes'),
                'image_mime_types' => array_keys(config('marketplace.images.mime_types')),
                'max_delivery_coverage_entries' => 20,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function unit(Unit $unit): array
    {
        return [
            'code' => $unit->code, 'name' => $unit->name, 'symbol' => $unit->symbol, 'integer_only' => $unit->integer_only, 'decimal_places' => $unit->decimal_places,
            'requires_package_details' => self::isContainer($unit->code),
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private function vocabulary(array $map): array
    {
        return array_map(fn ($value, $label) => ['value' => $value, 'label' => $label], array_keys($map), array_values($map));
    }

    /** @return list<array<string, mixed>> */
    private function products(string $kind, Collection $species, Collection $crops): array
    {
        return match ($kind) {
            self::LIVESTOCK, self::FISH => $species->filter(fn (Species $s) => self::speciesFits($s, $kind))->map(fn (Species $s) => ['type' => 'species', 'id' => $s->id, 'code' => $s->code, 'name' => $s->name])->values()->all(),
            self::EGGS, self::MILK => $species->filter(fn (Species $s) => self::speciesFits($s, $kind) && $s->operationType?->category?->value === 'livestock')->map(fn (Species $s) => ['type' => 'species', 'id' => $s->id, 'code' => $s->code, 'name' => $s->name])->values()->all(),
            self::CROP => $crops->map(fn (CropType $c) => ['type' => 'crop_type', 'id' => $c->id, 'code' => $c->code, 'name' => $c->name])->values()->all(),
            default => [],
        };
    }
}
