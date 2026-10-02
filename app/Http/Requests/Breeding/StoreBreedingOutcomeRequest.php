<?php

namespace App\Http\Requests\Breeding;

use App\Http\Requests\Inventory\InventoryRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreBreedingOutcomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Actual live births / hatched chicks. 0 records an unsuccessful attempt and adds nothing; a positive count joins the project's cycle population through the Phase 8 ledger automatically. */
            'live_count' => ['required', 'integer', 'min:0', 'max:999999999'],
            /** Stillborn / dead-in-shell / infertile; informational. For incubation live + loss cannot exceed eggs_set. */
            'loss_count' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999'],
            /** Reversed outcome this one replaces (needs breeding.reverse). */
            'corrects_outcome_id' => ['sometimes', 'nullable', 'uuid'],
            'recorded_at' => InventoryRules::event()['recorded_at'],
            'idempotency_key' => InventoryRules::event()['idempotency_key'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
            /** @ignoreParam */
            'population_delta' => ['missing'],
            /** @ignoreParam */
            'production_cycle_id' => ['missing'],
        ];
    }
}
