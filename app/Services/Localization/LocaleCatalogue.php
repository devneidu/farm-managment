<?php

namespace App\Services\Localization;

use Illuminate\Support\Arr;

/** Locale registry + UI resource bundles (Phase 19). Read-side only; stored data is never touched. */
class LocaleCatalogue
{
    public function default(): string
    {
        return config('localization.default', 'en');
    }

    public function fallback(): string
    {
        return config('localization.fallback', 'en');
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return config('localization.locales', []);
    }

    /** @return list<string> */
    public function enabledCodes(): array
    {
        return array_keys(array_filter($this->all(), fn ($l) => $l['enabled'] ?? false));
    }

    public function isEnabled(?string $code): bool
    {
        return is_string($code) && in_array($code, $this->enabledCodes(), true);
    }

    /** @return list<array<string, mixed>> */
    public function describe(): array
    {
        $out = [];
        foreach ($this->all() as $code => $l) {
            $out[] = [
                'code' => $code,
                'name' => $l['name'],
                'native_name' => $l['native_name'],
                'direction' => $l['direction'],
                'available' => (bool) $l['enabled'],
                'status' => $l['status'],
                'is_default' => $code === $this->default(),
            ];
        }

        return $out;
    }

    /** First enabled language from an Accept-Language header (q-weighted; "en-NG" matches "en"). */
    public function fromAcceptLanguage(?string $header): ?string
    {
        if (! $header) {
            return null;
        }
        $candidates = [];
        foreach (explode(',', $header) as $i => $part) {
            $bits = explode(';', trim($part));
            $tag = strtolower(trim($bits[0]));
            $q = 1.0;
            foreach (array_slice($bits, 1) as $param) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $param, $m)) {
                    $q = (float) $m[1];
                }
            }
            if ($tag !== '' && $tag !== '*' && $q > 0) {
                $candidates[] = [$q, $i, $tag];
            }
        }
        usort($candidates, fn ($a, $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
        foreach ($candidates as [, , $tag]) {
            foreach ([$tag, explode('-', $tag)[0]] as $try) {
                if ($this->isEnabled($try)) {
                    return $try;
                }
            }
        }

        return null;
    }

    /** @return array<string, string> flat dot-keyed messages of one locale only (no fallback) */
    public function messages(string $locale): array
    {
        $file = lang_path($locale.'/ui.php');
        $flat = [];
        if (preg_match('/^[a-z]{2,3}$/', $locale) && is_file($file)) {
            foreach (Arr::dot(require $file) as $key => $value) {
                $flat[$key] = (string) $value;
            }
        }
        ksort($flat);

        return $flat;
    }

    /**
     * Bundle for an enabled locale layered over the fallback, so a missing key never reaches the client blank.
     *
     * @return array{locale: string, fallback_locale: string, direction: string, version: string, complete: bool, fallback_keys: list<string>, messages: array<string, string>}
     */
    public function bundle(string $locale): array
    {
        $fallback = $this->fallback();
        $base = $this->messages($fallback);
        $own = $locale === $fallback ? $base : $this->messages($locale);
        $messages = $base;
        $fallbackKeys = [];
        foreach ($base as $key => $value) {
            if (isset($own[$key]) && $own[$key] !== '') {
                $messages[$key] = $own[$key];
            } elseif ($locale !== $fallback) {
                $fallbackKeys[] = $key;
            }
        }

        return [
            'locale' => $locale,
            'fallback_locale' => $fallback,
            'direction' => $this->all()[$locale]['direction'] ?? 'ltr',
            'version' => substr(sha1(json_encode($messages)), 0, 12),
            'complete' => $fallbackKeys === [],
            'fallback_keys' => $fallbackKeys,
            'messages' => $messages,
        ];
    }

    /** Server-side lookup: locale value, else fallback value, else the key itself (never throws, never blank). */
    public function translate(string $key, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $own = $this->messages($locale)[$key] ?? null;

        return ($own !== null && $own !== '') ? $own : ($this->messages($this->fallback())[$key] ?? $key);
    }
}
