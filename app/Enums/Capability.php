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
            self::SupportsIncubation => self::periodRules('incubation_days', 365),
            self::SupportsPregnancy => self::periodRules('gestation_days', 1000),
            default => [],
        };
    }

    /**
     * A biological reference period: an optional single default (`<key>`), an optional inclusive range
     * (`<key>_min` + `<key>_max`, only together), `approximate` (the source gives "about N") a short human-readable `note`
     * (never behaviour) and `automatic_expectation` (false = the period is stored but not applied as an expected date, e.g. caste-dependent). A range is never collapsed to a midpoint; whether a default exists is explicit.
     *
     * @return array<string, list<string>>
     */
    private static function periodRules(string $key, int $max): array
    {
        return [
            $key => ['integer', 'min:1', 'max:'.$max],
            $key.'_min' => ['integer', 'min:1', 'max:'.$max, 'required_with:'.$key.'_max'],
            $key.'_max' => ['integer', 'min:1', 'max:'.$max, 'required_with:'.$key.'_min', 'gte:'.$key.'_min'],
            'approximate' => ['boolean'],
            'automatic_expectation' => ['boolean'],
            'note' => ['string', 'max:255'],
        ];
    }
}
