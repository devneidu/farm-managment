<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/** A procurement document. Immutable except for the one-way active -> cancelled transition. */
class Purchase extends Model
{
    use HasUuidV7;

    public const ACTIVE = 'active';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'records_expense' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $p) {
            $allowed = ['status', 'cancelled_at', 'cancel_reason', 'cancelled_by', 'cancel_idempotency_key', 'cancel_request_hash', 'updated_at'];
            if (array_diff(array_keys($p->getDirty()), $allowed) !== [] || $p->getOriginal('status') !== self::ACTIVE) {
                throw new LogicException('Purchases are append-only; cancel and replace instead.');
            }
        });
        static::deleting(fn () => throw new LogicException('Purchases are append-only.'));
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class)->orderBy('line_no');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'finance_category_id');
    }

    /** The canonical expense entry (never a reversal) that this purchase booked, if any. */
    public function transaction(): HasOne
    {
        return $this->hasOne(FinanceTransaction::class, 'source_id')->where('source_type', 'purchase')->where('entry_type', 'entry');
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }
}
