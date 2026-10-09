<?php

namespace App\Services\Marketplace;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Human reference codes (DEL-2026-00001). Numbering reads the last code, so creation runs under a named database lock to keep two writers from drawing the same number. */
final class MarketplaceReferences
{
    public static function locked(string $name, \Closure $work): mixed
    {
        DB::select('SELECT GET_LOCK(?, 10)', [$name]);
        try {
            return $work();
        } finally {
            DB::select('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }

    /** @param  class-string<Model>  $model */
    public static function next(string $prefix, string $model): string
    {
        $year = now()->format('Y');
        $last = $model::where('reference', 'like', "$prefix-$year-%")->orderByDesc('reference')->value('reference');

        return "$prefix-$year-".str_pad((string) ($last ? ((int) substr($last, -5)) + 1 : 1), 5, '0', STR_PAD_LEFT);
    }
}
