<?php

namespace App\Enums;

/** The kinds of actual record that can evidence a completed task. The task never creates one. */
enum EvidenceType: string
{
    case OperationalRecord = 'operational_record';
    case HealthRecord = 'health_record';
    case BreedingCheck = 'breeding_check';
    case BreedingOutcome = 'breeding_outcome';
}
