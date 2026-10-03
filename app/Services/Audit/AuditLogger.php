<?php

namespace App\Services\Audit;

use App\Models\AuditLog;

/**
 * Writes the privileged / configuration actions that no domain ledger already records. Callers pass safe facts only (never tokens, hashes or
 * secrets). The request id and client address are taken from the current HTTP request when there is one, so an entry can be traced back to it.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $changes
     */
    public function record(string $farmId, ?string $actorId, string $action, string $resourceType, ?string $resourceId, ?string $label = null, ?array $changes = null): AuditLog
    {
        // Only an HTTP request carries a request id (set by AssignRequestId); a console run has neither id nor client address.
        $request = app()->bound('request') && request()->attributes->has('request_id') ? request() : null;

        return AuditLog::create([
            'farm_id' => $farmId, 'actor_id' => $actorId, 'action' => $action, 'resource_type' => $resourceType, 'resource_id' => $resourceId,
            'resource_label' => $label !== null ? mb_substr($label, 0, 150) : null, 'changes' => $changes,
            'request_id' => $request?->attributes->get('request_id'), 'ip_address' => $request?->ip(),
        ]);
    }
}
