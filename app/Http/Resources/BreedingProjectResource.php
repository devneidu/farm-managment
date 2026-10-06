<?php

namespace App\Http\Resources;

use App\Models\BreedingOutcome;
use App\Support\Measurement\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BreedingProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $snapshot = $this->reference_snapshot;
        $effective = $this->outcomes->first(fn ($o) => $o->kind === BreedingOutcome::OUTCOME && $o->reversal === null);
        $window = $this->expected_from !== null;
        $none = $this->expected_date === null && ! $window;

        return [
            'id' => $this->id, 'reference' => $this->reference, 'production_cycle_id' => $this->production_cycle_id,
            'workflow' => $this->workflow->value, 'status' => $this->status->value,
            'start_date' => $this->start_date->toDateString(),
            /** Incubation only; null otherwise. Eggs set are not live population. */
            'eggs_set' => $this->eggs_set,
            /** Pregnancy only; null otherwise. */
            'females_bred' => $this->females_bred,
            /** An estimate. Never changes population. */
            'expected_offspring' => $this->expected_offspring,
            /**
             * Operational expectation: an exact date OR a window, never both. source: reference (derived from the snapshot), manual, or none.
             * When type is none, no_expectation_reason says why (no_numeric_reference, qualified_reference: reference_config.automatic_expectation=false) or null when simply not provided.
             *
             * @var array{type: 'exact'|'window'|'none', source: string, date: string|null, from: string|null, to: string|null, no_expectation_reason: string|null}
             */
            'expectation' => [
                'type' => $none ? 'none' : ($window ? 'window' : 'exact'), 'source' => $this->expectation_source,
                'date' => $this->expected_date?->toDateString(), 'from' => $this->expected_from?->toDateString(), 'to' => $this->expected_to?->toDateString(),
                'no_expectation_reason' => $none ? $snapshot['no_automatic_reason'] : null,
            ],
            /**
             * Biological reference frozen when the project started (later master-data edits never change it).
             *
             * @var array{workflow: string, capability: string, species_id: string, species_code: string, kind: 'exact'|'range'|'none', days: int|null, days_min: int|null, days_max: int|null, approximate: bool, note: string|null, automatic_expectation: bool, no_automatic_reason: string|null, captured_at: string}
             */
            'biological_reference' => $snapshot,
            /**
             * Eggs this project took from available stock (incubation workflow with consume_egg_stock), derived from its stock movements.
             * null when it never touched stock. returned counts only eggs the farmer explicitly sent back; net_out is what is still counted as in incubation.
             *
             * @var array{consumed: int, returned: int, net_out: int, movements: array<int, array{id: string, type: string, reason: string, quantity: string, recorded_at: string}>}|null
             */
            'egg_stock' => $this->stockMovements->isEmpty() ? null : (function () {
                $consumed = $this->stockMovements->where('reason', 'incubation')->sum(fn ($m) => -(float) $m->quantity_delta);
                $returned = $this->stockMovements->where('reason', 'returned')->sum(fn ($m) => (float) $m->quantity_delta);

                return ['consumed' => (int) $consumed, 'returned' => (int) $returned, 'net_out' => (int) ($consumed - $returned),
                    'movements' => $this->stockMovements->map(fn ($m) => ['id' => $m->id, 'type' => $m->type->value, 'reason' => $m->reason, 'quantity' => ltrim(Decimal::trim((string) $m->quantity_delta), '-'), 'recorded_at' => $m->recorded_at->toISOString()])->all()];
            })(),
            'parents' => $this->parents->map(fn ($p) => ['id' => $p->id, 'role' => $p->role, 'production_cycle_id' => $p->parent_cycle_id, 'head_count' => $p->head_count])->all(),
            'checks' => $this->checks->map(fn ($c) => ['id' => $c->id, 'checked_on' => $c->checked_on->toDateString(), 'result' => $c->result, 'fertile_count' => $c->fertile_count, 'notes' => $c->notes])->all(),
            /** Outcome rows including reversals, oldest first. */
            'outcomes' => BreedingOutcomeResource::collection($this->outcomes)->resolve($request),
            /**
             * The current actual outcome (not reversed), with expected-vs-actual variance; null while none.
             *
             * @var array{outcome_id: string, outcome_date: string, live_count: int, loss_count: int|null, expected_offspring: int|null, variance: int|null}|null
             */
            'result' => $effective ? [
                'outcome_id' => $effective->id, 'outcome_date' => $effective->outcome_date->toDateString(), 'live_count' => $effective->live_count, 'loss_count' => $effective->loss_count,
                'expected_offspring' => $this->expected_offspring,
                'variance' => $this->expected_offspring === null ? null : $effective->live_count - $this->expected_offspring,
            ] : null,
            'notes' => $this->notes, 'cancelled_at' => $this->cancelled_at?->toISOString(), 'cancel_reason' => $this->cancel_reason,
            'created_by' => $this->created_by, 'created_at' => $this->created_at->toISOString(), 'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
