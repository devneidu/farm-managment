<?php

namespace App\Services\Platform;

use App\Models\User;
use App\Services\Audit\AuditLogger;

/**
 * Writes platform-admin audit entries through the Phase 17 audit_logs table (farm_id NULL). Callers pass the SAFE before/after facts of
 * what changed (names, flags, limits, counts - never secrets); only fields whose value actually changed are stored, and anything whose key
 * looks like a credential is dropped as defence in depth. Called inside the same transaction as the change, so a change never exists unaudited.
 */
class PlatformAudit
{
    private const FORBIDDEN_KEY = '/pass|token|secret|otp|hash|authorization|api_?key/i';

    public function __construct(private AuditLogger $logger) {}

    /**
     * @param  array<string, mixed>  $before  empty for a creation
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $context  extra safe facts (for example a reason)
     */
    public function record(User $actor, string $action, string $resourceType, ?string $resourceId, ?string $label, array $before = [], array $after = [], array $context = []): void
    {
        $before = $this->clean($before);
        $after = $this->clean($after);
        $changes = $context === [] ? [] : $this->clean($context);

        if ($before === []) {
            $changes += $after === [] ? [] : ['after' => $after];
        } else {
            $changed = array_keys(array_filter($after, fn ($value, $key) => ! array_key_exists($key, $before) || $before[$key] !== $value, ARRAY_FILTER_USE_BOTH));
            $changes += ['before' => array_intersect_key($before, array_flip($changed)), 'after' => array_intersect_key($after, array_flip($changed))];
        }

        $this->logger->platform($actor, $action, $resourceType, $resourceId, $label, $changes === [] ? null : $changes);
    }

    /** @param  array<string, mixed>  $data */
    private function clean(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::FORBIDDEN_KEY, $key)) {
                continue;
            }
            $out[$key] = is_array($value) ? $this->clean($value) : $value;
        }

        return $out;
    }
}
