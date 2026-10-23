<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\Inventory\InventoryRules;
use App\Models\Payment;
use App\Rules\MoneyAmount;
use App\Rules\PositiveWholeCount;
use Illuminate\Foundation\Http\FormRequest;
use App\Support\Measurement\Decimal;
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
            /** Buyer in one step: reuses an active contact with the same name (adding the customer role) or creates a customer contact. Needs no contact.manage. Not allowed together with contact_id or customer_name (422). */
            'buyer' => ['sometimes', 'nullable', 'array:name,phone'],
            'buyer.name' => ['required_with:buyer', 'string', 'max:150'],
            'buyer.phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            /** Paid now: a payment received at the time of the sale. The sale, its invoice (issued automatically when no invoice block is sent) and the payment are created in ONE transaction. Omit it for a sale that is not paid yet. Needs no payment.create. */
            'payment' => ['sometimes', 'nullable', 'array:amount,method,received_on'],
            'payment.amount' => ['required_with:payment', new MoneyAmount],
            'payment.method' => ['required_with:payment', Rule::in(Payment::METHODS)],
            /** Defaults to the sale's farm-local date. */
            'payment.received_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            /** "Save & Create Invoice": issue the invoice for this sale in the same transaction. A convenience only - the invoice is still its own document. Needs invoice.create. */
            'invoice' => ['sometimes', 'array:issue_date,due_date,notes'],
            'invoice.issue_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'invoice.due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'invoice.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            /** 1-30 lines. Total = exact sum of line amounts. */
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*' => ['required', 'array:kind,description,inventory_item_id,output,storage_location_id,lot_id,components,production_cycle_id,head_count,quantity,unit_price,amount'],
            /** stock = produce leaving inventory through a Phase 9 stock-out; livestock = animals leaving a livestock cycle through the population ledger; other = no physical effect. */
            'items.*.kind' => ['required', Rule::in(['stock', 'livestock', 'other'])],
            /** Required for other lines; stock and livestock lines are named from the item/cycle (an optional label is kept for livestock). */
            'items.*.description' => ['sometimes', 'nullable', 'string', 'max:190'],
            /** stock only. The item sold/bought, by id. Exactly one of inventory_item_id or output (422 otherwise). */
            'items.*.inventory_item_id' => ['sometimes', 'uuid'],
            /** stock only. The farm's automatic egg or milk stock, instead of inventory_item_id. Sale takes the existing item only - a farm that never stocked it gets 409 insufficient_stock, nothing is created. With no storage_location_id the only active store is used (several = 422). */
            'items.*.output' => ['sometimes', Rule::in(['eggs', 'milk']), 'prohibits:items.*.inventory_item_id'],
            /** Required with inventory_item_id; optional with output. */
            'items.*.storage_location_id' => ['sometimes', 'uuid'],
            'items.*.lot_id' => ['sometimes', 'nullable', 'uuid'],
            ...InventoryRules::components('items.*.components', required: false),
            /** Required for livestock lines (the cycle the animals leave); optional revenue allocation for the others. */
            'items.*.production_cycle_id' => ['sometimes', 'nullable', 'uuid'],
            /** livestock only: whole animals sold. */
            'items.*.head_count' => ['sometimes', new PositiveWholeCount],
            /** What the buyer pays for the whole line, in the farm currency, a string with at most two decimals. Required unless unit_price is sent; if both are sent they must agree (422). */
            'items.*.amount' => ['sometimes', new MoneyAmount],
            /** Price per the item's own unit (per head for livestock), a string with at most two decimals. The line amount is quantity x unit_price rounded to 2 decimals. */
            'items.*.unit_price' => ['sometimes', new MoneyAmount],
            /** How many units are sold: in the item's stock unit for a stock line (instead of components), head count for livestock (same as head_count), required with unit_price on an other line. */
            'items.*.quantity' => ['sometimes', function (string $attribute, mixed $value, \Closure $fail) {
                $parsed = Decimal::parse($value);
                if ($parsed === null || Decimal::isNegative($parsed) || Decimal::isZero($parsed)) {
                    $fail('The '.$attribute.' must be a number greater than zero.');
                }
            }],
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
