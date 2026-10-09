<?php

namespace App\Http\Requests\Marketplace;

use App\Services\Marketplace\MarketplaceProductCatalogue;
use Illuminate\Validation\Rule;

/** Shared shape rules for creating and updating a listing. Cross-field rules (units, precision, minimum order) live in MarketplaceListingRules. */
trait ListingFields
{
    /** @return array<string, mixed> */
    protected function listingRules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            /** Public listing title (3-150 characters). */
            'title' => [$req, 'string', 'min:3', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:3000'],
            /** livestock | fish | crop_produce | eggs | milk | feed | other (see GET /marketplace/product-options). */
            'product_kind' => [$req, 'string', Rule::in(MarketplaceProductCatalogue::kinds())],
            /** An active species from the existing master data (livestock, fish; optional producer for eggs / milk). */
            'species_id' => ['sometimes', 'nullable', 'uuid'],
            /** An active crop type from the existing master data (crop_produce). */
            'crop_type_id' => ['sometimes', 'nullable', 'uuid'],
            /** Names what master data does not cover; required for `other`. */
            'custom_product_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            /** Selling unit code; the allowed codes depend on `product_kind`. */
            'unit' => [$req, 'string', 'max:30'],
            /** Naira per ONE selling unit, as a decimal (string preferred), at most 2 decimals. */
            'unit_price' => [$req, 'regex:/^\d+(\.\d+)?$/'],
            /** Seller-declared available quantity in the selling unit; the unit decides the allowed decimals. */
            'available_quantity' => [$req, 'regex:/^\d+(\.\d+)?$/'],
            'min_order_quantity' => ['sometimes', 'nullable', 'regex:/^\d+(\.\d+)?$/'],
            'negotiable' => ['sometimes', 'boolean'],
            /** What ONE package holds, stated by the seller. Required for container units (bag, sack, crate, tray, carton, bottle, basket). */
            'package' => ['sometimes', 'nullable', 'array'],
            'package.quantity' => ['required_with:package', 'regex:/^\d+(\.\d+)?$/'],
            'package.unit' => ['required_with:package', 'string', 'max:30'],
            'package.description' => ['sometimes', 'nullable', 'string', 'max:160'],
            /** pickup | seller_delivery | both. Default pickup. */
            'fulfilment' => ['sometimes', 'string', Rule::in(array_keys(MarketplaceProductCatalogue::FULFILMENT))],
            /** GENERAL public pickup area (not the exact address, which stays in the private shop contact). */
            'pickup_area' => ['sometimes', 'nullable', 'string', 'max:120'],
            /** States / cities the seller delivers to (seller-arranged delivery). */
            'delivery_coverage' => ['sometimes', 'nullable', 'array', 'max:20'],
            'delivery_coverage.*' => ['string', 'max:80'],
            /** same_day | 1_2_days | 3_5_days | within_week | to_be_agreed */
            'dispatch_estimate' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(MarketplaceProductCatalogue::DISPATCH))],
            /** included | agreed_separately */
            'delivery_charge' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(MarketplaceProductCatalogue::DELIVERY_CHARGE))],
            /** Public location; defaults to the shop's location when the listing is created. */
            'state' => ['sometimes', 'nullable', 'string', 'max:60'],
            'city' => ['sometimes', 'nullable', 'string', 'max:80'],
            'area' => ['sometimes', 'nullable', 'string', 'max:120'],
            /** A catalogue image from GET /marketplace/image-library (illustrative). Never a URL. */
            'catalog_image_id' => ['sometimes', 'nullable', 'uuid'],
            /** Optional inventory item of the shop's own farm (eggs, milk, feed, produce). Informational only: stock is never reserved or deducted. */
            'inventory_item_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
