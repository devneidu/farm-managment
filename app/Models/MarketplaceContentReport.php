<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

class MarketplaceContentReport extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $report) {
            if ($report->isDirty(['reference', 'reporter_id', 'target_type', 'target_id', 'reason', 'description', 'created_at'])) {
                throw new \LogicException('Filed complaints are immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Reports cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['closed_at' => 'datetime'];
    }
}
