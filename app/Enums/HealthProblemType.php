<?php

namespace App\Enums;

/** Plain-language "what is the problem" choice on every health record. Stored as `details.problem_type`; records written before it existed have none. */
enum HealthProblemType: string
{
    case Breathing = 'breathing';
    case Digestive = 'digestive';
    case WormsInternalParasites = 'worms_internal_parasites';
    case SkinExternalParasites = 'skin_external_parasites';
    case Nervous = 'nervous';
    case LegFootMovement = 'leg_foot_movement';
    case Eye = 'eye';
    case InjuryWound = 'injury_wound';
    case ReproductionUdderLaying = 'reproduction_udder_laying';
    case WeakNotEating = 'weak_not_eating';
    case SuddenDeathUnknown = 'sudden_death_unknown';
    case RoutinePrevention = 'routine_prevention';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Breathing => 'Breathing problem (coughing, sneezing, nasal discharge)',
            self::Digestive => 'Stomach or droppings problem (diarrhoea, bloody droppings, bloating)',
            self::WormsInternalParasites => 'Worms or internal parasites',
            self::SkinExternalParasites => 'Skin problem, lice, ticks or mites',
            self::Nervous => 'Nervous signs (shaking, twisted neck, paralysis)',
            self::LegFootMovement => 'Leg, foot or movement problem (limping)',
            self::Eye => 'Eye problem',
            self::InjuryWound => 'Injury or wound',
            self::ReproductionUdderLaying => 'Reproduction, udder or laying problem (mastitis, drop in eggs, abortion)',
            self::WeakNotEating => 'Weak, not eating or losing weight',
            self::SuddenDeathUnknown => 'Sudden death, cause unknown',
            self::RoutinePrevention => 'Routine care or prevention (check-ups, vitamins, general vaccination)',
            self::Other => 'Other',
        };
    }
}
