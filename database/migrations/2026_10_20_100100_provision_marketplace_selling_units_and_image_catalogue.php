<?php

use Database\Seeders\MarketplaceImageCatalogueSeeder;
use Database\Seeders\MeasurementSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Data migration (insert-only, keyed by stable codes): the selling units a marketplace needs that the measurement catalogue lacked (basket,
 * tuber, bunch - package units with NO family, so they convert to nothing) and the image-catalogue rows (without assets). Existing rows are
 * never touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new MeasurementSeeder)->run();
        (new MarketplaceImageCatalogueSeeder)->run();
    }

    public function down(): void
    {
        // Seeded rows are removed with their tables / left in place for units (system units are protected from deletion).
    }
};
