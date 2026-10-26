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
            /** Where the offspring go. `mother_batch` adds them to the project's own batch; `new_batch` starts a new batch (same species, started on the outcome date, named like the project title) holding them. Default: new_batch for incubation, mother_batch for pregnancy. new_batch needs live_count of at least 1. */
            'destination' => ['sometimes', 'in:mother_batch,new_batch'],
            /** Only with destination `new_batch`: details of the batch to start. Both parts are optional; send nothing for the defaults. */
            'new_batch' => ['sometimes', 'nullable', 'array'],
            /** Name of the new batch (1–100 characters, unique on the farm). Blank or omitted: "{Species} hatch – {date}" (incubation) or "{Species} offspring – {date}" (pregnancy). */
            'new_batch.title' => ['sometimes', 'nullable', 'string', 'max:100'],
            /** Production purpose code of the new batch, from the species' batch-reference response (same list as batch creation). Blank or omitted: the mother batch's purpose. */
            'new_batch.purpose' => ['sometimes', 'nullable', 'string', 'max:64'],
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
