<?php

namespace App\Services\Marketplace;

use App\Models\CropType;
use App\Models\MarketplaceListing;
use App\Models\Species;
use App\Models\Unit;
use App\Support\Measurement\Decimal;
use App\Support\Measurement\MeasurementException;
use App\Support\Measurement\Quantity;
use Illuminate\Validation\ValidationException;

/**
 * Cross-field business rules for a listing: product identity against master data, selling unit against product kind, decimal-safe price and
 * quantity precision per unit, minimum order against available quantity, and the seller-declared package statement.
 *
 * Everything is a decimal STRING (bcmath via Decimal); no float is ever used for money or quantity. Package statements are DESCRIPTIVE: nothing
 * here (or anywhere) converts a package into another unit or into stock.
 */
class MarketplaceListingRules
{
    public const MAX_PRICE = '9999999999.99';

    public const CURRENCY = 'NGN';

    /**
     * Validates the EFFECTIVE state of a listing (stored values overlaid with the request) and returns the column values to persist.
     * Throws ValidationException keyed by request field names.
     *
     * @param  array<string, mixed>  $e  effective state: product_kind, species_id, crop_type_id, custom_product_name, unit (code), unit_price,
     *                                   available_quantity, min_order_quantity, package (array|null), fulfilment, pickup_area, delivery_coverage,
     *                                   dispatch_estimate, delivery_charge
     * @return array<string, mixed>
     */
    public function resolve(array $e): array
    {
        $kind = $e['product_kind'];
        $out = ['product_kind' => $kind] + $this->product($e, $kind);

        $unit = $this->sellingUnit($e['unit'] ?? null, $kind);
        $out['unit_id'] = $unit->id;
        $out['unit_price'] = $this->price($e['unit_price'] ?? null);
        $out['currency'] = self::CURRENCY;
        $out['available_quantity'] = $this->quantity('available_quantity', $e['available_quantity'] ?? null, $unit);
        $min = $e['min_order_quantity'] ?? null;
        $out['min_order_quantity'] = $min === null ? null : $this->quantity('min_order_quantity', $min, $unit);
        if ($out['min_order_quantity'] !== null && Decimal::cmp($out['min_order_quantity'], $out['available_quantity']) > 0) {
            throw ValidationException::withMessages(['min_order_quantity' => 'The minimum order cannot exceed the available quantity.']);
        }

        return $out + $this->package($e['package'] ?? null, $unit) + $this->fulfilment($e);
    }

    /** Total for a quantity at a unit price, rounded half away from zero to kobo. A non-binding preview, never an agreed total. */
    public function total(string $unitPrice, string $quantity): array
    {
        $total = Decimal::round(Decimal::mul($unitPrice, $quantity), 2);

        return ['total' => $this->money($total), 'total_minor' => $this->minor($total)];
    }

    /** "8000" -> "8000.00" */
    public function money(string $amount): string
    {
        return bcadd($amount, '0', 2);
    }

    /** Kobo as an integer. Exact: the amount has at most 2 decimals, so no rounding or float is involved. */
    public function minor(string $amount): int
    {
        return (int) bcmul($amount, '100', 0);
    }

    /** Validates a buyer-style quantity for an existing listing's unit (decimal places / whole numbers). */
    public function quantityFor(string $field, mixed $value, Unit $unit): string
    {
        return $this->quantity($field, $value, $unit);
    }

    /**
     * A buyer's quantity for a listing: the unit's precision, the minimum order and the declared available quantity. Throws a 422 keyed `quantity`.
     * Used by the price estimate, offers and purchase intents so all three agree.
     */
    public function orderableQuantity(MarketplaceListing $listing, mixed $value): string
    {
        $listing->loadMissing('unit');
        $qty = $this->quantityFor('quantity', $value, $listing->unit);
        if ($listing->min_order_quantity !== null && Decimal::cmp($qty, Decimal::trim((string) $listing->min_order_quantity)) < 0) {
            throw ValidationException::withMessages(['quantity' => 'The quantity is below the minimum order of '.Decimal::trim((string) $listing->min_order_quantity).'.']);
        }
        if (Decimal::cmp($qty, Decimal::trim((string) $listing->available_quantity)) > 0) {
            throw ValidationException::withMessages(['quantity' => 'The quantity exceeds the quantity the seller has declared as available.']);
        }

        return $qty;
    }

    // ------------------------------------------------------------------ parts

    /** @return array<string, mixed> */
    private function product(array $e, string $kind): array
    {
        $speciesId = $e['species_id'] ?? null;
        $cropId = $e['crop_type_id'] ?? null;
        $custom = isset($e['custom_product_name']) ? trim((string) $e['custom_product_name']) : null;
        $custom = $custom === '' ? null : $custom;
        $errors = [];

        if (in_array($kind, [MarketplaceProductCatalogue::LIVESTOCK, MarketplaceProductCatalogue::FISH, MarketplaceProductCatalogue::EGGS, MarketplaceProductCatalogue::MILK], true)) {
            if ($cropId !== null) {
                $errors['crop_type_id'] = 'A crop type does not apply to this product kind.';
            }
            if ($speciesId !== null) {
                $species = Species::with('operationType')->active()->find($speciesId);
                if ($species === null) {
                    $errors['species_id'] = 'Choose an active species from the product options.';
                } elseif (! MarketplaceProductCatalogue::speciesFits($species, $kind)) {
                    $errors['species_id'] = 'This species does not fit the selected product kind.';
                }
            }
        } elseif ($kind === MarketplaceProductCatalogue::CROP) {
            if ($speciesId !== null) {
                $errors['species_id'] = 'A species does not apply to crops and produce.';
            }
            if ($cropId !== null && ! CropType::active()->whereKey($cropId)->exists()) {
                $errors['crop_type_id'] = 'Choose an active crop type from the product options.';
            }
        } else {
            foreach (['species_id' => $speciesId, 'crop_type_id' => $cropId] as $field => $value) {
                if ($value !== null) {
                    $errors[$field] = 'This does not apply to the selected product kind.';
                }
            }
        }

        // Master-data kinds need a master-data reference OR a name for what master data does not cover. A name WITH a reference is allowed and
        // refines it ("fish" + "Catfish"); master data is never replaced by free text.
        if ($errors === []) {
            if (in_array($kind, MarketplaceProductCatalogue::MASTER_DATA_KINDS, true) && $speciesId === null && $cropId === null && $custom === null) {
                $errors['custom_product_name'] = 'Choose a product from the list, or name the product.';
            }
            if ($kind === MarketplaceProductCatalogue::OTHER && $custom === null) {
                $errors['custom_product_name'] = 'Name the product.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['species_id' => $speciesId, 'crop_type_id' => $cropId, 'custom_product_name' => $custom];
    }

    private function sellingUnit(mixed $code, string $kind): Unit
    {
        $allowed = MarketplaceProductCatalogue::unitsFor($kind);
        if (! is_string($code) || ! in_array($code, $allowed, true)) {
            throw ValidationException::withMessages(['unit' => 'This unit cannot be used for the selected product kind. Allowed: '.implode(', ', $allowed).'.']);
        }
        $unit = Unit::with('dimension')->active()->where('code', $code)->first();
        if ($unit === null || ! $unit->dimension->is_active) {
            throw ValidationException::withMessages(['unit' => 'This unit is not available.']);
        }

        return $unit;
    }

    /** Positive NGN amount with at most 2 decimals, as an exact decimal string. */
    private function price(mixed $value): string
    {
        $parsed = Decimal::parse($value, 10);
        if ($parsed === null || str_contains($parsed, '.') && strlen(explode('.', $parsed)[1]) > 2) {
            throw ValidationException::withMessages(['unit_price' => 'The unit price must be an amount in naira with at most 2 decimal places.']);
        }
        if (Decimal::cmp($parsed, '0') <= 0 || Decimal::cmp($parsed, self::MAX_PRICE) > 0) {
            throw ValidationException::withMessages(['unit_price' => 'The unit price must be greater than zero.']);
        }

        return $this->money($parsed);
    }

    /** Positive decimal quantity honouring the unit: whole numbers for indivisible units, otherwise at most the unit's decimal places. */
    private function quantity(string $field, mixed $value, Unit $unit): string
    {
        try {
            $q = Quantity::of($value, $unit->spec());
        } catch (MeasurementException $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
        if (Decimal::cmp($q->value, '0') <= 0) {
            throw ValidationException::withMessages([$field => 'The quantity must be greater than zero.']);
        }
        $places = str_contains($q->value, '.') ? strlen(explode('.', $q->value)[1]) : 0;
        if ($places > $unit->decimal_places) {
            throw ValidationException::withMessages([$field => $unit->decimal_places === 0
                ? "{$unit->name} is counted in whole numbers; fractions are not allowed."
                : "At most {$unit->decimal_places} decimal places are allowed for {$unit->name}."]);
        }

        return bcadd($q->value, '0', Decimal::SCALE);   // storage format (decimal(24,6)) so an unchanged value is never seen as a change
    }

    /**
     * What ONE package holds, as stated by the seller. Required for container units, rejected for every other unit. Stored as text-level
     * information: it is never used to convert the listing quantity into another unit, nor to move stock.
     *
     * @return array<string, mixed>
     */
    private function package(mixed $package, Unit $unit): array
    {
        $none = ['package_basis_unit_id' => null, 'package_quantity' => null, 'package_description' => null];
        $isContainer = MarketplaceProductCatalogue::isContainer($unit->code);
        $given = is_array($package) && array_filter($package, fn ($v) => $v !== null && $v !== '') !== [];

        if (! $isContainer) {
            if ($given) {
                throw ValidationException::withMessages(['package' => "Package details only apply to container units; {$unit->name} needs none."]);
            }

            return $none;
        }
        if (! $given || ! isset($package['quantity'], $package['unit'])) {
            throw ValidationException::withMessages(['package' => "State what one {$unit->code} holds (package.quantity and package.unit). Contents are never assumed."]);
        }
        $content = is_string($package['unit']) && in_array($package['unit'], MarketplaceProductCatalogue::CONTENT_UNITS, true)
            ? Unit::with('dimension')->active()->where('code', $package['unit'])->first() : null;
        if ($content === null) {
            throw ValidationException::withMessages(['package.unit' => 'Choose a content unit: '.implode(', ', MarketplaceProductCatalogue::CONTENT_UNITS).'.']);
        }
        $description = isset($package['description']) ? trim((string) $package['description']) : null;

        return [
            'package_basis_unit_id' => $content->id,
            'package_quantity' => $this->quantity('package.quantity', $package['quantity'], $content),
            'package_description' => $description === '' ? null : $description,
        ];
    }

    /** @return array<string, mixed> */
    private function fulfilment(array $e): array
    {
        $mode = $e['fulfilment'] ?? 'pickup';
        if (! array_key_exists($mode, MarketplaceProductCatalogue::FULFILMENT)) {
            throw ValidationException::withMessages(['fulfilment' => 'Choose pickup, seller_delivery or both.']);
        }
        $pickup = MarketplaceProductCatalogue::offersPickup($mode);
        $delivery = MarketplaceProductCatalogue::offersDelivery($mode);
        $coverage = $delivery ? array_values(array_unique(array_map(fn ($v) => trim((string) $v), (array) ($e['delivery_coverage'] ?? [])))) : [];
        $coverage = array_values(array_filter($coverage, fn ($v) => $v !== ''));

        // The arrangement that does not apply to the chosen mode is dropped, so a pickup-only listing never advertises delivery details.
        return [
            'fulfilment' => $mode,
            'pickup_area' => $pickup ? ($e['pickup_area'] ?? null) : null,
            'delivery_coverage' => $coverage === [] ? null : $coverage,
            'dispatch_estimate' => $delivery ? ($e['dispatch_estimate'] ?? null) : null,
            'delivery_charge' => $delivery ? ($e['delivery_charge'] ?? null) : null,
        ];
    }
}
