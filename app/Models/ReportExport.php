<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A queued request to render one report to a private file. Holds the request context (farm, requesting user, the exact resolved filters)
 * and the lifecycle; it never holds report data or totals.
 */
class ReportExport extends Model
{
    use HasUuidV7;

    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'filters' => 'array', 'row_count' => 'integer', 'file_size' => 'integer', 'download_count' => 'integer',
            'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'failed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime', 'first_downloaded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $e) {
            if ($e->isDirty(['farm_id', 'requested_by', 'report', 'format', 'filters', 'idempotency_key', 'request_hash'])) {
                throw new LogicException('An export request (farm, requester, report, format and filters) is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Exports are kept as history; only their file expires.'));
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::COMPLETED && $this->expires_at !== null && $this->expires_at->isFuture();
    }
}
