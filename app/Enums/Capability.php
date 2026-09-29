<?php

namespace App\Enums;

/**
 * Registry of species capability codes (02-DOMAIN-BEHAVIOUR-MATRIX). Later phases ask "does this species have
 * capability X?" instead of comparing species names. `configRules()` is the ONLY allowed shape of a capability's
 * `reference_config`: biological REFERENCE defaults (not farm targets, not actual outcomes). Capabilities not listed
 * there accept no config at all.
 */
enum Capability: string
{
    case SupportsGroupTracking = 'supports_group_tracking';
    case SupportsIndividualTracking = 'supports_individual_tracking';
    case SupportsIncubation = 'supports_incubation';
    case SupportsPregnancy = 'supports_pregnancy';
    case ProducesEggs = 'produces_eggs';
    case ProducesMilk = 'produces_milk';
    case SupportsLiveWeight = 'supports_live_weight';
    case SupportsBreeding = 'supports_breeding';
    case SupportsHarvest = 'supports_harvest';
    case SupportsMortality = 'supports_mortality';
    case SupportsFeedRecords = 'supports_feed_records';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }

    /** @return array<string, list<string>> validation rules per allowed reference_config key */
    public function configRules(): array
    {
        return match ($this) {
            self::SupportsIncubation => ['incubation_days' => ['integer', 'min:1', 'max:365']],
            self::SupportsPregnancy => ['gestation_days' => ['integer', 'min:1', 'max:1000']],
            default => [],
        };
    }
}
