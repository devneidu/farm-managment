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

    public function deal(): BelongsTo
    {
        return $this->belongsTo(MarketplaceDeal::class, 'deal_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }
}
