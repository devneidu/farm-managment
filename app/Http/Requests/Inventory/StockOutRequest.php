<?php

namespace App\Http\Requests\Inventory;

use App\Enums\StockOutReason;
use App\Rules\ManualStockReason;
use Illuminate\Validation\Rule;

class StockOutRequest extends InventoryRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** The item leaving stock. Omit when `output` is used. */
            'inventory_item_id' => ['required_without:output', 'uuid', 'prohibits:output'],
            /** eggs | milk: the farm's own egg / milk stock, so no item id is needed. Sales, incubation and feed use are recorded through their own workflows. */
            'output' => ['sometimes', Rule::in(['eggs', 'milk'])],
            /** Required, except with `output`: then the only active storage location is used and several active locations need a choice (422). */
            'storage_location_id' => ['required_without:output', 'uuid'],
            /** Required for lot-tracked items. There is no automatic FIFO/FEFO: the caller names the lot. */
            ...InventoryRules::lotChoice(),
            'reason' => ['required', Rule::enum(StockOutReason::class), new ManualStockReason('out')],
            ...InventoryRules::components('components'),
            ...InventoryRules::event(),
        ];
    }
}
