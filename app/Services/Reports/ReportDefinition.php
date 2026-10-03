<?php

namespace App\Services\Reports;

use App\Enums\Feature;
use App\Enums\Permission;

/**
 * What a report is and who may run it. `permissions` are ALL required (they are the permissions of the data the report reads - a
 * report never widens access); `anyOf` additionally requires at least one. `feature` is the plan entitlement, if any.
 * `kind` ties the report to livestock or crop operations so the catalogue can tell whether it is relevant to the farm.
 */
final class ReportDefinition
{
    /**
     * @param  list<Permission>  $permissions
     * @param  list<Permission>  $anyOf
     * @param  list<string>  $filters  of: from, to, as_of, production_cycle_id, kind, status
     */
    public function __construct(
        public readonly string $code,
        public readonly string $title,
        public readonly string $family,
        public readonly string $description,
        public readonly array $permissions,
        public readonly array $filters,
        public readonly ?Feature $feature = null,
        public readonly ?string $kind = null,
        public readonly array $anyOf = [],
    ) {}

    public function usesPeriod(): bool
    {
        return in_array('from', $this->filters, true);
    }
}
