<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A complaint about a deal or the other party. Preserved as filed; it never changes the deal. Phase 27 adds triage and outcomes. */
class MarketplaceDealReport extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $report) {
            if ($report->isDirty(['reference', 'deal_id', 'reporter_id', 'reporter_side', 'target', 'reason', 'description', 'deal_status_at_report', 'created_at'])) {
                throw new \LogicException('Filed complaints are immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Reports cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['closed_at' => 'datetime'];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(MarketplaceDeal::class, 'deal_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }
}
