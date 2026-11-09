<?php

namespace App\Http\Requests\Inventory;

use App\Enums\StockInReason;
use App\Rules\ManualStockReason;
use Illuminate\Validation\Rule;

class StockInRequest extends InventoryRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** The item receiving stock. Send exactly one of `inventory_item_id`, `new_item` or `output`. */
            'inventory_item_id' => ['required_without_all:output,new_item', 'uuid', 'prohibits:output,new_item'],
            /**
             * Add stock for something not yet in the item list, in one step: `{name, category, unit}` (plus the optional fields of POST /inventory/items:
             * tracks_lots, tracks_expiry, low_stock_threshold, description). An ACTIVE item with the same name and category is reused, never duplicated
             * (`item_reused: true` in the response); otherwise the item is created with the stock-in in one transaction. Same validation as POST /inventory/items.
             */
            'new_item' => ['sometimes', 'array:name,category,unit,tracks_lots,tracks_expiry,low_stock_threshold,description', 'prohibits:output'],
            ...self::newItemRules(),
            /** eggs | milk: the farm's own egg / milk stock (created automatically on first use), so no item has to be set up. For stock that was NOT produced on this farm (purchase, donation, received, opening balance, other); produced-on-farm eggs and milk are recorded with POST /records. */
            'output' => ['sometimes', Rule::in(['eggs', 'milk'])],
            /** Optional. Omitted: the farm's main store (created as "Main Store" on first use). Send it to receive into another store. */
            'storage_location_id' => ['sometimes', 'uuid'],
            /** Existing lot of this item. Use `lot` instead to receive into a (new or existing) lot by code. */
            'lot_id' => ['sometimes', 'nullable', 'uuid', 'prohibits:lot'],
            /** Lot code (and expiry for expiry-tracked items). An existing code of the same item is reused; a different expiry is rejected. */
            'lot' => ['sometimes', 'array:code,expires_on'],
            'lot.code' => ['required_with:lot', 'string', 'max:100'],
            'lot.expires_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'reason' => ['required', Rule::enum(StockInReason::class), new ManualStockReason('in')],
            ...InventoryRules::components('components'),
            ...InventoryRules::event(),
        ];
    }

    /** The item-creation rules (StoreItemRequest), re-keyed under new_item (stock_unit is called `unit` here) and only required when new_item is sent. */
    public static function newItemRules(string $prefix = 'new_item'): array
    {
        $rules = [];
        foreach ((new StoreItemRequest)->rules() as $key => $rule) {
            $key = $key === 'stock_unit' ? 'unit' : $key;
            $rules[$prefix.'.'.$key] = array_map(fn ($r) => $r === 'required' ? 'required_with:'.$prefix : (is_string($r) && str_starts_with($r, 'required_with:') ? 'required_with:'.$prefix.'.'.substr($r, 14) : $r), $rule);
        }

        return $rules;
    }
}
