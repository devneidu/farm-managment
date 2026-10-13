<?php

namespace App\Enums;

/** What kind of product a medicine is. Stored on the medicine profile; NULL there means "not specified" (medicines created before this existed). */
enum MedicineType: string
{
    case Antibiotic = 'antibiotic';
    case Vaccine = 'vaccine';
    case Supplement = 'supplement';
    case Dewormer = 'dewormer';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Antibiotic => 'Antibiotic',
            self::Vaccine => 'Vaccine',
            self::Supplement => 'Supplement',
            self::Dewormer => 'Dewormer',
            self::Other => 'Other',
        };
    }
}
