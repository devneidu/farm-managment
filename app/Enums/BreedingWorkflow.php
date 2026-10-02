<?php

namespace App\Enums;

/** The two reproductive modes, each unlocked by a species capability (never by comparing species names). */
enum BreedingWorkflow: string
{
    case Incubation = 'incubation';
    case Pregnancy = 'pregnancy';

    public function capability(): Capability
    {
        return match ($this) {
            self::Incubation => Capability::SupportsIncubation,
            self::Pregnancy => Capability::SupportsPregnancy,
        };
    }

    /** The reference_config key holding this workflow's biological period. */
    public function periodKey(): string
    {
        return match ($this) {
            self::Incubation => 'incubation_days',
            self::Pregnancy => 'gestation_days',
        };
    }
}
