<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use App\Support\Finance\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** The customer document: its commercial details are snapshotted when issued. Only the one-way issued -> void change is allowed. */
class Invoice extends Model
{
    use HasUuidV7;

    public const ISSUED = 'issued';

    public const VOID = 'void';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['issue_date' => 'immutable_date', 'due_date' => 'immutable_date', 'voided_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $i) {
            $allowed = ['status', 'live_sale_key', 'voided_at', 'void_reason', 'voided_by', 'void_idempotency_key', 'void_request_hash', 'updated_at'];
            if (array_diff(array_keys($i->getDirty()), $allowed) !== [] || $i->getOriginal('status') !== self::ISSUED) {
                throw new LogicException('Invoices are immutable once issued; void and re-issue instead.');
            }
        });
        static::deleting(fn () => throw new LogicException('Invoices are append-only.'));
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('line_no');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('recorded_at')->orderBy('id');
    }

    public function isVoid(): bool
    {
        return $this->status === self::VOID;
    }

    /** Sum of live payments (a reversal row and the payment it offsets both drop out). */
    public function amountPaid(): string
    {
        $payments = $this->relationLoaded('payments') ? $this->payments : $this->payments()->get();
        $reversed = $payments->where('entry_type', Payment::REVERSAL)->pluck('reverses_payment_id')->all();

        return Money::sum($payments->where('entry_type', Payment::PAYMENT)->reject(fn ($p) => in_array($p->id, $reversed, true))->map(fn ($p) => (string) $p->amount));
    }

    public function outstanding(): string
    {
        return Money::sub((string) $this->total_amount, $this->amountPaid());
    }

    /** @return 'unpaid'|'partially_paid'|'paid' */
    public function paymentStatus(): string
    {
        $paid = $this->amountPaid();

        return Money::isZero($paid) ? 'unpaid' : (Money::cmp($paid, (string) $this->total_amount) >= 0 ? 'paid' : 'partially_paid');
    }
}
