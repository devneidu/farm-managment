<?php

namespace App\Support\Idempotency;

/** Canonical hash of a validated payload (object keys sorted, list order preserved) used to compare retries. */
final class RequestHash
{
    public static function of(array $data): string
    {
        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $sort($item);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($sort($data), JSON_THROW_ON_ERROR));
    }
}
