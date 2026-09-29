<?php

namespace App\Enums;

/**
 * Registry of boolean plan entitlements (stable machine keys). Code asks
 * `EntitlementService::allows($farm, Feature::X)`; it never inspects plan names.
 * To add one: add a case + label here, then grant it to plans in the catalogue (DB).
 * No schema change is needed.
 */
enum Feature: string
{
    case AdvancedReports = 'advanced_reports';
    case DataExport = 'data_export';

    public function label(): string
    {
        return match ($this) {
            self::AdvancedReports => 'Advanced reports',
            self::DataExport => 'Data export',
        };
    }
}
