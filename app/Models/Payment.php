<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/** Money actually received. Append-only; a reversal row offsets the original in every balance. */
class Payment extends Model
{
    use HasUuidV7;

    public const PAYMENT = 'payment';

    public const REVERSAL = 'reversal';

    public const METHODS = ['cash', 'bank_transfer', 'pos', 'mobile_money', 'cheque', 'other'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['received_on' => 'immutable_date', 'recorded_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Payments are append-only; reverse and re-record instead.'));
        static::deleting(fn () => throw new LogicException('Payments are append-only.'));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_payment_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FinanceTransaction::class, 'finance_transaction_id');
    }
}
