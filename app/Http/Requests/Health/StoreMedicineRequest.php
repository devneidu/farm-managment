<?php

namespace App\Http\Requests\Health;

use App\Enums\MedicineType;
use App\Services\Health\MedicineCatalogueService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMedicineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Unique on the farm (case/space-insensitive) across ALL inventory items. */
            'name' => ['required', 'string', 'max:150'],
            /** antibiotic | vaccine | supplement | dewormer | other (GET /master/medicine-options). */
            'medicine_type' => ['required', Rule::enum(MedicineType::class)],
            /** Primary unit all stock is measured in: mg, g, kg, ml, l or piece. Fixed once the medicine has stock history. */
            'stock_unit' => ['required', 'string', Rule::in(MedicineCatalogueService::PRIMARY_UNITS)],
            /** Optional packaging: one package holds `quantity_per_package` of the stock unit (e.g. bottle = 100 ml). Creates the per-medicine package conversion. */
            'packaging' => ['sometimes', 'nullable', 'array:unit,quantity_per_package'],
            /** bottle | box | card | sachet | bag | vial */
            'packaging.unit' => ['required_with:packaging', 'string', Rule::in(MedicineCatalogueService::PACKAGE_UNITS)],
            /** Positive decimal in the stock unit (whole number when the stock unit is `piece`). */
            'packaging.quantity_per_package' => ['required_with:packaging', function ($attribute, $value, $fail) {
                if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                    $fail('A scalar decimal quantity is required.');
                }
            }],
            /** Default withdrawal period in days for future health lines (0-3650). */
            'default_withdrawal_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            /** Track lots (batch numbers). Defaults to true when opening stock names a lot or an expiry. Cannot change after the first movement. */
            'tracks_lots' => ['sometimes', 'boolean'],
            /** Track expiry per lot (requires tracks_lots). Defaults to true when the opening-stock lot has `expires_on`. Cannot change after the first movement. */
            'tracks_expiry' => ['sometimes', 'boolean'],
            /** Optional opening balance, written through the inventory ledger as a stock-in with reason opening_balance. No expense, supplier or purchase is created. */
            'opening_stock' => ['sometimes', 'nullable', 'array:storage_location_id,components,lot,recorded_at,idempotency_key,notes'],
            'opening_stock.storage_location_id' => ['required_with:opening_stock', 'uuid'],
            /** Quantity parts in the stock unit or the medicine's packaging unit (e.g. 5 bottle). */
            'opening_stock.components' => ['required_with:opening_stock', 'array', 'min:1', 'max:10'],
            'opening_stock.components.*' => ['required', 'array:quantity,unit'],
            'opening_stock.components.*.quantity' => ['required', function ($attribute, $value, $fail) {
                if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                    $fail('A scalar decimal quantity is required.');
                }
            }],
            'opening_stock.components.*.unit' => ['required', 'string', 'max:32'],
            /** Lot code and expiry date (Y-m-d). Required when the medicine tracks lots / expiry. */
            'opening_stock.lot' => ['sometimes', 'nullable', 'array:code,expires_on'],
            'opening_stock.lot.code' => ['required_with:opening_stock.lot', 'string', 'max:100'],
            'opening_stock.lot.expires_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            /** ISO-8601 with offset, not in the future. */
            'opening_stock.recorded_at' => ['required_with:opening_stock', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/'],
            /** Retrying the same request with this key never double-receives: it returns the medicine already created. */
            'opening_stock.idempotency_key' => ['required_with:opening_stock', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'opening_stock.notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
        ];
    }
}
