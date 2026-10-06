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
            /** The item receiving stock. Omit when `output` is used. */
            'inventory_item_id' => ['required_without:output', 'uuid', 'prohibits:output'],
            /** eggs | milk: the farm's own egg / milk stock (created automatically on first use), so no item has to be set up. For stock that was NOT produced on this farm (purchase, donation, received, opening balance, other); produced-on-farm eggs and milk are recorded with POST /records. */
            'output' => ['sometimes', Rule::in(['eggs', 'milk'])],
            /** Required, except with `output`: then the only active storage location is used, a farm with none gets a "Main Store", and several active locations need a choice (422). */
            'storage_location_id' => ['required_without:output', 'uuid'],
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
}
