<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/** One row of the append-only money ledger. Corrections are a reversal row plus a linked replacement. */
class FinanceTransaction extends Model
{
    use HasUuidV7;

    public const ENTRY = 'entry';

    public const REVERSAL = 'reversal';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurred_on' => 'immutable_date', 'recorded_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Finance transactions are append-only; reverse and replace instead.'));
        static::deleting(fn () => throw new LogicException('Finance transactions are append-only.'));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'finance_category_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_transaction_id');
    }
}
