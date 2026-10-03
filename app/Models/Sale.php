<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/** The commercial/operational event of selling. Immutable except for the one-way active -> cancelled transition. */
class Sale extends Model
{
    use HasUuidV7;

    public const ACTIVE = 'active';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $s) {
            $allowed = ['status', 'cancelled_at', 'cancel_reason', 'cancelled_by', 'cancel_idempotency_key', 'cancel_request_hash', 'updated_at'];
            if (array_diff(array_keys($s->getDirty()), $allowed) !== [] || $s->getOriginal('status') !== self::ACTIVE) {
                throw new LogicException('Sales are append-only; cancel and replace instead.');
            }
        });
        static::deleting(fn () => throw new LogicException('Sales are append-only.'));
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class)->orderBy('line_no');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'finance_category_id');
    }

    /** The live (non-void) invoice of this sale, if one was issued. */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class)->where('status', Invoice::ISSUED);
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }
}
