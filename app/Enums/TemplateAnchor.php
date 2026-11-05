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

    /** The date chosen when the routine is applied; day 1 is offset 0. Fits every kind of routine, including general (no batch) ones. */
    case StartDate = 'start_date';

    /** The kind of template this anchor belongs to; null = usable in any. */
    public function target(): ?string
    {
        if ($this === self::StartDate) {
            return null;
        }

        return in_array($this, [self::CycleStart, self::CycleExpectedEnd], true) ? 'production_cycle' : 'breeding_project';
    }
}
