<?php

namespace App\Services\Breeding;

use App\Enums\BreedingWorkflow;
use App\Enums\Capability;
use App\Models\ProductionCycle;
use App\Models\SpeciesCapability;
use Carbon\CarbonImmutable;

/**
 * Reads the biological reference of a species capability and turns it into an expectation. Three rules are locked:
 * an exact reference gives an exact date; a ranged reference gives an expected WINDOW (start + min .. start + max, never a
 * midpoint); a missing or qualified reference gives NO automatic expectation (nothing is invented). A reference whose config sets
 * `automatic_expectation` to false (e.g. honeybee, development depends on caste) is preserved but not applied. `note` is only
 * human-readable text and never affects calculation.
 */
class BreedingReference
{
    public const NONE_NO_NUMBER = 'no_numeric_reference';

    public const NONE_QUALIFIED = 'qualified_reference';

    /** The enabled capability row of the cycle's species for this workflow, or null when the species cannot do it. */
    public function capabilityRow(ProductionCycle $cycle, BreedingWorkflow $workflow): ?SpeciesCapability
    {
        $species = $cycle->livestock?->species;
        if (! $species) {
            return null;
        }
        $enabled = fn (Capability $c) => $species->speciesCapabilities()->where('enabled', true)->whereHas('capability', fn ($q) => $q->where('code', $c->value));
        if (! $enabled(Capability::SupportsBreeding)->exists()) {
            return null;
        }

        return $enabled($workflow->capability())->first();
    }

    /** The immutable, self-contained reference stored on the project at creation. */
    public function snapshot(ProductionCycle $cycle, BreedingWorkflow $workflow, SpeciesCapability $row, CarbonImmutable $capturedAt): array
    {
        $config = $row->reference_config ?? [];
        $key = $workflow->periodKey();
        $days = $config[$key] ?? null;
        $min = $config[$key.'_min'] ?? null;
        $max = $config[$key.'_max'] ?? null;
        $note = $config['note'] ?? null;
        $automatic = (bool) ($config['automatic_expectation'] ?? true);
        $kind = $days !== null ? 'exact' : ($min !== null && $max !== null ? 'range' : 'none');
        $reason = match (true) {
            $kind === 'none' => self::NONE_NO_NUMBER,
            ! $automatic => self::NONE_QUALIFIED,
            default => null,
        };

        return [
            'workflow' => $workflow->value, 'capability' => $workflow->capability()->value, 'species_id' => $cycle->livestock->species_id, 'species_code' => $cycle->livestock->species->code,
            'kind' => $kind, 'days' => $days, 'days_min' => $min, 'days_max' => $max, 'approximate' => (bool) ($config['approximate'] ?? false), 'note' => $note,
            'automatic_expectation' => $reason === null, 'no_automatic_reason' => $reason, 'captured_at' => $capturedAt->toISOString(),
        ];
    }

    /** @return array{source: string, date: ?string, from: ?string, to: ?string} */
    public function expectation(array $snapshot, string $start): array
    {
        $day = CarbonImmutable::parse($start);
        if (! $snapshot['automatic_expectation']) {
            return ['source' => 'none', 'date' => null, 'from' => null, 'to' => null];
        }
        if ($snapshot['kind'] === 'exact') {
            return ['source' => 'reference', 'date' => $day->addDays($snapshot['days'])->toDateString(), 'from' => null, 'to' => null];
        }

        return ['source' => 'reference', 'date' => null, 'from' => $day->addDays($snapshot['days_min'])->toDateString(), 'to' => $day->addDays($snapshot['days_max'])->toDateString()];
    }
}
