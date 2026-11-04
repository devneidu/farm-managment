<?php

namespace App\Support\References;

use Illuminate\Database\Eloquent\Model;

/**
 * The one generator of farm-scoped human references such as FIN-2026-00007.
 *
 * The next number is one more than the highest number already used by that farm, prefix and year - never a row count, which
 * repeats a number as soon as the numbers have a gap (a restored or imported row, a row from another year, an earlier failed write).
 * Call it inside the transaction that inserts the row, after the farm row has been locked (every writer already does this), so two
 * writers cannot read the same maximum. Existing references are never touched.
 */
class ReferenceNumber
{
    /**
     * @param  class-string<Model>  $model
     * @param  string|null  $like  SQL LIKE for the rows that share one number sequence; defaults to "{prefix}-{year}-%"
     */
    public static function next(string $model, string $farmId, string $prefix, ?string $like = null): string
    {
        $year = now()->format('Y');
        $highest = (int) $model::query()->where('farm_id', $farmId)->where('reference', 'like', $like ?? "$prefix-$year-%")
            ->selectRaw("MAX(CAST(SUBSTRING_INDEX(reference, '-', -1) AS UNSIGNED)) as highest")->value('highest');

        return "$prefix-$year-".str_pad((string) ($highest + 1), 5, '0', STR_PAD_LEFT);
    }
}
