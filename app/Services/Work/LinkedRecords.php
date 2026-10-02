<?php

namespace App\Services\Work;

use App\Enums\EvidenceType;
use App\Services\Records\RecordTypeRegistry;

/**
 * The record a task is meant to be evidenced by (linked_record_type): an operational record type code, `health`, `breeding_check`
 * or `breeding_outcome`. This only describes WHICH actual record belongs to the task; nothing here creates one.
 */
final class LinkedRecords
{
    public const HEALTH = 'health';

    public const BREEDING_CHECK = 'breeding_check';

    public const BREEDING_OUTCOME = 'breeding_outcome';

    /** @return list<string> */
    public static function types(): array
    {
        return [...array_keys((new RecordTypeRegistry)->definitions()), self::HEALTH, self::BREEDING_CHECK, self::BREEDING_OUTCOME];
    }

    public static function evidenceType(string $linked): EvidenceType
    {
        return match ($linked) {
            self::HEALTH => EvidenceType::HealthRecord,
            self::BREEDING_CHECK => EvidenceType::BreedingCheck,
            self::BREEDING_OUTCOME => EvidenceType::BreedingOutcome,
            default => EvidenceType::OperationalRecord,
        };
    }

    /** Where the frontend submits the actual record (the canonical record endpoints; tasks have no write path of their own). */
    public static function endpoint(string $linked, ?string $breedingProjectId): string
    {
        return match ($linked) {
            self::HEALTH => '/api/v1/health-records',
            self::BREEDING_CHECK => '/api/v1/breeding-projects/'.$breedingProjectId.'/checks',
            self::BREEDING_OUTCOME => '/api/v1/breeding-projects/'.$breedingProjectId.'/outcomes',
            default => '/api/v1/records',
        };
    }
}
