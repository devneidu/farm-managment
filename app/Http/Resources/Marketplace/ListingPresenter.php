<?php

namespace App\Http\Resources\Marketplace;

use App\Models\MarketplaceListing;
use App\Models\Unit;
use App\Services\Marketplace\MarketplaceImageService;
use App\Services\Marketplace\MarketplaceListingRules;
use App\Services\Marketplace\MarketplaceProductCatalogue;
use App\Support\Measurement\Decimal;

/**
 * The parts of a listing that seller, public and admin views share, built once so the three can never disagree about money, quantity or units.
 * Money and quantities are DECIMAL STRINGS (`"8000.00"`, `"2.5"`); `amount_minor` is the exact kobo integer. Nothing here reads farm or inventory data.
 */
final class ListingPresenter
{
    public static function unit(Unit $unit): array
    {
        return ['code' => $unit->code, 'name' => $unit->name, 'symbol' => $unit->symbol, 'integer_only' => $unit->integer_only, 'decimal_places' => $unit->decimal_places];
    }

    /** @return array<string, mixed> */
    public static function product(MarketplaceListing $l): array
    {
        $label = MarketplaceProductCatalogue::KINDS[$l->product_kind][0];

        return [
            'kind' => $l->product_kind, 'kind_label' => $label,
            'species' => $l->species ? ['id' => $l->species->id, 'code' => $l->species->code, 'name' => $l->species->name] : null,
            'crop_type' => $l->cropType ? ['id' => $l->cropType->id, 'code' => $l->cropType->code, 'name' => $l->cropType->name] : null,
            'custom_name' => $l->custom_product_name,
            'name' => $l->custom_product_name ?? $l->species?->name ?? $l->cropType?->name ?? $label,
        ];
    }

    /** @return array<string, mixed> */
    public static function price(MarketplaceListing $l): array
    {
        $amount = app(MarketplaceListingRules::class)->money((string) $l->unit_price);

        return ['amount' => $amount, 'amount_minor' => app(MarketplaceListingRules::class)->minor($amount), 'currency' => $l->currency, 'per' => self::unit($l->unit)];
    }

    /** @return array<string, mixed> */
    public static function quantity(MarketplaceListing $l): array
    {
        return [
            'available' => Decimal::trim((string) $l->available_quantity),
            'min_order' => $l->min_order_quantity === null ? null : Decimal::trim((string) $l->min_order_quantity),
            'unit' => $l->unit->code,
            // Always the seller's own declaration, never live stock and never a guarantee.
            'basis' => 'seller_declared', 'updated_at' => $l->quantity_updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function package(MarketplaceListing $l): ?array
    {
        if ($l->package_quantity === null) {
            return null;
        }

        return [
            'quantity' => Decimal::trim((string) $l->package_quantity), 'unit' => $l->packageBasisUnit->code, 'description' => $l->package_description,
            'declared_by' => 'seller', 'is_conversion' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function fulfilment(MarketplaceListing $l): array
    {
        $delivery = MarketplaceProductCatalogue::offersDelivery($l->fulfilment);

        return [
            'mode' => $l->fulfilment, 'label' => MarketplaceProductCatalogue::FULFILMENT[$l->fulfilment],
            'pickup_area' => $l->pickup_area,
            'delivery_coverage' => $delivery ? ($l->delivery_coverage ?? []) : [],
            'dispatch_estimate' => $delivery && $l->dispatch_estimate ? ['value' => $l->dispatch_estimate, 'label' => MarketplaceProductCatalogue::DISPATCH[$l->dispatch_estimate]] : null,
            'delivery_charge' => $delivery && $l->delivery_charge ? ['value' => $l->delivery_charge, 'label' => MarketplaceProductCatalogue::DELIVERY_CHARGE[$l->delivery_charge]] : null,
            'arranged_by' => 'seller',
        ];
    }

    /** @return array<string, mixed> */
    public static function location(MarketplaceListing $l): array
    {
        return ['state' => $l->state, 'city' => $l->city, 'area' => $l->area];
    }

    /** @return array<string, mixed> */
    public static function image(MarketplaceListing $l, string $audience): array
    {
        return app(MarketplaceImageService::class)->resolve($l, $audience);
    }

    /** @return list<array<string, mixed>> seller photos only, in display order */
    public static function photos(MarketplaceListing $l, string $audience): array
    {
        $images = app(MarketplaceImageService::class);

        return $l->images->map(fn ($i) => [
            'id' => $i->id, 'url' => $images->photoUrl($i, $audience), 'alt_text' => $i->alt_text, 'position' => $i->position,
            'width' => $i->width, 'height' => $i->height, 'kind' => 'seller', 'illustrative' => false,
        ])->values()->all();
    }
}
