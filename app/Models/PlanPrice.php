<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanPrice extends Model
{
    use HasUuidV7;

    protected $fillable = ['plan_id', 'interval', 'currency', 'amount_minor', 'is_active'];

    protected function casts(): array
    {
        return [
            'interval' => BillingInterval::class,
            'amount_minor' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
