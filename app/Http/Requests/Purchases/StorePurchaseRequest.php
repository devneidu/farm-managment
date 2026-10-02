<?php

namespace App\Http\Requests\Purchases;

use App\Http\Requests\Inventory\InventoryRules;
use App\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** The supplier (an active contact with the supplier role). */
            'contact_id' => ['sometimes', 'nullable', 'uuid'],
            /** Supplier invoice or receipt number. */
            'supplier_reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            /** Allocates the cost to an open production cycle. */
            'production_cycle_id' => ['sometimes', 'nullable', 'uuid'],
            /** Book the purchase total as ONE expense transaction linked to this purchase (default true). */
            'record_expense' => ['sometimes', 'boolean'],
            /** Expense category; defaults from the lines (feed -> feed, medicine -> medicine_veterinary ...; mixed -> general_supplies). */
            'finance_category_id' => ['sometimes', 'nullable', 'uuid'],
            /** Replace a cancelled purchase once. Needs purchase.cancel. */
            'corrects_purchase_id' => ['sometimes', 'nullable', 'uuid'],
            ...InventoryRules::event(),
            /** 1-30 lines. Total = sum of line amounts. */
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*' => ['required', 'array:kind,description,inventory_item_id,storage_location_id,lot_id,lot,components,amount'],
            /** stock = received into inventory through a stock-in; non_stock = a cost with no physical stock (services, transport...). */
            'items.*.kind' => ['required', Rule::in(['stock', 'non_stock'])],
            /** Required for non_stock lines; stock lines use the item name. */
            'items.*.description' => ['sometimes', 'nullable', 'string', 'max:190'],
            /** stock only. */
            'items.*.inventory_item_id' => ['sometimes', 'uuid'],
            'items.*.storage_location_id' => ['sometimes', 'uuid'],
            'items.*.lot_id' => ['sometimes', 'nullable', 'uuid', 'prohibits:items.*.lot'],
            'items.*.lot' => ['sometimes', 'array:code,expires_on'],
            'items.*.lot.code' => ['required_with:items.*.lot', 'string', 'max:100'],
            'items.*.lot.expires_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            ...InventoryRules::components('items.*.components', required: false),
            /** What was paid for the whole line, in the farm currency, a string with at most two decimals. */
            'items.*.amount' => ['required', new MoneyAmount],
            /** @ignoreParam */
            'farm_id' => ['missing'],
            /** @ignoreParam */
            'created_by' => ['missing'],
            /** @ignoreParam */
            'total_amount' => ['missing'],
            /** @ignoreParam */
            'status' => ['missing'],
            /** @ignoreParam */
            'currency' => ['missing'],
            /** @ignoreParam */
            'reference' => ['missing'],
        ];
    }
}
