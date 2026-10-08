<?php

namespace Database\Seeders;

use App\Models\CropType;
use App\Models\MarketplaceCatalogImage;
use App\Models\Species;
use Illuminate\Database\Seeder;

/**
 * The curated illustrative-image CATALOGUE STRUCTURE. It seeds rows only - no image files and no URLs: `asset_path` stays NULL ("awaiting
 * asset") until a licensed asset is placed on the marketplace disk and the row is updated (docs/api/PHASE-23-IMAGE-ASSET-RUNBOOK.md). A row
 * without an asset is never offered by the picker, never served and never resolved onto a listing.
 *
 * INSERT-ONLY and keyed by `code`: re-running adds missing rows and never touches existing ones (including seeded assets and later edits).
 * Species / crop links point at the existing master data by code; where master data has no entry (catfish, rice, tomatoes) the row is matched
 * by label against the listing's product name instead.
 */
class MarketplaceImageCatalogueSeeder extends Seeder
{
    /** code => [label, product_kind, species code|null, crop code|null] */
    private const IMAGES = [
        'chicken' => ['Chicken', 'livestock', 'chicken', null],
        'goat' => ['Goat', 'livestock', 'goat', null],
        'cattle' => ['Cattle', 'livestock', 'cattle', null],
        'sheep' => ['Sheep', 'livestock', 'sheep', null],
        'pig' => ['Pig', 'livestock', 'pig', null],
        'rabbit' => ['Rabbit', 'livestock', 'rabbit', null],
        'fish' => ['Fish', 'fish', 'fish', null],
        'catfish' => ['Catfish', 'fish', null, null],
        'yam' => ['Yam', 'crop_produce', null, 'yam'],
        'cassava' => ['Cassava', 'crop_produce', null, 'cassava'],
        'maize' => ['Maize', 'crop_produce', null, 'maize'],
        'vegetables' => ['Vegetables', 'crop_produce', null, 'vegetables'],
        'fruits' => ['Fruits', 'crop_produce', null, 'fruits'],
        'rice' => ['Rice', 'crop_produce', null, null],
        'tomatoes' => ['Tomatoes', 'crop_produce', null, null],
        'eggs' => ['Eggs', 'eggs', null, null],
        'milk' => ['Milk', 'milk', null, null],
    ];

    /** product_kind => label of its category-level fallback */
    private const FALLBACKS = [
        'livestock' => 'Livestock', 'fish' => 'Fish', 'crop_produce' => 'Crops and produce', 'eggs' => 'Eggs', 'milk' => 'Milk', 'feed' => 'Feed', 'other' => 'Agricultural product',
    ];

    public function run(): void
    {
        $species = Species::pluck('id', 'code');
        $crops = CropType::pluck('id', 'code');
        $order = 0;
        foreach (self::IMAGES as $code => [$label, $kind, $speciesCode, $cropCode]) {
            MarketplaceCatalogImage::firstOrCreate(['code' => $code], [
                'label' => $label, 'product_kind' => $kind, 'species_id' => $speciesCode ? ($species[$speciesCode] ?? null) : null,
                'crop_type_id' => $cropCode ? ($crops[$cropCode] ?? null) : null, 'is_kind_fallback' => false,
                'alt_text' => "Illustration of $label", 'is_illustrative' => true, 'is_active' => true, 'sort_order' => ++$order,
            ]);
        }
        foreach (self::FALLBACKS as $kind => $label) {
            MarketplaceCatalogImage::firstOrCreate(['code' => "fallback-$kind"], [
                'label' => $label, 'product_kind' => $kind, 'is_kind_fallback' => true, 'alt_text' => "Illustration of $label",
                'is_illustrative' => true, 'is_active' => true, 'sort_order' => 1000 + ++$order,
            ]);
        }
    }
}
