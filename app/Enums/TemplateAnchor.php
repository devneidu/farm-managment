<?php

namespace App\Enums;

/** The date a template item's offset_days counts from. */
enum TemplateAnchor: string
{
    case CycleStart = 'cycle_start';
    case CycleExpectedEnd = 'cycle_expected_end';
    case BreedingStart = 'breeding_start';
    /** Exact expected date, or the start of the expected window. */
    case BreedingExpected = 'breeding_expected';
    /** End of the expected window (falls back to the exact date). */
    case BreedingExpectedTo = 'breeding_expected_to';

    public function target(): string
    {
        return in_array($this, [self::CycleStart, self::CycleExpectedEnd], true) ? 'production_cycle' : 'breeding_project';
    }
}
