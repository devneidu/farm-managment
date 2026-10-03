<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\Inventory\InventoryRules;
use App\Rules\MoneyAmount;
use App\Rules\PositiveWholeCount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** The customer (an active contact with the customer role, Phase 14). Omit for a walk-in sale. */
            'contact_id' => ['sometimes', 'nullable', 'uuid'],
            /** Free-text buyer name for a walk-in sale; not allowed together with contact_id. */
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            /** Income category later payments are booked to; defaults from the lines (livestock -> livestock_sales, produce -> crop_sales, otherwise other_income). */
            'finance_category_id' => ['sometimes', 'nullable', 'uuid'],
            /** Replace a cancelled sale once. Needs sale.cancel. */
            'corrects_sale_id' => ['sometimes', 'nullable', 'uuid'],
            ...InventoryRules::event(),
            /** "Save & Create Invoice": issue the invoice for this sale in the same transaction. A convenience only - the invoice is still its own document. Needs invoice.create. */
            'invoice' => ['sometimes', 'array:issue_date,due_date,notes'],
            'invoice.issue_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'invoice.due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'invoice.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            /** 1-30 lines. Total = exact sum of line amounts. */
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*' => ['required', 'array:kind,description,inventory_item_id,storage_location_id,lot_id,components,production_cycle_id,head_count,amount'],
            /** stock = produce leaving inventory through a Phase 9 stock-out; livestock = animals leaving a livestock cycle through the population ledger; other = no physical effect. */
            'items.*.kind' => ['required', Rule::in(['stock', 'livestock', 'other'])],
            /** Required for other lines; stock and livestock lines are named from the item/cycle (an optional label is kept for livestock). */
            'items.*.description' => ['sometimes', 'nullable', 'string', 'max:190'],
            /** stock only. */
            'items.*.inventory_item_id' => ['sometimes', 'uuid'],
            'items.*.storage_location_id' => ['sometimes', 'uuid'],
            'items.*.lot_id' => ['sometimes', 'nullable', 'uuid'],
            ...InventoryRules::components('items.*.components', required: false),
            /** Required for livestock lines (the cycle the animals leave); optional revenue allocation for the others. */
            'items.*.production_cycle_id' => ['sometimes', 'nullable', 'uuid'],
            /** livestock only: whole animals sold. */
            'items.*.head_count' => ['sometimes', new PositiveWholeCount],
            /** What the buyer pays for the whole line, in the farm currency, a string with at most two decimals. */
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
